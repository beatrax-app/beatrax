<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\ComponentAttributeBag;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Mapping\StoredRateSet;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Models\ForecastRun;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\ValueObjects\Rate;

uses(RefreshDatabase::class);

// The single-account curve is rehydrated from forecast_runs.result_json, which
// recorded what the fold could NOT price and nothing about what priced the
// rest. A dollar subscription moved a euro balance at a rate the page could
// not name, and a run stored before the format carried one must say that
// rather than render as though it had converted nothing.

// EUR 1 = USD 1.1359 from the bundled snapshot, read the other way round at the
// column's own eight places. forDisplay() keeps 0.88, which does not rebuild
// the figure the fold folded in.
const SPR_USD_RATE = '0.88035919';

const SPR_SNAPSHOT_DAY = '2026-06-05';

function sprUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'spr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function sprAccount(User $user): int
{
    return (int) app(DatabaseManager::class)->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'ASN Betaalrekening',
        'slug' => 'spr-'.bin2hex(random_bytes(5)),
        'kind' => 'bank',
        'iban' => 'NL00SPR'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 150_000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// A dollar subscription on a euro account: the only contribution the fold has
// to convert, so the rate it converted at is the whole of what the run should
// have recorded and did not.
function sprDollarSeries(User $user, int $accountId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Netflix US',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1_199,
        'latest_currency' => 'USD',
        'monthly_equivalent_minor' => -1_199,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-06-18',
        'next_expected_confidence_low' => false,
        'cluster_key' => 'spr-cluster-'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'netflix us',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $importRunId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/spr-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'spr-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'committed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    foreach (['2026-03-18', '2026-04-18', '2026-05-18'] as $day) {
        $transactionId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $importRunId,
            'fingerprint' => hash('sha256', 'spr-'.$day.'-'.bin2hex(random_bytes(6))),
            'fingerprint_version' => 3,
            'posted_at' => $day,
            'booked_at' => $day.' 00:00:00',
            'value_date' => $day,
            'amount_minor' => -1_199,
            'currency' => 'USD',
            'settled_amount_minor' => -1_055,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Netflix US',
            'counterparty_normalized' => 'netflix us',
            'normalization_version' => 1,
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);

        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $transactionId,
            'observed_at' => $day,
            'observed_amount_minor' => -1_199,
            'observed_currency' => 'USD',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
    }
}

/**
 * @return array{0: User, 1: int}
 */
function sprProjectedAt(string $today, bool $withSeries = true): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($today)->startOfDay());
    $user = sprUser();
    $accountId = sprAccount($user);

    if ($withSeries) {
        sprDollarSeries($user, $accountId);
    }

    Bus::dispatchSync(new ProjectForecastJob(userId: $user->id, scenarioId: null, horizonDays: 30));

    return [$user, $accountId];
}

/**
 * @return array<array-key, mixed>
 */
function sprStoredAccountBlock(User $user, int $accountId): array
{
    $row = app(DatabaseManager::class)->connection()->table('forecast_runs')
        ->where('user_id', $user->id)
        ->orderByDesc('id')
        ->first();

    /** @var array<array-key, mixed> $decoded */
    $decoded = json_decode((string) ($row->result_json ?? ''), associative: true);
    /** @var array<array-key, mixed> $accounts */
    $accounts = $decoded['accounts'];
    /** @var array<array-key, mixed> $block */
    $block = $accounts[(string) $accountId] ?? $accounts[$accountId];

    return $block;
}

// A run the pipeline did NOT write, in the shape it used to write: every field
// the reader needs except the rates. Nothing can recover them -- the pair's
// rate today is not the rate this run used -- so the surface says so.
function sprLegacyRun(User $user, int $accountId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $points = [];
    for ($day = 0; $day <= 30; $day++) {
        $points[] = [
            'date' => CarbonImmutable::parse('2026-06-06')->addDays($day)->toDateString(),
            'low_minor' => 148_000,
            'point_minor' => 149_000,
            'high_minor' => 150_000,
            'currency' => 'EUR',
        ];
    }

    $run = new ForecastRun;
    $run->user_id = $user->id;
    $run->scenario_id = null;
    $run->horizon_days = 30;
    $run->status = 'complete';
    $run->save();

    $db->connection()->table('forecast_runs')->where('id', $run->id)->update([
        'result_json' => json_encode([
            'as_of' => '2026-06-06',
            'horizon_days' => 30,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'ASN Betaalrekening',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => 150_000,
                    'anchor_source' => 'sum_of_transactions',
                    'unconverted_currencies' => [],
                    'points' => $points,
                ],
            ],
        ]),
    ]);
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes the rates the fold priced with into the run it stores', function (): void {
    [$user, $accountId] = sprProjectedAt('2026-06-06');

    $block = sprStoredAccountBlock($user, $accountId);

    /** @var list<array<string, mixed>> $rates */
    $rates = $block[StoredRateSet::KEY];

    expect($rates)->toHaveCount(1)
        ->and($rates[0]['from'])->toBe('USD')
        ->and($rates[0]['to'])->toBe('EUR')
        ->and($rates[0]['source'])->toBe(BundledRates::SOURCE)
        ->and($rates[0]['as_of'])->toBe(SPR_SNAPSHOT_DAY)
        // Exact, not display-rounded: the stored string has to rebuild the
        // figure it folded in, and 0.88 prices a $11.99 charge 1.4 cents out.
        ->and(Rate::parse((string) $rates[0]['rate'])?->exact())->toBe(SPR_USD_RATE);
});

