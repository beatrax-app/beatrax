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
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

/*
 * Req 6 — the over-budget nudge trigger, redirected (D-20 AMENDMENT — see
 * 18-07's <planner_decisions>) to read the LIVE envelope model via
 * CarryoverQuery::forUserAndPeriod() rather than the write-dead
 * BudgetProgressQuery/category_budgets path. Seeds via real envelope
 * assignments + settled transactions (never category_budgets) so
 * CarryoverQuery computes genuine spentMinor/availableMinor figures.
 */

function bntUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

/** Activates the envelope genesis anchor at the start of the current period. */
function bntActivate(User $user): void
{
    app(DatabaseManager::class)->connection()->table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);
}

function bntCategory(string $name): Category
{
    return Category::create([
        'user_id' => null,
        'name' => $name,
        'slug' => 'bnt-'.strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function bntAccount(User $user): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'BNT ASN',
        'slug' => 'bnt-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'asn',
        'iban' => 'NL00BNT'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function bntImportRun(User $user): ImportRun
{
    return ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/bnt-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'bnt-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

/** Persists a settled expense transaction, categorized, dated within the given period. */
function bntSpend(User $user, Account $account, ImportRun $run, Category $category, int $spentMinor, CarbonImmutable $postedAt): void
{
    static $i = 700000;
    $i++;

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $postedAt->toDateString(),
        'booked_at' => $postedAt->toDateString().' 12:00:00',
        'value_date' => $postedAt->toDateString(),
        'amount_minor' => -$spentMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$spentMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "BntM{$i}",
        'counterparty_normalized' => "bntm{$i}",
        'normalization_version' => 1,
        'category_id' => $category->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => $i,
        'fingerprint' => str_pad('bnt'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function bntAssign(User $user, Category $category, int $minor): void
{
    app(EnvelopeWriter::class)->setAssigned($user, $category->id, CarbonImmutable::now()->startOfMonth(), $minor);
}

function bntSetThreshold(User $user, Category $category, int $percent): void
{
    app(EnvelopeWriter::class)->setNotifyThreshold($user, $category->id, $percent);
}

/** Runs the nudge job for $user with delivery globally suppressed (D-43) — no test ever attempts a real OS notification. */
function bntRunJob(User $user): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user): void {
        $job = new EmitBudgetNudgesJob($user->id);
        $job->handle(
            app(CarryoverQuery::class),
            app(Clock::class),
            app(Dispatcher::class),
            app(AuthFactory::class),
        );
    });
}

function bntNudgeCount(int $userId, ?string $bodyContains = null): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $query = $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE);

    if ($bodyContains !== null) {
        $query->where('body', 'like', '%'.$bodyContains.'%');
    }

    return $query->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('nudges exactly once for an envelope past its threshold and not at all for one under it (Req 6)', function (): void {
    $user = bntUser('bnt-over-under');
    bntActivate($user);
    $account = bntAccount($user);
    $run = bntImportRun($user);

    $groceries = bntCategory('Groceries');
    bntAssign($user, $groceries, 10000);
    bntSpend($user, $account, $run, $groceries, 9500, CarbonImmutable::now());

    $fuel = bntCategory('Fuel');
    bntAssign($user, $fuel, 10000);
    bntSpend($user, $account, $run, $fuel, 5000, CarbonImmutable::now());

    bntRunJob($user);

    expect(bntNudgeCount($user->id))->toBe(1);
    expect(bntNudgeCount($user->id, 'Groceries'))->toBe(1);
    expect(bntNudgeCount($user->id, 'Fuel'))->toBe(0);
});

it('does not re-fire when the job runs again in the SAME period after further spend (Req 6, D-06)', function (): void {
    $user = bntUser('bnt-same-period');
    bntActivate($user);
    $account = bntAccount($user);
    $run = bntImportRun($user);

    $groceries = bntCategory('Groceries');
    bntAssign($user, $groceries, 10000);
    bntSpend($user, $account, $run, $groceries, 9500, CarbonImmutable::now());

    bntRunJob($user);
    expect(bntNudgeCount($user->id))->toBe(1);

    // Further spend, still within the same period — the envelope is now
    // even further over, but the occurrence key (the period) is unchanged.
    bntSpend($user, $account, $run, $groceries, 200, CarbonImmutable::now());
    bntRunJob($user);

    expect(bntNudgeCount($user->id))->toBe(1);
});

it('re-fires in the NEXT period when the budget is still over (Req 6, D-06)', function (): void {
    $user = bntUser('bnt-next-period');
    bntActivate($user);
    $account = bntAccount($user);
    $run = bntImportRun($user);

    $groceries = bntCategory('Groceries');
    bntAssign($user, $groceries, 10000);
    bntSpend($user, $account, $run, $groceries, 9500, CarbonImmutable::now());

    bntRunJob($user);
    expect(bntNudgeCount($user->id))->toBe(1);

    // Advance into the next monthly period; re-assign + re-spend past
    // threshold there too — the period key rolls, so the nudge legitimately
    // fires again (never suppressed by the D-06 occurrence key).
    CarbonImmutable::setTestNow('2026-08-04 10:00:00');
    bntAssign($user, $groceries, 10000);
    bntSpend($user, $account, $run, $groceries, 9500, CarbonImmutable::now());

    bntRunJob($user);

    expect(bntNudgeCount($user->id))->toBe(2);
});

it('honours each envelope\'s own threshold: 50% explicit fires at 55% used, 90% default does not (D-20)', function (): void {
    $user = bntUser('bnt-per-budget-threshold');
    bntActivate($user);
    $account = bntAccount($user);
    $run = bntImportRun($user);

    $explicit = bntCategory('ExplicitThresh');
    bntSetThreshold($user, $explicit, 50);
    bntAssign($user, $explicit, 10000);
    bntSpend($user, $account, $run, $explicit, 5500, CarbonImmutable::now());

    $default = bntCategory('DefaultThresh');
    bntAssign($user, $default, 10000);
    bntSpend($user, $account, $run, $default, 5500, CarbonImmutable::now());

    bntRunJob($user);

    expect(bntNudgeCount($user->id))->toBe(1);
    expect(bntNudgeCount($user->id, 'ExplicitThresh'))->toBe(1);
    expect(bntNudgeCount($user->id, 'DefaultThresh'))->toBe(0);
});

it('never leaks one user\'s over-budget envelope into another user\'s nudges (cross-user)', function (): void {
    $userA = bntUser('bnt-cross-a');
    bntActivate($userA);
    $accountA = bntAccount($userA);
    $runA = bntImportRun($userA);
    $groceriesA = bntCategory('Groceries');
    bntAssign($userA, $groceriesA, 10000);
    bntSpend($userA, $accountA, $runA, $groceriesA, 9500, CarbonImmutable::now());

    $userB = bntUser('bnt-cross-b');
    bntActivate($userB);

    bntRunJob($userA);
    bntRunJob($userB);

    expect(bntNudgeCount($userA->id))->toBe(1);
    expect(bntNudgeCount($userB->id))->toBe(0);
});
