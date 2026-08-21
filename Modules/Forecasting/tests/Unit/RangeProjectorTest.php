<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\Forecasting\Internal\Pipeline\RangeProjector;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/projection-math.md */
beforeEach(function (): void {
    /** @var RangeProjector $projector */
    $projector = $this->app->make(RangeProjector::class);
    $this->projector = $projector;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'projector',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function rpDtoSeries(array $overrides = []): RecurringSeriesDto
{
    $base = [
        'seriesId' => 101,
        'direction' => 'expense',
        'detectedName' => 'Netflix',
        'displayNameOverride' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latestAmount' => Money::ofMinor(-1199, 'EUR'),
        'eurEquivalent' => null,
        'monthlyEquivalent' => Money::ofMinor(-1199, 'EUR'),
        'latestFundingChainLinkId' => null,
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-25'),
        'nextExpectedConfidenceLow' => false,
        'varianceTolerancePercent' => 5,
        'snoozedUntil' => null,
        'latestFxRateUsed' => null,
    ];
    $merged = array_merge($base, $overrides);

    return new RecurringSeriesDto(
        seriesId: $merged['seriesId'],
        direction: $merged['direction'],
        detectedName: $merged['detectedName'],
        displayNameOverride: $merged['displayNameOverride'],
        state: $merged['state'],
        cadence: SeriesCadence::from($merged['cadence']),
        latestAmount: $merged['latestAmount'],
        eurEquivalent: $merged['eurEquivalent'],
        monthlyEquivalent: $merged['monthlyEquivalent'],
        latestFundingChainLinkId: $merged['latestFundingChainLinkId'],
        nextExpectedAt: $merged['nextExpectedAt'],
        nextExpectedConfidenceLow: $merged['nextExpectedConfidenceLow'],
        varianceTolerancePercent: $merged['varianceTolerancePercent'],
        snoozedUntil: $merged['snoozedUntil'],
        latestFxRateUsed: $merged['latestFxRateUsed'],
    );
}

/**
 * @param  list<int>  $observedAmounts
 */
function rpSeedPercentileSeries(DatabaseManager $db, int $userId, array $observedAmounts): int
{
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => 'Groceries',
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -9999, 'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -9999, 'variance_tolerance_percent' => 50,
        'cluster_key' => 'groceries|monthly|EUR|'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Bank', 'slug' => 'rp-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00RP'.strtoupper(bin2hex(random_bytes(5))), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/rp.csv',
        'sha256' => hash('sha256', 'rp-'.bin2hex(random_bytes(6))), 'uploaded_at' => '2026-05-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    foreach ($observedAmounts as $i => $amountMinor) {
        $day = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
        $txId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'rp-tx-'.$i.'-'.bin2hex(random_bytes(6))),
            'posted_at' => '2026-05-'.$day, 'booked_at' => '2026-05-'.$day.' 00:00:00', 'value_date' => '2026-05-'.$day,
            'amount_minor' => $amountMinor, 'currency' => 'EUR', 'settled_amount_minor' => $amountMinor, 'settled_currency' => 'EUR',
            'counterparty_normalized' => 'groceries', 'counterparty_name' => 'GROCERIES', 'normalization_version' => 1,
            'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $i,
            'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
        ]);
        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
            'observed_at' => '2026-05-'.$day, 'observed_amount_minor' => $amountMinor, 'observed_currency' => 'EUR',
            'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
        ]);
    }

    return $seriesId;
}

