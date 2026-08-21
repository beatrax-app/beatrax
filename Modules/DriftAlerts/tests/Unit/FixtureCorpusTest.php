<?php

declare(strict_types=1);

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsList;

/**
 * @return iterable<int, array{0: string, 1: string}>
 */
function driftCorpusFixtures(): iterable
{
    $dir = __DIR__.'/../fixtures/drift-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];
    sort($paths);
    foreach ($paths as $path) {
        $name = basename($path, '.php');
        yield [$name, $path];
    }
}

it('produces exactly 24 fixture files', function (): void {
    $dir = __DIR__.'/../fixtures/drift-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];

    expect(count($paths))->toBe(24);
});

it('every drift-corpus fixture returns the documented shape', function (string $name, string $path): void {
    /** @var array<string, mixed> $fixture */
    $fixture = require $path;

    assertIsArray($fixture, "Fixture {$name} must return an associative array.");
    assertArrayHasKey('transactions', $fixture, "Fixture {$name} must declare a 'transactions' key.");
    assertArrayHasKey('expected', $fixture, "Fixture {$name} must declare an 'expected' key.");

    /** @var mixed $transactions */
    $transactions = $fixture['transactions'];
    assertIsArray($transactions, "Fixture {$name}: 'transactions' must be a list.");
    assertIsList($transactions, "Fixture {$name}: 'transactions' must be a 0-indexed list.");

    /** @var mixed $expected */
    $expected = $fixture['expected'];
    assertIsArray($expected, "Fixture {$name}: 'expected' must be an associative array.");
    assertArrayHasKey('alerts', $expected, "Fixture {$name}: 'expected' must declare an 'alerts' key.");

    /** @var mixed $alerts */
    $alerts = $expected['alerts'];
    assertIsArray($alerts, "Fixture {$name}: 'expected.alerts' must be a list (may be empty).");
    assertIsList($alerts, "Fixture {$name}: 'expected.alerts' must be a 0-indexed list.");

    $allowedAlertKeys = [
        'state', 'direction', 'baseline_amount_minor', 'latest_amount_minor',
        'delta_minor', 'annualized_impact_minor', 'threshold_percent_used',
        'threshold_source', 'currency', 'snoozed_until', 'actioned_at',
    ];
    $allowedDirections = ['expense', 'income'];
    $allowedStates = ['open', 'acknowledged', 'snoozed', 'dismissed_cancelled'];
    $allowedThresholdSources = ['default', 'global', 'series_override'];

    foreach ($alerts as $index => $alert) {
        assertIsArray($alert, "Fixture {$name}: alert #{$index} must be an associative array.");

        foreach (array_keys($alert) as $key) {
            assertContains(
                $key,
                $allowedAlertKeys,
                "Fixture {$name}: alert #{$index} has unrecognised key '{$key}'."
            );
        }

        if (array_key_exists('direction', $alert)) {
            assertContains(
                $alert['direction'],
                $allowedDirections,
                "Fixture {$name}: alert #{$index}.direction is not one of expense/income."
            );
        }

        if (array_key_exists('state', $alert)) {
            assertContains(
                $alert['state'],
                $allowedStates,
                "Fixture {$name}: alert #{$index}.state is not a recognised state."
            );
        }

        if (array_key_exists('threshold_source', $alert)) {
            assertContains(
                $alert['threshold_source'],
                $allowedThresholdSources,
                "Fixture {$name}: alert #{$index}.threshold_source must be default, global, or series_override."
            );
        }
    }
})->with(driftCorpusFixtures());

it('large-drift-above-threshold encodes the canonical Spotify-15% math', function (): void {
    /** @var array{expected: array{alerts: list<array<string, mixed>>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/drift-corpus/large-drift-above-threshold.php';

    expect($fixture['expected']['alerts'])->toHaveCount(1);
    expect($fixture['expected']['alerts'][0]['delta_minor'])->toBe(-150);
    expect($fixture['expected']['alerts'][0]['annualized_impact_minor'])->toBe(-1800);
});

it('fx-only-swing fires zero alerts (FX-exclusion invariant)', function (): void {
    /** @var array{expected: array{alerts: list<array<string, mixed>>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/drift-corpus/fx-only-swing.php';

    expect($fixture['expected']['alerts'])->toBe([]);
});

it('weekly-cadence annualizes via the x52 multiplier', function (): void {
    /** @var array{expected: array{alerts: list<array<string, mixed>>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/drift-corpus/weekly-cadence.php';

    expect($fixture['expected']['alerts'])->toHaveCount(1);

    /** @var array{delta_minor: int, annualized_impact_minor: int} $alert */
    $alert = $fixture['expected']['alerts'][0];
    expect($alert['annualized_impact_minor'])->toBe($alert['delta_minor'] * 52);
});

it('multi-drift queues exactly two open alerts (queue-all-as-open invariant)', function (): void {
    /** @var array{expected: array{alerts: list<array<string, mixed>>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/drift-corpus/multi-drift.php';

    expect($fixture['expected']['alerts'])->toHaveCount(2);
});
