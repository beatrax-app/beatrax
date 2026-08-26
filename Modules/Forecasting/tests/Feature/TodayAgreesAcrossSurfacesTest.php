<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\DailyBalanceAggregator;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Pots\Public\Services\PotBalanceQuery;

uses(RefreshDatabase::class);

// The forecast horizon the calendar asks for; the run has to match it or the
// calendar falls back to its computing sentinel and proves nothing.
const TAAS_HORIZON_DAYS = 365;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'taas',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function taasTransaction(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    string $postedAt,
    int $settledMinor,
    ?int $nativeMinor = null,
    string $nativeCurrency = Currency::Eur->value,
): void {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/taas-'.$hex.'.csv',
        'sha256' => hash('sha256', 'taas-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'taas-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $nativeMinor ?? $settledMinor,
        'currency' => $nativeCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'taas',
        'counterparty_name' => 'TAAS',
        'normalization_version' => 1,
        'description' => 'taas fixture',
        'type' => $settledMinor >= 0 ? TransactionType::Income->value : TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function taasSeedAccountWithStaleStatement(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TAAS Bank',
        'slug' => 'taas-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00TAAS'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 150_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $summaryRunId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/taas-summary-'.$hex.'.csv',
        'sha256' => hash('sha256', 'taas-summary-'.$hex),
        'uploaded_at' => '2026-04-11 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-04-11 00:00:00',
        'updated_at' => '2026-04-11 00:00:00',
    ]);

    $db->connection()->table('statement_summaries')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $summaryRunId,
        'iban_owner' => 'NL57ASNB0123456789',
        'period_start' => '2026-03-11 00:00:00',
        'period_end' => '2026-04-11 00:00:00',
        'closing_balance_minor' => 201_111,
        'closing_balance_currency' => Currency::Eur->value,
        'closing_balance_date' => '2026-04-11 00:00:00',
        'created_at' => '2026-04-11 00:00:00',
        'updated_at' => '2026-04-11 00:00:00',
    ]);

    taasTransaction($db, $userId, $accountId, '2026-04-20', 60_000);
    taasTransaction($db, $userId, $accountId, '2026-08-20', -15_891);
    taasTransaction($db, $userId, $accountId, '2026-08-23', -2_000);
    taasTransaction($db, $userId, $accountId, '2026-08-30', 50_000);

    return $accountId;
}

// Four surfaces answer "what is on this account today" and they must answer
// with one number. Before this, the projection answered EUR2,011.11 from a
// statement that closed on 11 April while the other three answered EUR1,921.09.
it('opens the forecast on the same figure the dashboard, pots and reconcile show', function (): void {
    $accountId = taasSeedAccountWithStaleStatement($this->db, $this->user->id);

    app(ProjectionPipeline::class)->project($this->user, null, TAAS_HORIZON_DAYS);

    $forecast = app(ForecastQuery::class)->forUser($accountId, TAAS_HORIZON_DAYS, null, $this->user);
    $ledger = app(AccountBalanceQuery::class)
        ->currentBalanceAsOf($accountId, $this->user, CarbonImmutable::now()->startOfDay())
        ->in(Currency::Eur->value);
    $netWorth = app(NetWorthQuery::class)->forUser($this->user);
    $pots = app(PotBalanceQuery::class)->reconciliationForAccount($accountId, $this->user);

    expect($ledger)->toBe(192_109)
        ->and($forecast->todayBalanceMinor)->toBe($ledger)
        ->and($netWorth->accounts[0]->balanceMinor)->toBe($ledger)
        ->and($pots->realBalanceMinor)->toBe($ledger);
});

// The calendar draws days before today from the transactions themselves and
// today from the projection's opening figure, so a wrong anchor showed up as a
// step on today: EUR3,020 on 22 August dropping to EUR2,085 on the 23rd.
it('draws today on the calendar at the projection opening, continuous from yesterday', function (): void {
    $accountId = taasSeedAccountWithStaleStatement($this->db, $this->user->id);

    app(ProjectionPipeline::class)->project($this->user, null, TAAS_HORIZON_DAYS);

    $today = CarbonImmutable::now()->startOfDay();
    $built = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$accountId],
        $this->user,
        $today->startOfMonth(),
        $today->endOfMonth()->startOfDay(),
    );

    $forecast = app(ForecastQuery::class)->forUser($accountId, TAAS_HORIZON_DAYS, null, $this->user);

    expect($built['todayAnchorMinor'])->toBe($forecast->todayBalanceMinor)
        ->and($built['map'][$today->toDateString()][0])->toBe(192_109)
        ->and($built['map'][$today->subDay()->toDateString()][0])->toBe(194_109);
});

function taasSeedEuroAccountWithForeignRows(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TAAS Euro Account',
        'slug' => 'taas-fx-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL01TAAS'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    // The bank charged EUR100 for a USD120 purchase — an implied 1.2 — while
    // the rate table below says 1.5. Re-deriving the euro figure from the
    // dollar one therefore lands EUR20 away from what the account was debited.
    taasTransaction($db, $userId, $accountId, '2026-08-20', -10_000, -12_000, Currency::Usd->value);
    taasTransaction($db, $userId, $accountId, '2026-08-23', -2_000);

    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-08-23',
        'rate' => '1.5',
        'source' => BundledRates::SOURCE,
        'created_at' => '2026-08-23 00:00:00',
        'updated_at' => '2026-08-23 00:00:00',
    ]);

    return $accountId;
}

// The same continuity, for the account that actually splits the two
// derivations apart. Summing the native amount at today's rate drew yesterday
// at EUR920.00 against a ledger of EUR900.00, so the line stepped EUR20 at
// today on a curve with nothing behind the step.
it('draws a past day carrying foreign rows at the balance the account was debited', function (): void {
    $accountId = taasSeedEuroAccountWithForeignRows($this->db, $this->user->id);

    app(ProjectionPipeline::class)->project($this->user, null, TAAS_HORIZON_DAYS);

    $today = CarbonImmutable::now()->startOfDay();
    $yesterday = $today->subDay();
    $built = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$accountId],
        $this->user,
        $today->startOfMonth(),
        $today->endOfMonth()->startOfDay(),
    );

    $balances = app(AccountBalanceQuery::class);
    $ledgerYesterday = $balances->currentBalanceAsOf($accountId, $this->user, $yesterday)->in(Currency::Eur->value);
    $ledgerToday = $balances->currentBalanceAsOf($accountId, $this->user, $today)->in(Currency::Eur->value);

    expect($ledgerYesterday)->toBe(90_000)
        ->and($ledgerToday)->toBe(88_000)
        ->and($built['map'][$yesterday->toDateString()][0])->toBe($ledgerYesterday)
        ->and($built['map'][$today->toDateString()][0])->toBe($ledgerToday)
        ->and($built['todayAnchorMinor'])->toBe($ledgerToday)
        ->and($built['map'][$today->toDateString()][0] - $built['map'][$yesterday->toDateString()][0])->toBe(-2_000);
});
