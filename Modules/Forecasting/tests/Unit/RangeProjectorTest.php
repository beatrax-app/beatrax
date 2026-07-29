<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\Forecasting\Internal\Pipeline\RangeProjector;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/*
 * Unit coverage for RangeProjector — the envelope-tier per-occurrence
 * emitter.
 *
 * Covers cadence walking (weekly, monthly, quarterly, yearly), sign-aware
 * low/point/high tuples for expense vs income series, and the
 * variance_tolerance_percent magnitude semantics. FX-series contributions
 * carry the original currency (USD); the daily fold handles conversion.
 */

beforeEach(function (): void {
    /** @var RangeProjector $projector */
    $projector = $this->app->make(RangeProjector::class);
    $this->projector = $projector;

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
        // The helper's overrides array still names cadences as strings, which
        // keeps each case reading as 'cadence' => 'weekly'; the DTO takes the
        // enum, so the conversion happens once here rather than in ten cases.
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
    // ±5% on magnitude 1199 → low_mag = round(1199 × 0.95) = 1139,
    //                         high_mag = round(1199 × 1.05) = 1259.
    // For a negative point: low_minor = -1259 (more negative outflow),
    //                       high_minor = -1139 (less negative outflow).
    expect($first->lowMinor)->toBe(-1259);
    expect($first->highMinor)->toBe(-1139);
    // Always: low <= point <= high when read as signed integers.
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

    // asOf=2026-05-19, horizonEnd=2026-06-18. Weekly steps from 05-22:
    // 05-22, 05-29, 06-05, 06-12 → 4 contributions before 06-19 falls
    // strictly outside the window.
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

    // 2026-03-15 is before asOf → skipped. 2026-04-15 also before → skipped.
    // 2026-05-15 also before → skipped. 2026-06-15 within horizon → included.
    expect($contribs)->toHaveCount(1);
    expect($contribs[0]->date->toDateString())->toBe('2026-06-15');
});
