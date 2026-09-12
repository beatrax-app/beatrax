<?php

declare(strict_types=1);

/** @link ../../../../.docs/features/forecasting/forecast-corpus.md#shape */

use Carbon\CarbonImmutable;
use Modules\Forecasting\Tests\Support\ForecastCorpus;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsList;
use function PHPUnit\Framework\assertLessThanOrEqual;

/**
 * @return iterable<int, array{0: string, 1: string}>
 */
function forecastCorpusFixtures(): iterable
{
    foreach (ForecastCorpus::paths() as $path) {
        $name = basename($path, '.php');
        yield [$name, $path];
    }
}

it('produces exactly 11 fixture files', function (): void {
    expect(count(ForecastCorpus::paths()))->toBe(11);
});

it('every forecast-corpus fixture returns the documented shape', function (string $name, string $path): void {
    /** @var array<string, mixed> $fixture */
    $fixture = require $path;

    assertIsArray($fixture, sprintf('Fixture %s must return an associative array.', $name));
    assertArrayHasKey('accounts', $fixture, sprintf("Fixture %s must declare an 'accounts' key.", $name));
    assertArrayHasKey('series', $fixture, sprintf("Fixture %s must declare a 'series' key.", $name));
    assertArrayHasKey('expected', $fixture, sprintf("Fixture %s must declare an 'expected' key.", $name));

    /** @var mixed $accounts */
    $accounts = $fixture['accounts'];
    assertIsArray($accounts, sprintf("Fixture %s: 'accounts' must be a list.", $name));
    assertIsList($accounts, sprintf("Fixture %s: 'accounts' must be a 0-indexed list.", $name));
    assertGreaterThan(0, count($accounts), sprintf("Fixture %s: 'accounts' must be non-empty.", $name));

    $allowedKinds = ['bank', 'ics_card', 'paypal'];
    foreach ($accounts as $index => $account) {
        assertIsArray($account, sprintf('Fixture %s: account #%s must be an associative array.', $name, $index));
        foreach (['id', 'user_id', 'name', 'kind', 'default_currency'] as $required) {
            assertArrayHasKey(
                $required,
                $account,
                sprintf("Fixture %s: account #%s missing required key '%s'.", $name, $index, $required),
            );
        }
        assertContains(
            $account['kind'],
            $allowedKinds,
            sprintf('Fixture %s: account #%s.kind is not one of asn/ics_card/paypal.', $name, $index),
        );
    }

    /** @var mixed $series */
    $series = $fixture['series'];
    assertIsArray($series, sprintf("Fixture %s: 'series' must be a list.", $name));
    assertIsList($series, sprintf("Fixture %s: 'series' must be a 0-indexed list.", $name));

    $allowedDirections = ['expense', 'income'];
    $allowedStates = ['approved', 'pending', 'rejected', 'irregular'];
    $allowedCadences = ['monthly', 'weekly', 'quarterly', 'yearly'];
    foreach ($series as $index => $row) {
        assertIsArray($row, sprintf('Fixture %s: series #%s must be an associative array.', $name, $index));
        foreach (['id', 'user_id', 'name', 'cadence', 'direction', 'account_id', 'latest_amount_minor', 'latest_currency', 'variance_tolerance_percent', 'state', 'next_expected_date'] as $required) {
            assertArrayHasKey(
                $required,
                $row,
                sprintf("Fixture %s: series #%s missing required key '%s'.", $name, $index, $required),
            );
        }
        assertContains(
            $row['direction'],
            $allowedDirections,
            sprintf('Fixture %s: series #%s.direction is not one of expense/income.', $name, $index),
        );
        assertContains(
            $row['state'],
            $allowedStates,
            sprintf('Fixture %s: series #%s.state is not a recognised state.', $name, $index),
        );
        assertContains(
            $row['cadence'],
            $allowedCadences,
            sprintf('Fixture %s: series #%s.cadence is not one of monthly/weekly/quarterly/yearly.', $name, $index),
        );
    }

    /** @var mixed $expected */
    $expected = $fixture['expected'];
    assertIsArray($expected, sprintf("Fixture %s: 'expected' must be an associative array.", $name));
    assertArrayHasKey('projection', $expected, sprintf("Fixture %s: 'expected' must declare 'projection'.", $name));
    assertArrayHasKey('shortfalls', $expected, sprintf("Fixture %s: 'expected' must declare 'shortfalls'.", $name));

    /** @var mixed $projection */
    $projection = $expected['projection'];
    assertIsArray($projection, sprintf("Fixture %s: 'expected.projection' must be a list.", $name));
    assertIsList($projection, sprintf("Fixture %s: 'expected.projection' must be a 0-indexed list.", $name));

    $allowedHorizons = [30, 60, 90];
    foreach ($projection as $index => $point) {
        assertIsArray($point, sprintf('Fixture %s: projection #%s must be an associative array.', $name, $index));
        foreach (['horizon_days', 'account_id', 'date', 'low_minor', 'point_minor', 'high_minor', 'currency'] as $required) {
            assertArrayHasKey(
                $required,
                $point,
                sprintf("Fixture %s: projection #%s missing required key '%s'.", $name, $index, $required),
            );
        }
        assertContains(
            $point['horizon_days'],
            $allowedHorizons,
            sprintf('Fixture %s: projection #%s.horizon_days must be 30, 60, or 90.', $name, $index),
        );
        assertLessThanOrEqual(
            $point['point_minor'],
            $point['low_minor'],
            sprintf('Fixture %s: projection #%s low_minor must be <= point_minor.', $name, $index),
        );
        assertLessThanOrEqual(
            $point['high_minor'],
            $point['point_minor'],
            sprintf('Fixture %s: projection #%s point_minor must be <= high_minor.', $name, $index),
        );
    }

    /** @var mixed $shortfalls */
    $shortfalls = $expected['shortfalls'];
    assertIsArray($shortfalls, sprintf("Fixture %s: 'expected.shortfalls' must be a list.", $name));
    assertIsList($shortfalls, sprintf("Fixture %s: 'expected.shortfalls' must be a 0-indexed list.", $name));

    foreach ($shortfalls as $index => $shortfall) {
        assertIsArray($shortfall, sprintf('Fixture %s: shortfall #%s must be an associative array.', $name, $index));
        foreach (['account_id', 'starts_at', 'ends_at', 'lowest_balance_minor', 'currency', 'buffer_used_minor'] as $required) {
            assertArrayHasKey(
                $required,
                $shortfall,
                sprintf("Fixture %s: shortfall #%s missing required key '%s'.", $name, $index, $required),
            );
        }
    }
})->with(forecastCorpusFixtures());

