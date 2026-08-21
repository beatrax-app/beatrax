<?php

declare(strict_types=1);

/** @link ../../../../.docs/features/forecasting/forecast-corpus.md#shape */

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
    $dir = __DIR__.'/../fixtures/forecast-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];
    sort($paths);
    foreach ($paths as $path) {
        $name = basename($path, '.php');
        yield [$name, $path];
    }
}

it('produces exactly 10 fixture files', function (): void {
    $dir = __DIR__.'/../fixtures/forecast-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];

    expect(count($paths))->toBe(10);
});

it('every forecast-corpus fixture returns the documented shape', function (string $name, string $path): void {
    /** @var array<string, mixed> $fixture */
    $fixture = require $path;

    assertIsArray($fixture, "Fixture {$name} must return an associative array.");
    assertArrayHasKey('accounts', $fixture, "Fixture {$name} must declare an 'accounts' key.");
    assertArrayHasKey('series', $fixture, "Fixture {$name} must declare a 'series' key.");
    assertArrayHasKey('expected', $fixture, "Fixture {$name} must declare an 'expected' key.");

    /** @var mixed $accounts */
    $accounts = $fixture['accounts'];
    assertIsArray($accounts, "Fixture {$name}: 'accounts' must be a list.");
    assertIsList($accounts, "Fixture {$name}: 'accounts' must be a 0-indexed list.");
    assertGreaterThan(0, count($accounts), "Fixture {$name}: 'accounts' must be non-empty.");

    $allowedKinds = ['bank', 'ics_card', 'paypal'];
    foreach ($accounts as $index => $account) {
        assertIsArray($account, "Fixture {$name}: account #{$index} must be an associative array.");
        foreach (['id', 'user_id', 'name', 'kind', 'default_currency'] as $required) {
            assertArrayHasKey(
                $required,
                $account,
                "Fixture {$name}: account #{$index} missing required key '{$required}'.",
            );
        }
        assertContains(
            $account['kind'],
            $allowedKinds,
            "Fixture {$name}: account #{$index}.kind is not one of asn/ics_card/paypal.",
        );
    }

    /** @var mixed $series */
    $series = $fixture['series'];
    assertIsArray($series, "Fixture {$name}: 'series' must be a list.");
    assertIsList($series, "Fixture {$name}: 'series' must be a 0-indexed list.");

    $allowedDirections = ['expense', 'income'];
    $allowedStates = ['approved', 'pending', 'rejected', 'irregular'];
    $allowedCadences = ['monthly', 'weekly', 'quarterly', 'yearly'];
    foreach ($series as $index => $row) {
        assertIsArray($row, "Fixture {$name}: series #{$index} must be an associative array.");
        foreach (['id', 'user_id', 'name', 'cadence', 'direction', 'account_id', 'latest_amount_minor', 'latest_currency', 'variance_tolerance_percent', 'state', 'next_expected_date'] as $required) {
            assertArrayHasKey(
                $required,
                $row,
                "Fixture {$name}: series #{$index} missing required key '{$required}'.",
            );
        }
        assertContains(
            $row['direction'],
            $allowedDirections,
            "Fixture {$name}: series #{$index}.direction is not one of expense/income.",
        );
        assertContains(
            $row['state'],
            $allowedStates,
            "Fixture {$name}: series #{$index}.state is not a recognised state.",
        );
        assertContains(
            $row['cadence'],
            $allowedCadences,
            "Fixture {$name}: series #{$index}.cadence is not one of monthly/weekly/quarterly/yearly.",
        );
    }

    /** @var mixed $expected */
    $expected = $fixture['expected'];
    assertIsArray($expected, "Fixture {$name}: 'expected' must be an associative array.");
    assertArrayHasKey('projection', $expected, "Fixture {$name}: 'expected' must declare 'projection'.");
    assertArrayHasKey('shortfalls', $expected, "Fixture {$name}: 'expected' must declare 'shortfalls'.");

    /** @var mixed $projection */
    $projection = $expected['projection'];
    assertIsArray($projection, "Fixture {$name}: 'expected.projection' must be a list.");
    assertIsList($projection, "Fixture {$name}: 'expected.projection' must be a 0-indexed list.");

    $allowedHorizons = [30, 60, 90];
    foreach ($projection as $index => $point) {
        assertIsArray($point, "Fixture {$name}: projection #{$index} must be an associative array.");
        foreach (['horizon_days', 'account_id', 'date', 'low_minor', 'point_minor', 'high_minor', 'currency'] as $required) {
            assertArrayHasKey(
                $required,
                $point,
                "Fixture {$name}: projection #{$index} missing required key '{$required}'.",
            );
        }
        assertContains(
            $point['horizon_days'],
            $allowedHorizons,
            "Fixture {$name}: projection #{$index}.horizon_days must be 30, 60, or 90.",
        );
        assertLessThanOrEqual(
            $point['point_minor'],
            $point['low_minor'],
            "Fixture {$name}: projection #{$index} low_minor must be <= point_minor.",
        );
        assertLessThanOrEqual(
            $point['high_minor'],
            $point['point_minor'],
            "Fixture {$name}: projection #{$index} point_minor must be <= high_minor.",
        );
    }

    /** @var mixed $shortfalls */
    $shortfalls = $expected['shortfalls'];
    assertIsArray($shortfalls, "Fixture {$name}: 'expected.shortfalls' must be a list.");
    assertIsList($shortfalls, "Fixture {$name}: 'expected.shortfalls' must be a 0-indexed list.");

    foreach ($shortfalls as $index => $shortfall) {
        assertIsArray($shortfall, "Fixture {$name}: shortfall #{$index} must be an associative array.");
        foreach (['account_id', 'starts_at', 'ends_at', 'lowest_balance_minor', 'currency', 'buffer_used_minor'] as $required) {
            assertArrayHasKey(
                $required,
                $shortfall,
                "Fixture {$name}: shortfall #{$index} missing required key '{$required}'.",
            );
        }
    }
})->with(forecastCorpusFixtures());

