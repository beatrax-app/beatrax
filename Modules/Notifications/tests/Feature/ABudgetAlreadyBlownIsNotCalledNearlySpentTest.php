<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Internal\Jobs\EmitBudgetNudgesJob;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// EmitBudgetNudgesJob fires on `spent >= threshold%` with no upper bound, and
// the occurrence is the PERIOD — so the one nudge a period carries is whatever
// the first hourly run after the crossing saw. A single large charge takes an
// envelope from nothing to well past its budget between two of those runs.

function babUser(): User
{
    $user = User::query()->create([
        'username' => 'bab-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    app(DatabaseManager::class)->connection()->table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);

    return $user;
}

function babCategory(): Category
{
    return Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'bab-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function babSpend(User $user, Category $category, int $spentMinor): void
{
    static $i = 810000;
    $i++;

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'BAB ASN',
        'slug' => 'bab-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BAB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/bab-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'bab-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -$spentMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$spentMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "BabM{$i}",
        'counterparty_normalized' => "babm{$i}",
        'normalization_version' => 1,
        'category_id' => $category->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => $i,
        'fingerprint' => str_pad('bab'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function babRunJob(User $user): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user): void {
        (new EmitBudgetNudgesJob($user->id))->handle(
            app(CarryoverQuery::class),
            app(PeriodQuery::class),
            app(Dispatcher::class),
            app(AuthFactory::class),
        );
    });
}

/**
 * @return array{title: string, body: string}
 */
function babNudge(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::BudgetNudge->value)
        ->first();

    expect($row)->not->toBeNull();

    return ['title' => (string) $row->title, 'body' => (string) $row->body];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('does not call an envelope spent two and a half times over "nearly spent"', function (): void {
    $user = babUser();
    $groceries = babCategory();

    app(EnvelopeWriter::class)->setAssigned($user, $groceries->id, CarbonImmutable::now()->startOfMonth(), 10000);
    babSpend($user, $groceries, 25000);

    babRunJob($user);

    $nudge = babNudge($user);

    expect($nudge['body'])->toContain('250.00')
        ->and($nudge['body'])->toContain('100.00')
        ->and($nudge['title'])->not->toContain('nearly');
});

it('still says nearly spent for an envelope that is only nearly spent', function (): void {
    $user = babUser();
    $groceries = babCategory();

    app(EnvelopeWriter::class)->setAssigned($user, $groceries->id, CarbonImmutable::now()->startOfMonth(), 10000);
    babSpend($user, $groceries, 9500);

    babRunJob($user);

    expect(babNudge($user)['title'])->toBe('Budget nearly spent');
});