it('names that rate on the curve the run is read back into', function (): void {
    [$user, $accountId] = sprProjectedAt('2026-06-06');

    $dto = app(ForecastQuery::class)->forUser($accountId, 30, null, $user);

    expect($dto->conversion)->not->toBeNull()
        ->and($dto->conversion?->ratesUnrecorded)->toBeFalse()
        ->and($dto->conversion?->hasRates())->toBeTrue()
        ->and($dto->conversion?->asOf()?->toDateString())->toBe(SPR_SNAPSHOT_DAY)
        ->and($dto->conversion?->sourceLabel())->toBe(Lang::get('core::fx.source_bundled'))
        ->and($dto->conversion?->rates[0]->rateForDisplay())->toBe(SPR_USD_RATE);
});

// Staleness is a fact about the day the rate is READ. Stored as a boolean it
// would sit under a date that is still true saying a snapshot three months old
// is fresh.
it('ages the stored rate against the day it is read, not the day it was written', function (): void {
    [$user, $accountId] = sprProjectedAt('2026-06-06');

    expect(app(ForecastQuery::class)->forUser($accountId, 30, null, $user)->conversion?->isStale())->toBeFalse();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12')->startOfDay());

    $later = app(ForecastQuery::class)->forUser($accountId, 30, null, $user);

    expect($later->conversion?->isStale())->toBeTrue()
        ->and($later->conversion?->ageInDaysAt(CarbonImmutable::parse('2026-09-12')))->toBe(99);
});

it('draws the rate on the forecast page', function (): void {
    [$user, $accountId] = sprProjectedAt('2026-06-06');

    $view = app(ForecastChartView::class)->selectedAccount($accountId, 30, null, $user, 'EUR');

    expect($view['baselineConversion']?->rates[0]->rateForDisplay())->toBe(SPR_USD_RATE);

    $html = view('core::components.fx-disclosure', [
        'disclosure' => $view['baselineConversion'],
        'id' => 'forecast-baseline',
        'label' => 'Baseline',
        'onlineRates' => false,
        'flat' => false,
        'attributes' => new ComponentAttributeBag,
    ])->render();

    expect($html)->toContain(SPR_USD_RATE)
        ->and($html)->toContain(Lang::get('core::fx.source_bundled'))
        ->and($html)->not->toContain(Lang::get('core::fx.rates_not_recorded'));
});

// A projection whose every contribution was already in the account's currency
// converted nothing, records an empty set, and has nothing to say. It must not
// be confused with the run below, which converted at rates nobody kept.
it('records an empty set where the fold converted nothing, and discloses nothing', function (): void {
    [$user, $accountId] = sprProjectedAt('2026-06-06', withSeries: false);

    $block = sprStoredAccountBlock($user, $accountId);
    $dto = app(ForecastQuery::class)->forUser($accountId, 30, null, $user);

    expect($block[StoredRateSet::KEY])->toBe([])
        ->and($dto->conversion?->ratesUnrecorded)->toBeFalse()
        ->and($dto->conversion?->isEmpty())->toBeTrue();
});

it('says a run stored without its rates cannot name them, rather than saying nothing', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-06')->startOfDay());
    $user = sprUser();
    $accountId = sprAccount($user);
    sprLegacyRun($user, $accountId);

    $dto = app(ForecastQuery::class)->forUser($accountId, 30, null, $user);

    expect($dto->conversion?->ratesUnrecorded)->toBeTrue()
        ->and($dto->conversion?->hasRates())->toBeFalse()
        // Not empty, so the component renders it rather than skipping it.
        ->and($dto->conversion?->isEmpty())->toBeFalse();

    $html = view('core::components.fx-disclosure', [
        'disclosure' => $dto->conversion,
        'id' => 'forecast-baseline',
        'label' => 'Baseline',
        'onlineRates' => false,
        'flat' => false,
        'attributes' => new ComponentAttributeBag,
    ])->render();

    expect($html)->toContain(Lang::get('core::fx.rates_not_recorded'))
        ->and($html)->toContain('data-fx-rates-unrecorded="true"')
        // No panel, because there is nothing to put in one.
        ->and($html)->not->toContain('popovertarget');
});

it('replaces that state as soon as the next projection runs', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-06')->startOfDay());
    $user = sprUser();
    $accountId = sprAccount($user);
    sprDollarSeries($user, $accountId);
    sprLegacyRun($user, $accountId);

    expect(app(ForecastQuery::class)->forUser($accountId, 30, null, $user)->conversion?->ratesUnrecorded)->toBeTrue();

    Bus::dispatchSync(new ProjectForecastJob(userId: $user->id, scenarioId: null, horizonDays: 30));

    $reprojected = app(ForecastQuery::class)->forUser($accountId, 30, null, $user);

    expect($reprojected->conversion?->ratesUnrecorded)->toBeFalse()
        ->and($reprojected->conversion?->rates[0]->rateForDisplay())->toBe(SPR_USD_RATE);
});