it('buffer-crossing returns a non-empty shortfalls list with lowest_balance below buffer', function (): void {
    /** @var array{expected: array{shortfalls: list<array<string, int|string>>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/forecast-corpus/buffer-crossing.php';

    expect($fixture['expected']['shortfalls'])->not->toBe([]);
    /** @var array{lowest_balance_minor: int, buffer_used_minor: int} $first */
    $first = $fixture['expected']['shortfalls'][0];
    expect($first['lowest_balance_minor'])->toBeLessThan($first['buffer_used_minor']);
});

it('scenario-with-each-mutation-kind declares exactly five mutations covering all five kinds', function (): void {
    /** @var array{expected: array{scenarios: list<array{mutations: list<array{kind: string}>}>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/forecast-corpus/scenario-with-each-mutation-kind.php';

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
    $fixture = require __DIR__.'/../fixtures/forecast-corpus/ics-settlement-chain.php';

    expect($fixture['chain_state'])->toHaveKey('next_settlement_date');
    expect($fixture['chain_state'])->toHaveKey('next_settlement_amount_minor');
});

it('fx-only-usd-subscription preserves the non-EUR primary currency on its series', function (): void {
    /** @var array{series: list<array{latest_currency: string, latest_fx_rate_used: ?float}>} $fixture */
    $fixture = require __DIR__.'/../fixtures/forecast-corpus/fx-only-usd-subscription.php';

    $usdSeries = array_filter($fixture['series'], static fn (array $s): bool => $s['latest_currency'] === 'USD');
    expect($usdSeries)->not->toBe([]);
    foreach ($usdSeries as $row) {
        expect($row['latest_fx_rate_used'])->not->toBeNull();
    }
});
