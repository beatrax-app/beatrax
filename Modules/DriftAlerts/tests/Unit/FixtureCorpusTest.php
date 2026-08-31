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

it('produces exactly 29 fixture files', function (): void {
    $dir = __DIR__.'/../fixtures/drift-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];

    expect(count($paths))->toBe(29);
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

    $allowedExpectedKeys = [
        'alerts', 'transitions', 'series_state', 'series_cadence', 'series_currency',
        'series_drift_threshold_percent', 'user_drift_threshold_percent',
    ];
    foreach (array_keys($expected) as $key) {
        assertContains(
            $key,
            $allowedExpectedKeys,
            "Fixture {$name}: 'expected' has unrecognised key '{$key}'."
        );
    }

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

// Whether the numbers below are the ones the evaluator actually produces is
// settled by replaying each fixture through it, in
// tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php. What is left
// here is the fixture vocabulary, which that driver reads.
