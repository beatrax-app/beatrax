<?php

declare(strict_types=1);

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsList;

/**
 * @return iterable<int, array{0: string, 1: string}> one `[$name, $absolutePath]` pair per corpus fixture
 */
function anomalyCorpusFixtures(): iterable
{
    $dir = __DIR__.'/../fixtures/anomaly-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];
    sort($paths);
    foreach ($paths as $path) {
        $name = basename($path, '.php');
        yield [$name, $path];
    }
}

it('ships every one of the nine named anomaly-corpus fixtures', function (): void {
    $dir = __DIR__.'/../fixtures/anomaly-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];
    $names = array_map(static fn (string $p): string => basename($p, '.php'), $paths);

    $expected = [
        'duplicate-in-window',
        'duplicate-recurring-excluded',
        'first-time-large',
        'large-above',
        'large-below',
        'mixed-currency',
        'sub-floor-ignored',
        'suppressed-skip',
        'thin-history-category-fallback',
    ];

    sort($names);
    expect($names)->toBe($expected);
    expect(count($paths))->toBeGreaterThanOrEqual(9);
});

it('every anomaly-corpus fixture returns the documented shape', function (string $name, string $path): void {
    /** @var array<string, mixed> $fixture */
    $fixture = require $path;

    assertIsArray($fixture, "Fixture {$name} must return an associative array.");
    assertArrayHasKey('settings', $fixture, "Fixture {$name} must declare a 'settings' key.");
    assertArrayHasKey('history', $fixture, "Fixture {$name} must declare a 'history' key.");
    assertArrayHasKey('transaction', $fixture, "Fixture {$name} must declare a 'transaction' key.");
    assertArrayHasKey('expected', $fixture, "Fixture {$name} must declare an 'expected' key.");

    /** @var mixed $settings */
    $settings = $fixture['settings'];
    assertIsArray($settings, "Fixture {$name}: 'settings' must be an associative array.");
    assertArrayHasKey('anomaly_sensitivity_percent', $settings, "Fixture {$name}: settings must carry anomaly_sensitivity_percent.");
    assertArrayHasKey('anomaly_min_amount_minor', $settings, "Fixture {$name}: settings must carry anomaly_min_amount_minor.");

    /** @var mixed $history */
    $history = $fixture['history'];
    assertIsArray($history, "Fixture {$name}: 'history' must be a list.");
    assertIsList($history, "Fixture {$name}: 'history' must be a 0-indexed list.");

    /** @var mixed $transaction */
    $transaction = $fixture['transaction'];
    assertIsArray($transaction, "Fixture {$name}: 'transaction' must be an associative array.");
    assertArrayHasKey('counterparty', $transaction, "Fixture {$name}: transaction must carry a counterparty.");
    assertArrayHasKey('amount_minor', $transaction, "Fixture {$name}: transaction must carry an amount_minor.");
    assertArrayHasKey('direction', $transaction, "Fixture {$name}: transaction must carry a direction.");

    /** @var mixed $direction */
    $direction = $transaction['direction'];
    assertContains($direction, ['expense', 'income'], "Fixture {$name}: transaction.direction must be expense or income.");

    /** @var mixed $expected */
    $expected = $fixture['expected'];
    assertIsArray($expected, "Fixture {$name}: 'expected' must be an associative array.");
    assertArrayHasKey('reasons', $expected, "Fixture {$name}: 'expected' must declare a 'reasons' key.");

    /** @var mixed $reasons */
    $reasons = $expected['reasons'];
    assertIsArray($reasons, "Fixture {$name}: 'expected.reasons' must be a list (may be empty).");
    assertIsList($reasons, "Fixture {$name}: 'expected.reasons' must be a 0-indexed list.");

    $allowedReasons = ['large', 'first_time', 'duplicate'];
    foreach ($reasons as $reason) {
        assertContains($reason, $allowedReasons, "Fixture {$name}: '{$reason}' is not a recognised detector reason.");
    }
})->with(anomalyCorpusFixtures());

it('large-above expects exactly the large reason', function (): void {
    /** @var array{expected: array{reasons: list<string>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/anomaly-corpus/large-above.php';
    expect($fixture['expected']['reasons'])->toBe(['large']);
});

it('first-time-large carries both large and first_time', function (): void {
    /** @var array{expected: array{reasons: list<string>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/anomaly-corpus/first-time-large.php';
    expect($fixture['expected']['reasons'])->toContain('large');
    expect($fixture['expected']['reasons'])->toContain('first_time');
});

it('sub-floor-ignored and large-below and mixed-currency fire zero reasons', function (string $fixtureName): void {
    /** @var array{expected: array{reasons: list<string>}} $fixture */
    $fixture = require __DIR__.'/../fixtures/anomaly-corpus/'.$fixtureName.'.php';
    expect($fixture['expected']['reasons'])->toBe([]);
})->with([
    'sub-floor-ignored',
    'large-below',
    'mixed-currency',
    'duplicate-recurring-excluded',
]);

it('suppressed-skip declares a matching suppression rule and is marked suppressed', function (): void {
    /** @var array{suppression_rules: list<array<string, mixed>>, expected: array{reasons: list<string>, suppressed?: bool}} $fixture */
    $fixture = require __DIR__.'/../fixtures/anomaly-corpus/suppressed-skip.php';

    expect($fixture['suppression_rules'])->toHaveCount(1);
    expect($fixture['expected']['reasons'])->toBe([]);
    expect($fixture['expected']['suppressed'] ?? false)->toBeTrue();
});
