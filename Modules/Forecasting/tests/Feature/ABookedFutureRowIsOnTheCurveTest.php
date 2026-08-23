<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

const BFR_HORIZON_DAYS = 365;

const BFR_TODAY = '2026-08-23';

const BFR_RENT_DATE = '2026-09-01';

const BFR_RENT_MINOR = -145_000;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BFR_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'bfr',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function bfrAccount(DatabaseManager $db, int $userId, string $currency = Currency::Eur->value, string $name = 'BFR Bank'): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'bfr-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00BFR'.strtoupper($hex),
        'default_currency' => $currency,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function bfrTransaction(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    string $postedAt,
    int $settledMinor,
    string $settledCurrency = Currency::Eur->value,
    string $counterpartyNormalized = 'woonstichting-delta',
    string $type = TransactionType::Expense->value,
): int {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bfr-'.$hex.'.csv',
        'sha256' => hash('sha256', 'bfr-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'bfr-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => $settledCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $settledCurrency,
        'counterparty_normalized' => $counterpartyNormalized,
        'counterparty_name' => 'Woonstichting Delta',
        'normalization_version' => 1,
        'description' => 'bfr fixture',
        'type' => $type,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function bfrMonthlySeries(DatabaseManager $db, int $userId, string $nextExpectedAt, string $clusterCounterpartyKey = 'woonstichting-delta'): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Woonstichting Delta',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => BFR_RENT_MINOR,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => BFR_RENT_MINOR,
        'variance_tolerance_percent' => 0,
        'cluster_key' => 'bfr::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => $clusterCounterpartyKey,
        'next_expected_at' => $nextExpectedAt,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @param  list<ForecastPointDto>  $points
 */
function bfrPointOn(array $points, string $date): int
{
    foreach ($points as $point) {
        if ($point->date === $date) {
            return $point->pointMinor;
        }
    }

    throw new RuntimeException('No forecast point on '.$date);
}

// /transactions listed the rent, the ledger held it with a posted_at of 1
// September, and every forward-looking surface behaved as though it did not
// exist: nothing but a recurring series ever reached the projection.
it('steps the curve on the booked row\'s own date by exactly its amount', function (): void {
    $accountId = bfrAccount($this->db, $this->user->id);
    bfrTransaction($this->db, $this->user->id, $accountId, BFR_RENT_DATE, BFR_RENT_MINOR);

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);
    $points = app(ForecastQuery::class)->forUser($accountId, BFR_HORIZON_DAYS, null, $this->user)->points;

    $dayBefore = CarbonImmutable::parse(BFR_RENT_DATE)->subDay()->toDateString();

    expect(bfrPointOn($points, BFR_RENT_DATE) - bfrPointOn($points, $dayBefore))->toBe(BFR_RENT_MINOR);
});

// It is money known, not money held. The five surfaces that agree on today
// must all still leave it out.
it('leaves today alone on every surface that answers for today', function (): void {
    $accountId = bfrAccount($this->db, $this->user->id);
    bfrTransaction($this->db, $this->user->id, $accountId, BFR_RENT_DATE, BFR_RENT_MINOR);

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);

    $forecast = app(ForecastQuery::class)->forUser($accountId, BFR_HORIZON_DAYS, null, $this->user);
    $ledger = app(AccountBalanceQuery::class)
        ->currentBalanceAsOf($accountId, $this->user, CarbonImmutable::now()->startOfDay())
        ->in(Currency::Eur->value);
    $netWorth = app(NetWorthQuery::class)->forUser($this->user);
    $pots = app(PotBalanceQuery::class)->reconciliationForAccount($accountId, $this->user);

    expect($ledger)->toBe(500_000)
        ->and($forecast->todayBalanceMinor)->toBe($ledger)
        ->and($netWorth->accounts[0]->balanceMinor)->toBe($ledger)
        ->and($pots->realBalanceMinor)->toBe($ledger)
        ->and(bfrPointOn($forecast->points, BFR_TODAY))->toBe($ledger);
});

// The case the whole change turns on. A monthly rent whose next expected date
// is the day the booked row already names is ONE payment: emitting the series
// estimate as well drew EUR2,900.00 out of the account for one EUR1,450.00
// rent, and the projection has no occurrence link to tell it otherwise until
// the next detection sweep has run.
it('counts a booked row that is also a projected occurrence once', function (): void {
    $accountId = bfrAccount($this->db, $this->user->id);
    bfrMonthlySeries($this->db, $this->user->id, BFR_RENT_DATE);
    bfrTransaction($this->db, $this->user->id, $accountId, BFR_RENT_DATE, BFR_RENT_MINOR);

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);
    $points = app(ForecastQuery::class)->forUser($accountId, BFR_HORIZON_DAYS, null, $this->user)->points;

    $dayBefore = CarbonImmutable::parse(BFR_RENT_DATE)->subDay()->toDateString();

    expect(bfrPointOn($points, BFR_RENT_DATE) - bfrPointOn($points, $dayBefore))->toBe(BFR_RENT_MINOR);
});

