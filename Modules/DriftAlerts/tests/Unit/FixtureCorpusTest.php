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

    assertIsArray($fixture, sprintf('Fixture %s must return an associative array.', $name));
    assertArrayHasKey('transactions', $fixture, sprintf("Fixture %s must declare a 'transactions' key.", $name));
    assertArrayHasKey('expected', $fixture, sprintf("Fixture %s must declare an 'expected' key.", $name));

    /** @var mixed $transactions */
    $transactions = $fixture['transactions'];
    assertIsArray($transactions, sprintf("Fixture %s: 'transactions' must be a list.", $name));
    assertIsList($transactions, sprintf("Fixture %s: 'transactions' must be a 0-indexed list.", $name));

    /** @var mixed $expected */
    $expected = $fixture['expected'];
    assertIsArray($expected, sprintf("Fixture %s: 'expected' must be an associative array.", $name));
    assertArrayHasKey('alerts', $expected, sprintf("Fixture %s: 'expected' must declare an 'alerts' key.", $name));

    /** @var mixed $alerts */
    $alerts = $expected['alerts'];
    assertIsArray($alerts, sprintf("Fixture %s: 'expected.alerts' must be a list (may be empty).", $name));
    assertIsList($alerts, sprintf("Fixture %s: 'expected.alerts' must be a 0-indexed list.", $name));

    $allowedExpectedKeys = [
        'alerts', 'transitions', 'series_state', 'series_cadence', 'series_currency',
        'series_drift_threshold_percent', 'user_drift_threshold_percent',
    ];
    foreach (array_keys($expected) as $key) {
        assertContains(
            $key,
            $allowedExpectedKeys,
            sprintf("Fixture %s: 'expected' has unrecognised key '%s'.", $name, $key)
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
        assertIsArray($alert, sprintf('Fixture %s: alert #%s must be an associative array.', $name, $index));

        foreach (array_keys($alert) as $key) {
            assertContains(
                $key,
                $allowedAlertKeys,
                sprintf("Fixture %s: alert #%s has unrecognised key '%s'.", $name, $index, $key)
            );
        }

        if (array_key_exists('direction', $alert)) {
            assertContains(
                $alert['direction'],
                $allowedDirections,
                sprintf('Fixture %s: alert #%s.direction is not one of expense/income.', $name, $index)
            );
        }

        if (array_key_exists('state', $alert)) {
            assertContains(
                $alert['state'],
                $allowedStates,
                sprintf('Fixture %s: alert #%s.state is not a recognised state.', $name, $index)
            );
        }

        if (array_key_exists('threshold_source', $alert)) {
            assertContains(
                $alert['threshold_source'],
                $allowedThresholdSources,
                sprintf('Fixture %s: alert #%s.threshold_source must be default, global, or series_override.', $name, $index)
            );
        }
    }
})->with(driftCorpusFixtures());

// Whether the numbers below are the ones the evaluator actually produces is
// settled by replaying each fixture through it, in
// tests/Feature/TheDriftCorpusWasNeverFedToTheEvaluatorTest.php. What is left
// here is the fixture vocabulary, which that driver reads.
