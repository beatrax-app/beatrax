<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\PeriodComparison;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Dto\ReportResultRow;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

/*
 * Plan 06 Task 2 (Req 13) — pins `ReportAggregator::run()`'s `compare =
 * true` behavior: `comparisonRows` carries the UNION of the current AND
 * previous-equivalent period's groups (a category that dropped to zero
 * this period still surfaces as a mover, mirroring
 * `CategorySpendTrendQuery`'s union-of-keys shape), each annotated with
 * `previousAmountMinor` + a signed `deltaMinor` (current − previous),
 * sorted by `abs(deltaMinor)` descending — and that comparison honors
 * `currencyMode` (a previous-period unconvertible-currency row is excluded
 * exactly like the current period's own base-mode total).
 */

function pctUser(string $prefix = 'pct'): User
{
    /** @var User */
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function pctAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-'.bin2hex(random_bytes(3)),
        'kind' => 'asn',
        'iban' => 'NL00PCTX'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function pctCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function pctImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pct-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'pct-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pctTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;
    $settledCurrency = $overrides['settled_currency'] ?? 'EUR';

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => pctImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $settledMinor,
        'currency' => $settledCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $settledCurrency,
        'counterparty_name' => 'PCT Vendor',
        'counterparty_normalized' => 'pct-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'pct-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function pctDefinition(bool $compare = true, string $currencyMode = 'base'): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: $currencyMode,
        viz: 'table',
        customFrom: '2026-03-01',
        customTo: '2026-03-31',
        compare: $compare,
    );
}

function pctMarchPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );
}

it('previousPeriod() steps back by calendar months for a month-aligned period, not a raw day-count shift (CR-01)', function (): void {
    $comparison = new PeriodComparison;
    $current = pctMarchPeriod(); // March 2026: 2026-03-01 -> 2026-04-01 (31 days)

    $previous = $comparison->previousPeriod($current);

    // The correct previous calendar month is February 2026 (28 days in
    // 2026, a non-leap year) — NOT a 31-day day-count shift, which used to
    // "borrow" 3 days from January (2026-01-29 -> 2026-03-01) instead of
    // landing on the real previous month.
    expect($previous->start->toDateString())->toBe('2026-02-01');
    expect($previous->endExclusive->toDateString())->toBe('2026-03-01');
});

it('previousPeriod() Feb->Jan and Mar->Feb transitions land on the correct calendar month, never a day-count shift (CR-01)', function (): void {
    $comparison = new PeriodComparison;

    $feb = new Period(
        start: CarbonImmutable::parse('2027-02-01'),
        endExclusive: CarbonImmutable::parse('2027-03-01'),
        label: 'February 2027',
    );
    $previousOfFeb = $comparison->previousPeriod($feb);
    expect($previousOfFeb->start->toDateString())->toBe('2027-01-01');
    expect($previousOfFeb->endExclusive->toDateString())->toBe('2027-02-01');

    $mar = new Period(
        start: CarbonImmutable::parse('2027-03-01'),
        endExclusive: CarbonImmutable::parse('2027-04-01'),
        label: 'March 2027',
    );
    $previousOfMar = $comparison->previousPeriod($mar);
    // The exact CR-01 concrete-failure scenario from the review: March (31
    // days) must step back to February (28 days) — a day-count shift would
    // wrongly land on 2027-01-29 instead of 2027-02-01.
    expect($previousOfMar->start->toDateString())->toBe('2027-02-01');
    expect($previousOfMar->endExclusive->toDateString())->toBe('2027-03-01');
});

it('comparisonRows carries correct current/previous/signed-delta per group, sorted by abs(delta) desc', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pctUser();
    $account = pctAccount($user);
    $groceries = pctCategory('Groceries');
    $fuel = pctCategory('Fuel');
    $rent = pctCategory('Rent');

    $comparison = app(PeriodComparison::class);
    $previousPeriod = $comparison->previousPeriod(pctMarchPeriod());
    $previousDate = $previousPeriod->start->addDays(2)->toDateString();

    // Current period (March): Groceries -15_000, Fuel -2_000.
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -15_000, 'category_id' => $groceries->id,
        'posted_at' => '2026-03-10', 'booked_at' => '2026-03-10 09:00:00', 'value_date' => '2026-03-10',
    ]);
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -2_000, 'category_id' => $fuel->id,
        'posted_at' => '2026-03-20', 'booked_at' => '2026-03-20 09:00:00', 'value_date' => '2026-03-20',
    ]);

    // Previous-equivalent period: Groceries -10_000 (grew +5_000 this
    // period), Fuel -6_000 (shrank -4_000 this period), Rent -9_000 (only
    // existed in the previous period — dropped to zero this period, must
    // still surface as a mover).
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -10_000, 'category_id' => $groceries->id,
        'posted_at' => $previousDate, 'booked_at' => $previousDate.' 09:00:00', 'value_date' => $previousDate,
    ]);
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -6_000, 'category_id' => $fuel->id,
        'posted_at' => $previousDate, 'booked_at' => $previousDate.' 10:00:00', 'value_date' => $previousDate,
    ]);
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -9_000, 'category_id' => $rent->id,
        'posted_at' => $previousDate, 'booked_at' => $previousDate.' 11:00:00', 'value_date' => $previousDate,
    ]);

    $result = app(ReportAggregator::class)->run($user, pctDefinition());

    expect($result->comparisonRows)->not->toBeNull();
    $byLabel = [];
    foreach ($result->comparisonRows as $row) {
        $byLabel[$row->groupLabel] = $row;
    }

    expect($byLabel['Groceries']->amountMinor)->toBe(15_000);
    expect($byLabel['Groceries']->previousAmountMinor)->toBe(10_000);
    expect($byLabel['Groceries']->deltaMinor)->toBe(5_000);

    expect($byLabel['Fuel']->amountMinor)->toBe(2_000);
    expect($byLabel['Fuel']->previousAmountMinor)->toBe(6_000);
    expect($byLabel['Fuel']->deltaMinor)->toBe(-4_000);

    expect($byLabel['Rent']->amountMinor)->toBe(0);
    expect($byLabel['Rent']->previousAmountMinor)->toBe(9_000);
    expect($byLabel['Rent']->deltaMinor)->toBe(-9_000);

    // Sorted by abs(delta) desc: Rent (9_000) > Groceries (5_000) > Fuel (4_000).
    $order = array_map(static fn ($row) => $row->groupLabel, $result->comparisonRows);
    expect($order)->toBe(['Rent', 'Groceries', 'Fuel']);
});