// The same rent, once the sweep HAS read it: the occurrence link is the
// authoritative answer to which series a row belongs to, and it must supersede
// on it too rather than only on the cluster identity.
it('counts it once when the occurrence link is the only thing relating the two', function (): void {
    $accountId = bfrAccount($this->db, $this->user->id);
    $seriesId = bfrMonthlySeries($this->db, $this->user->id, BFR_RENT_DATE, 'a-key-the-row-does-not-carry');
    $transactionId = bfrTransaction($this->db, $this->user->id, $accountId, BFR_RENT_DATE, BFR_RENT_MINOR);

    $this->db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $this->user->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transactionId,
        'observed_at' => BFR_RENT_DATE,
        'observed_amount_minor' => BFR_RENT_MINOR,
        'observed_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);
    $points = app(ForecastQuery::class)->forUser($accountId, BFR_HORIZON_DAYS, null, $this->user)->points;

    $dayBefore = CarbonImmutable::parse(BFR_RENT_DATE)->subDay()->toDateString();

    expect(bfrPointOn($points, BFR_RENT_DATE) - bfrPointOn($points, $dayBefore))->toBe(BFR_RENT_MINOR);
});

// A bank that moves a direct debit off a weekend still charges the rent once.
it('counts it once when the bank booked it a few days off the expected date', function (): void {
    $accountId = bfrAccount($this->db, $this->user->id);
    bfrMonthlySeries($this->db, $this->user->id, BFR_RENT_DATE);
    $bookedOn = CarbonImmutable::parse(BFR_RENT_DATE)->addDays(2)->toDateString();
    bfrTransaction($this->db, $this->user->id, $accountId, $bookedOn, BFR_RENT_MINOR);

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);
    $points = app(ForecastQuery::class)->forUser($accountId, BFR_HORIZON_DAYS, null, $this->user)->points;

    $before = CarbonImmutable::parse(BFR_RENT_DATE)->subDay()->toDateString();
    $after = CarbonImmutable::parse($bookedOn)->toDateString();

    expect(bfrPointOn($points, $after) - bfrPointOn($points, $before))->toBe(BFR_RENT_MINOR);
});

// A different merchant on the same day is a second payment, not the same one.
it('keeps a series estimate a booked row of another counterparty does not cover', function (): void {
    $accountId = bfrAccount($this->db, $this->user->id);
    bfrMonthlySeries($this->db, $this->user->id, BFR_RENT_DATE);
    bfrTransaction($this->db, $this->user->id, $accountId, BFR_RENT_DATE, -1_099, Currency::Eur->value, 'some-other-merchant');

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);
    $points = app(ForecastQuery::class)->forUser($accountId, BFR_HORIZON_DAYS, null, $this->user)->points;

    $dayBefore = CarbonImmutable::parse(BFR_RENT_DATE)->subDay()->toDateString();

    expect(bfrPointOn($points, BFR_RENT_DATE) - bfrPointOn($points, $dayBefore))->toBe(BFR_RENT_MINOR - 1_099);
});

// A contribution is denominated in something. The projection runs on the one
// line its account is denominated in, and the anchor deliberately leaves the
// account's other lines out — so a dollar row must move the dollar account's
// curve and must not be added to a euro one.
it('lands a foreign-currency row on the account line it is denominated in', function (): void {
    $euroId = bfrAccount($this->db, $this->user->id, Currency::Eur->value, 'BFR Euro');
    $dollarId = bfrAccount($this->db, $this->user->id, Currency::Usd->value, 'BFR Dollar');

    bfrTransaction($this->db, $this->user->id, $dollarId, BFR_RENT_DATE, -20_000, Currency::Usd->value);
    bfrTransaction($this->db, $this->user->id, $euroId, BFR_RENT_DATE, -30_000, Currency::Usd->value);

    app(ProjectionPipeline::class)->project($this->user, null, BFR_HORIZON_DAYS);

    $forecasts = app(ForecastQuery::class);
    $dollar = $forecasts->forUser($dollarId, BFR_HORIZON_DAYS, null, $this->user);
    $euro = $forecasts->forUser($euroId, BFR_HORIZON_DAYS, null, $this->user);

    $dayBefore = CarbonImmutable::parse(BFR_RENT_DATE)->subDay()->toDateString();

    expect($dollar->defaultCurrency)->toBe(Currency::Usd->value)
        ->and(bfrPointOn($dollar->points, BFR_RENT_DATE) - bfrPointOn($dollar->points, $dayBefore))->toBe(-20_000)
        ->and($euro->defaultCurrency)->toBe(Currency::Eur->value)
        ->and(bfrPointOn($euro->points, BFR_RENT_DATE) - bfrPointOn($euro->points, $dayBefore))->toBe(0);
});