it('routes a wide-variance series with enough occurrences through the percentile tier and jitters the band', function (): void {
    // 50% tolerance clears the 40% bar and six occurrences clear the 6-sample
    // minimum, which is what escalates project() to the percentile tier.
    $seriesId = rpSeedPercentileSeries($this->db, $this->user->id, [-1000, -1100, -1200, -1300, -1400, -1500]);

    $series = rpDtoSeries([
        'seriesId' => $seriesId,
        'cadence' => 'monthly',
        'varianceTolerancePercent' => 50,
        'latestAmount' => Money::ofMinor(-9999, 'EUR'),
        'monthlyEquivalent' => Money::ofMinor(-9999, 'EUR'),
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-25'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->project($series, accountId: 7, asOf: $asOf, horizonDays: 30, user: $this->user);

    // The one in-horizon occurrence is smeared over a ±3-day window, so
    // 2 × 3 + 1 replicas centred on 2026-05-25.
    expect($contribs)->toHaveCount(7);
    $dates = array_map(static fn ($c): string => $c->date->toDateString(), $contribs);
    expect(min($dates))->toBe('2026-05-22')
        ->and(max($dates))->toBe('2026-05-28');
});

it('emits one monthly expense contribution inside a 30-day horizon with sign-aware low/high', function (): void {
    $series = rpDtoSeries([
        'latestAmount' => Money::ofMinor(-1199, 'EUR'),
        'monthlyEquivalent' => Money::ofMinor(-1199, 'EUR'),
        'varianceTolerancePercent' => 5,
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-25'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 30, user: $this->user);

    expect($contribs)->toHaveCount(1);
    $first = $contribs[0];
    expect($first)->toBeInstanceOf(ForecastContribution::class);
    expect($first->date->toDateString())->toBe('2026-05-25');
    expect($first->pointMinor)->toBe(-1199);
    // ±5% is applied to the magnitude, then re-signed: the bigger outflow
    // (-1259) is the low end, not the high one.
    expect($first->lowMinor)->toBe(-1259);
    expect($first->highMinor)->toBe(-1139);
    expect($first->lowMinor)->toBeLessThanOrEqual($first->pointMinor);
    expect($first->pointMinor)->toBeLessThanOrEqual($first->highMinor);
});

it('emits sign-aware low < point < high for an income series', function (): void {
    $series = rpDtoSeries([
        'direction' => 'income',
        'latestAmount' => Money::ofMinor(250000, 'EUR'),
        'monthlyEquivalent' => Money::ofMinor(250000, 'EUR'),
        'varianceTolerancePercent' => 10,
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-25'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 2, asOf: $asOf, horizonDays: 30, user: $this->user);

    expect($contribs)->toHaveCount(1);
    $first = $contribs[0];
    expect($first->pointMinor)->toBe(250000);
    // ±10% on 250000 → low = 225000, high = 275000.
    expect($first->lowMinor)->toBe(225000);
    expect($first->highMinor)->toBe(275000);
    expect($first->lowMinor)->toBeLessThan($first->pointMinor);
    expect($first->pointMinor)->toBeLessThan($first->highMinor);
});

it('walks weekly cadence to emit ~4 contributions in 30 days', function (): void {
    $series = rpDtoSeries([
        'cadence' => 'weekly',
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-22'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 30, user: $this->user);

    // Weekly steps from 05-22 reach 06-12; the next one (06-19) falls past the
    // 06-18 horizon end.
    expect($contribs)->toHaveCount(4);
    expect($contribs[0]->date->toDateString())->toBe('2026-05-22');
    expect($contribs[3]->date->toDateString())->toBe('2026-06-12');
});

it('walks quarterly cadence to emit 1 contribution in 90 days', function (): void {
    $series = rpDtoSeries([
        'cadence' => 'quarterly',
        'nextExpectedAt' => CarbonImmutable::parse('2026-06-15'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 90, user: $this->user);

    expect($contribs)->toHaveCount(1);
    expect($contribs[0]->date->toDateString())->toBe('2026-06-15');
});

it('walks yearly cadence to emit 0 contributions in a 30-day window', function (): void {
    $series = rpDtoSeries([
        'cadence' => 'yearly',
        'nextExpectedAt' => CarbonImmutable::parse('2027-01-15'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 30, user: $this->user);

    expect($contribs)->toBe([]);
});

it('emits no contributions for an irregular cadence series', function (): void {
    $series = rpDtoSeries([
        'cadence' => 'irregular',
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-22'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 30, user: $this->user);

    expect($contribs)->toBe([]);
});

it('emits no contributions when nextExpectedAt is null', function (): void {
    $series = rpDtoSeries([
        'nextExpectedAt' => null,
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 30, user: $this->user);

    expect($contribs)->toBe([]);
});

it('carries the contribution currency + stored fxRateUsed for FX series (USD)', function (): void {
    $series = rpDtoSeries([
        'latestAmount' => Money::ofMinor(-599, 'USD'),
        'nextExpectedAt' => CarbonImmutable::parse('2026-05-25'),
        'latestFxRateUsed' => 0.9050,
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 3, asOf: $asOf, horizonDays: 30, user: $this->user);

    expect($contribs)->toHaveCount(1);
    expect($contribs[0]->currency)->toBe('USD');
    expect($contribs[0]->fxRateUsed)->toBe(0.9050);
});

it('skips occurrences strictly before asOf even when nextExpectedAt is in the past', function (): void {
    $series = rpDtoSeries([
        'cadence' => 'monthly',
        'nextExpectedAt' => CarbonImmutable::parse('2026-03-15'),
    ]);
    $asOf = CarbonImmutable::parse('2026-05-19');

    $contribs = $this->projector->envelope($series, accountId: 1, asOf: $asOf, horizonDays: 30, user: $this->user);

    // The walk starts in the past: 03-15, 04-15 and 05-15 all fall before asOf.
    expect($contribs)->toHaveCount(1);
    expect($contribs[0]->date->toDateString())->toBe('2026-06-15');
});