it('comparison honors currencyMode=base — a previous-period unconvertible-currency row is excluded, never converted at 1:1', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pctUser();
    $account = pctAccount($user);

    $comparison = app(PeriodComparison::class);
    $previousPeriod = $comparison->previousPeriod(pctMarchPeriod());
    $previousDate = $previousPeriod->start->addDays(2)->toDateString();

    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -5_000, 'settled_currency' => 'EUR',
        'posted_at' => '2026-03-10', 'booked_at' => '2026-03-10 09:00:00', 'value_date' => '2026-03-10',
    ]);
    // Previous-period JPY row — no exchange_rates row seeded for JPY, so a
    // base-mode conversion must exclude it, never fall back to 1:1 (which
    // would have added 500_000 to the previous total).
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -500_000, 'settled_currency' => 'JPY',
        'posted_at' => $previousDate, 'booked_at' => $previousDate.' 09:00:00', 'value_date' => $previousDate,
    ]);
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -3_000, 'settled_currency' => 'EUR',
        'posted_at' => $previousDate, 'booked_at' => $previousDate.' 10:00:00', 'value_date' => $previousDate,
    ]);

    $result = app(ReportAggregator::class)->run($user, pctDefinition());

    expect($result->comparisonRows)->toHaveCount(1);
    $row = $result->comparisonRows[0];
    expect($row->amountMinor)->toBe(5_000);
    expect($row->previousAmountMinor)->toBe(3_000);
    expect($row->deltaMinor)->toBe(2_000);
});

it('CR-03: compare() keeps BOTH currencies for a group present in EUR and USD under currencyMode=original, never overwriting one with the other', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pctUser();
    $account = pctAccount($user);
    $amazon = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'pct-amazon-'.bin2hex(random_bytes(3)),
        'display_name' => 'Amazon',
        'merchant_name' => 'AMAZON',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -5_000, 'settled_currency' => 'EUR', 'counterparty_id' => $amazon,
        'posted_at' => '2026-03-10', 'booked_at' => '2026-03-10 09:00:00', 'value_date' => '2026-03-10',
    ]);
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -3_000, 'settled_currency' => 'USD', 'counterparty_id' => $amazon,
        'posted_at' => '2026-03-12', 'booked_at' => '2026-03-12 09:00:00', 'value_date' => '2026-03-12',
    ]);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'counterparty',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'original',
        viz: 'table',
        customFrom: '2026-03-01',
        customTo: '2026-03-31',
        compare: true,
    );

    $result = app(ReportAggregator::class)->run($user, $definition);

    /** @var list<ReportResultRow> $amazonRows */
    $amazonRows = array_values(array_filter($result->comparisonRows, static fn ($r) => $r->groupLabel === 'Amazon'));
    expect($amazonRows)->toHaveCount(2);

    $byCurrency = [];
    foreach ($amazonRows as $row) {
        $byCurrency[$row->currency] = $row;
    }
    expect($byCurrency)->toHaveKeys(['EUR', 'USD']);
    expect($byCurrency['EUR']->amountMinor)->toBe(5_000);
    expect($byCurrency['USD']->amountMinor)->toBe(3_000);
});

it('WR-04: hasExcludedAccounts/accountsWithoutRate surface an unconvertible currency that ONLY existed in the previous period', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pctUser();
    $account = pctAccount($user);

    $comparison = app(PeriodComparison::class);
    $previousPeriod = $comparison->previousPeriod(pctMarchPeriod());
    $previousDate = $previousPeriod->start->addDays(2)->toDateString();

    // Current period: fully convertible EUR only — the CURRENT period's own
    // result carries hasExcludedAccounts = false.
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -5_000, 'settled_currency' => 'EUR',
        'posted_at' => '2026-03-10', 'booked_at' => '2026-03-10 09:00:00', 'value_date' => '2026-03-10',
    ]);

    // Previous period: an unconvertible JPY row — no exchange_rates row
    // seeded for JPY, so a base-mode conversion must exclude it.
    pctTransaction($db, $user, $account, [
        'settled_amount_minor' => -500_000, 'settled_currency' => 'JPY',
        'posted_at' => $previousDate, 'booked_at' => $previousDate.' 09:00:00', 'value_date' => $previousDate,
    ]);

    $result = app(ReportAggregator::class)->run($user, pctDefinition(compare: true, currencyMode: 'base'));

    // Without WR-04, only the CURRENT period's (false/0) flags would
    // survive onto the final DTO and the previous period's JPY exclusion
    // would be invisible to the user viewing the compare delta.
    expect($result->hasExcludedAccounts)->toBeTrue();
    expect($result->accountsWithoutRate)->toBeGreaterThanOrEqual(1);
});