it('dates every occurrence before the clock and every booked row after it', function (string $name, string $path): void {
    /** @var array{series: list<array<string, mixed>>, booked_rows?: list<array<string, mixed>>} $fixture */
    $fixture = require $path;

    $clock = ForecastCorpus::clock();
    $misdated = [];

    foreach ($fixture['series'] as $index => $row) {
        $occurrences = is_array($row['occurrences'] ?? null) ? $row['occurrences'] : [];
        foreach ($occurrences as $occurrence) {
            $date = (string) $occurrence['date'];
            if (! CarbonImmutable::parse($date)->lessThan($clock)) {
                $misdated[] = sprintf('series #%s occurrence %s', $index, $date);
            }
        }
    }

    $bookedRows = is_array($fixture['booked_rows'] ?? null) ? $fixture['booked_rows'] : [];
    foreach ($bookedRows as $index => $bookedRow) {
        $date = (string) (is_array($bookedRow) ? ($bookedRow['date'] ?? '') : '');
        if (! CarbonImmutable::parse($date)->greaterThan($clock)) {
            $misdated[] = sprintf('booked row #%s dated %s', $index, $date);
        }
    }

    expect($misdated)->toBe(
        [],
        sprintf('Fixture %s is dated against the wrong side of ', $name).ForecastCorpus::TODAY.': '
        .implode(', ', $misdated)
        .'. An occurrence is observed history and belongs before the clock; a row dated ahead of it is a booked row, which the projection reads as a certainty.',
    );
})->with(forecastCorpusFixtures());

it('buffer-crossing returns a non-empty shortfalls list with lowest_balance below buffer', function (): void {
    /** @var array{expected: array{shortfalls: list<array<string, int|string>>}} $fixture */
    $fixture = require ForecastCorpus::path('buffer-crossing');

    expect($fixture['expected']['shortfalls'])->not->toBe([]);
    /** @var array{lowest_balance_minor: int, buffer_used_minor: int} $first */
    $first = $fixture['expected']['shortfalls'][0];
    expect($first['lowest_balance_minor'])->toBeLessThan($first['buffer_used_minor']);
});

it('scenario-with-each-mutation-kind declares exactly five mutations covering all five kinds', function (): void {
    /** @var array{expected: array{scenarios: list<array{mutations: list<array{kind: string}>}>}} $fixture */
    $fixture = require ForecastCorpus::path('scenario-with-each-mutation-kind');

    expect($fixture['expected']['scenarios'])->toHaveCount(1);

    $mutations = $fixture['expected']['scenarios'][0]['mutations'];
    expect($mutations)->toHaveCount(5);

    $kinds = array_map(static fn (array $m): string => $m['kind'], $mutations);
    sort($kinds);
    expect($kinds)->toBe([
        'add_one_off',
        'add_recurring',
        'cancel_series',
        'change_series_amount',
        'shift_series_date',
    ]);
});

it('ics-settlement-chain declares the chain_state payload', function (): void {
    /** @var array{chain_state: array{next_settlement_date: string, next_settlement_amount_minor: int}} $fixture */
    $fixture = require ForecastCorpus::path('ics-settlement-chain');

    expect($fixture['chain_state'])->toHaveKey('next_settlement_date');
    expect($fixture['chain_state'])->toHaveKey('next_settlement_amount_minor');
});

// The fixture used to hand the pipeline a rate on the series row, which no
// production writer has ever put there. Six green tests sat on a path that
// raised on the first real dollar subscription.
it('fx-only-usd-subscription keeps a non-EUR series and supplies no rate of its own', function (): void {
    /** @var array{series: list<array<string, mixed>>} $fixture */
    $fixture = require ForecastCorpus::path('fx-only-usd-subscription');

    $usdSeries = array_filter($fixture['series'], static fn (array $s): bool => $s['latest_currency'] === 'USD');
    expect($usdSeries)->not->toBe([]);
    foreach ($fixture['series'] as $row) {
        expect($row)->not->toHaveKey('latest_fx_rate_used');
    }
});
