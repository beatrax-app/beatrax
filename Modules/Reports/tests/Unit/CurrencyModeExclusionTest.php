<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub (999.6-03 Task 2, Req 4/5).
 *
 * Exercises CurrencyModeApplier (Plan 06) — a DIFFERENT code path than
 * NetWorthSeriesQuery's FxExclusionTest (Plan 05): this is about
 * transaction-level `settled_currency` rows the report aggregator must
 * convert to the user's `base_currency` under `currencyMode = 'base'`, not
 * account-level balances.
 *
 * Pins the contract: when NO exchange rate exists for a settled currency,
 * ReportAggregator::run() under currencyMode='base' must EXCLUDE that row
 * from spend/income/net totals AND count it (ReportResultDto.
 * hasExcludedAccounts = true, accountsWithoutRate >= 1) — it must NEVER
 * silently fall back to a 1:1 rate (T-999.6-07 canonical-total guard,
 * mirrors NetWorthQuery's D-07 no-rate-fallback algorithm).
 *
 * RED as intended: ReportAggregator does not exist yet — every test below
 * fails on `app(ReportAggregator::class)` (missing class), not on the
 * (already-existing) ReportDefinition DTO it's built from.
 */

function cmeUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'cme-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function cmeAccount(User $user, string $currency = 'EUR'): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $currency.' account',
        'slug' => strtolower($currency).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00CMEX'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

function cmeImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cme-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'cme-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cmeTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    // amount_minor tracks settled_amount_minor by default so distinct
    // settled amounts on the same account/date never collide against the
    // composite fingerprint UNIQUE index (user_id, account_id, posted_at,
    // booked_at, amount_minor, currency, counterparty_normalized).
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;
    $settledCurrency = $overrides['settled_currency'] ?? 'EUR';

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => cmeImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $settledMinor,
        'currency' => $settledCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $settledCurrency,
        'counterparty_name' => 'CME Vendor',
        'counterparty_normalized' => 'cme-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'cme-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function cmeDefinition(string $metric): ReportDefinition
{
    return new ReportDefinition(
        metric: $metric,
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
    );
}

it('fx_exclusion_never_1to1_transactions: an unconvertible-currency row is excluded and counted, never converted at 1:1', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cmeUser();
    $eurAccount = cmeAccount($user, 'EUR');
    $jpyAccount = cmeAccount($user, 'JPY'); // no exchange_rates row seeded for JPY -> EUR

    // Convertible EUR baseline.
    cmeTransaction($db, $user, $eurAccount, ['type' => 'expense', 'settled_amount_minor' => -10_000, 'settled_currency' => 'EUR']);
    cmeTransaction($db, $user, $eurAccount, ['type' => 'income', 'settled_amount_minor' => 30_000, 'settled_currency' => 'EUR']);

    // Unconvertible JPY expense + income — no rate exists for this pair.
    cmeTransaction($db, $user, $jpyAccount, ['type' => 'expense', 'settled_amount_minor' => -500_000, 'settled_currency' => 'JPY']);
    cmeTransaction($db, $user, $jpyAccount, ['type' => 'income', 'settled_amount_minor' => 300_000, 'settled_currency' => 'JPY']);

    $spend = app(ReportAggregator::class)->run($user, cmeDefinition('spend'));

    // Only the EUR expense contributes — the JPY expense is excluded, not
    // silently converted at a fabricated 1:1 rate (which would have added
    // 500_000 to the total).
    expect($spend->totalMinor)->toBe(10_000);
    expect($spend->hasExcludedAccounts)->toBeTrue();
    expect($spend->accountsWithoutRate)->toBeGreaterThanOrEqual(1);

    $income = app(ReportAggregator::class)->run($user, cmeDefinition('income'));
    expect($income->totalMinor)->toBe(30_000);
    expect($income->hasExcludedAccounts)->toBeTrue();
    expect($income->accountsWithoutRate)->toBeGreaterThanOrEqual(1);
});

it('currencyMode=original never triggers exclusion — each currency reports its own native total', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cmeUser();
    $jpyAccount = cmeAccount($user, 'JPY');

    cmeTransaction($db, $user, $jpyAccount, ['type' => 'expense', 'settled_amount_minor' => -500_000, 'settled_currency' => 'JPY']);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'original',
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
    );

    $spend = app(ReportAggregator::class)->run($user, $definition);

    expect($spend->hasExcludedAccounts)->toBeFalse();
    expect($spend->accountsWithoutRate)->toBe(0);
});

it('CR-02: currencyMode=original with an account filter reports the correct currency/total, never $0.00 next to non-empty rows', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cmeUser();
    $usdAccount = cmeAccount($user, 'USD');
    $eurAccount = cmeAccount($user, 'EUR');

    // USD row on the EXCLUDED account, inserted FIRST so an unfiltered
    // `DISTINCT settled_currency` scan would very plausibly surface it
    // ahead of EUR — the exact "wrong primary currency" failure mode CR-02
    // fixes. discoverCurrencies() must never even see this row once the
    // report is filtered to $eurAccount only.
    cmeTransaction($db, $user, $usdAccount, ['settled_amount_minor' => -50_000, 'settled_currency' => 'USD']);
    cmeTransaction($db, $user, $eurAccount, ['settled_amount_minor' => -5_000, 'settled_currency' => 'EUR']);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'account',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'original',
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
        accounts: [$eurAccount->id],
    );

    $result = app(ReportAggregator::class)->run($user, $definition);

    expect($result->currency)->toBe('EUR');
    expect($result->totalMinor)->toBe(5_000);
    expect($result->rows)->not->toBeEmpty();
    foreach ($result->rows as $row) {
        expect($row->currency)->toBe('EUR');
    }
});
