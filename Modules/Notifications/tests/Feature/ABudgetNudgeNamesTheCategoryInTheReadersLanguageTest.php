<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\App;
use Modules\Budgets\Internal\Jobs\EmitBudgetNudgesJob;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// The nudge job is an hourly Schedule::call with no request behind it, so the
// translator sits at config('app.locale'). Every name it resolves on the way
// into a notification is resolved in nobody's language.

function bnrUser(string $username, string $locale): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'locale' => $locale,
    ]);

    app(DatabaseManager::class)->connection()->table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);

    return $user;
}

function bnrOverspend(User $user, Category $category): void
{
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'BNR ASN',
        'slug' => 'bnr-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BNR'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/bnr-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'bnr-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    app(EnvelopeWriter::class)->setAssigned($user, $category->id, CarbonImmutable::now()->startOfMonth(), 10000);

    static $row = 810000;
    $row++;

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -9500,
        'currency' => 'EUR',
        'settled_amount_minor' => -9500,
        'settled_currency' => 'EUR',
        'counterparty_name' => "BnrM{$row}",
        'counterparty_normalized' => "bnrm{$row}",
        'normalization_version' => 1,
        'category_id' => $category->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('bnr'.$row, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// The worker's locale, which is what config('app.locale') gives a scheduled
// job. Nothing in the chain sets it to the recipient's.
function bnrRunJobAsWorker(User $user): void
{
    App::setLocale('en');

    app(SuppressionEvaluator::class)->suppressDelivery(function () use ($user): void {
        (new EmitBudgetNudgesJob($user->id))->handle(
            app(CarryoverQuery::class),
            app(PeriodQuery::class),
            app(Dispatcher::class),
            app(AuthFactory::class),
        );
    });
}

function bnrStoredBody(int $userId): string
{
    $body = app(DatabaseManager::class)->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE)
        ->value('body');

    return is_string($body) ? $body : '';
}

function bnrReadBodyIn(User $user, string $locale): string
{
    App::setLocale($locale);

    $rows = app(NotificationQuery::class)->allForUser($user);

    return $rows === [] ? '' : $rows[0]->body;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names an untouched default category in the recipient’s language', function (): void {
    $user = bnrUser('bnr-dutch-reader', 'nl');

    bnrOverspend($user, Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
        'name_is_default' => true,
    ]));

    bnrRunJobAsWorker($user);

    expect(bnrStoredBody($user->id))
        ->toContain('Boodschappen')
        ->not->toContain('Groceries');
});

// Read time, not write time: the reader who switches language afterwards gets
// the same sentence in the language they are reading it in.
it('follows the reader when they change language later', function (): void {
    $user = bnrUser('bnr-switcher', 'nl');

    bnrOverspend($user, Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
        'name_is_default' => true,
    ]));

    bnrRunJobAsWorker($user);

    expect(bnrReadBodyIn($user, 'nl'))->toContain('Boodschappen');
    expect(bnrReadBodyIn($user, 'en'))->toContain('Groceries');
});

// A category the reader named themselves is their own words, and stays them.
it('leaves a renamed category in the words the reader gave it', function (): void {
    $user = bnrUser('bnr-renamer', 'nl');

    bnrOverspend($user, Category::create([
        'user_id' => $user->id,
        'name' => 'Weekboodschappen',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
        'name_is_default' => false,
    ]));

    bnrRunJobAsWorker($user);

    expect(bnrReadBodyIn($user, 'nl'))->toContain('Weekboodschappen');
    expect(bnrReadBodyIn($user, 'en'))->toContain('Weekboodschappen');
});
