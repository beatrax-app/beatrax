<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Enums\AnomalyDetector;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsList;
use function PHPUnit\Framework\assertTrue;

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

it('ships every one of the named anomaly-corpus fixtures', function (): void {
    $dir = __DIR__.'/../fixtures/anomaly-corpus';
    /** @var list<string> $paths */
    $paths = glob($dir.'/*.php') ?: [];
    $names = array_map(static fn (string $p): string => basename($p, '.php'), $paths);

    $expected = [
        'duplicate-in-window',
        'duplicate-newest-first-import',
        'duplicate-recurring-excluded',
        'duplicate-three-siblings',
        'first-time-large',
        'income-spike-above',
        'large-above',
        'large-below',
        'mixed-currency',
        'sub-floor-ignored',
        'suppressed-skip',
        'thin-history-category-fallback',
    ];

    sort($names);
    expect($names)->toBe($expected);
    expect(count($paths))->toBe(count($expected));
});

it('every anomaly-corpus fixture returns the documented shape', function (string $name, string $path): void {
    /** @var array<string, mixed> $fixture */
    $fixture = require $path;

    assertIsArray($fixture, sprintf('Fixture %s must return an associative array.', $name));
    assertArrayHasKey('settings', $fixture, sprintf("Fixture %s must declare a 'settings' key.", $name));
    assertTrue(
        array_key_exists('history', $fixture) || array_key_exists('history_after', $fixture),
        sprintf("Fixture %s must declare a 'history' or 'history_after' key.", $name),
    );
    assertArrayHasKey('transaction', $fixture, sprintf("Fixture %s must declare a 'transaction' key.", $name));
    assertArrayHasKey('expected', $fixture, sprintf("Fixture %s must declare an 'expected' key.", $name));

    /** @var mixed $settings */
    $settings = $fixture['settings'];
    assertIsArray($settings, sprintf("Fixture %s: 'settings' must be an associative array.", $name));
    assertArrayHasKey('anomaly_sensitivity_percent', $settings, sprintf('Fixture %s: settings must carry anomaly_sensitivity_percent.', $name));
    assertArrayHasKey('anomaly_min_amount_minor', $settings, sprintf('Fixture %s: settings must carry anomaly_min_amount_minor.', $name));

    foreach (['history', 'history_after'] as $key) {
        if (! array_key_exists($key, $fixture)) {
            continue;
        }
        /** @var mixed $rows */
        $rows = $fixture[$key];
        assertIsArray($rows, sprintf("Fixture %s: '%s' must be a list.", $name, $key));
        assertIsList($rows, sprintf("Fixture %s: '%s' must be a 0-indexed list.", $name, $key));
    }

    /** @var mixed $transaction */
    $transaction = $fixture['transaction'];
    assertIsArray($transaction, sprintf("Fixture %s: 'transaction' must be an associative array.", $name));
    assertArrayHasKey('counterparty', $transaction, sprintf('Fixture %s: transaction must carry a counterparty.', $name));
    assertArrayHasKey('amount_minor', $transaction, sprintf('Fixture %s: transaction must carry an amount_minor.', $name));
    assertArrayHasKey('direction', $transaction, sprintf('Fixture %s: transaction must carry a direction.', $name));

    /** @var mixed $direction */
    $direction = $transaction['direction'];
    assertContains($direction, ['expense', 'income'], sprintf('Fixture %s: transaction.direction must be expense or income.', $name));

    /** @var mixed $expected */
    $expected = $fixture['expected'];
    assertIsArray($expected, sprintf("Fixture %s: 'expected' must be an associative array.", $name));
    assertArrayHasKey('reasons', $expected, sprintf("Fixture %s: 'expected' must declare a 'reasons' key.", $name));

    /** @var mixed $reasons */
    $reasons = $expected['reasons'];
    assertIsArray($reasons, sprintf("Fixture %s: 'expected.reasons' must be a list (may be empty).", $name));
    assertIsList($reasons, sprintf("Fixture %s: 'expected.reasons' must be a 0-indexed list.", $name));

    $allowedReasons = AnomalyDetector::values();
    foreach ($reasons as $reason) {
        assertContains($reason, $allowedReasons, sprintf("Fixture %s: '%s' is not a recognised detector reason.", $name, $reason));
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
