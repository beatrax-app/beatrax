<?php

declare(strict_types=1);

use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// The scale a currency counts its minor units at was read five times and
// turned into a decimal count three times. Five readers of one fact drift, and
// the one that drifts renders a yen at a hundredth of itself.
// @link ../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#where-the-scale-comes-from

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function minorUnitScaleRepoRoot(): string
{
    return dirname((string) realpath(base_path('Modules')));
}

/**
 * @return array<string, string> repo-relative path => contents
 */
function minorUnitScaleSources(): array
{
    $root = minorUnitScaleRepoRoot();

    $sources = [];
    foreach (['Modules', 'app', 'database', 'config', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $sources;
}

it('keeps the fallback to a hundred inside the seam and the value object that defines it', function (): void {
    $seam = 'Modules/Ledger/Public/ValueObjects/CurrencyScale.php';
    $definition = 'Modules/Ledger/Public/ValueObjects/Money.php';

    $readers = [];
    foreach (minorUnitScaleSources() as $path => $source) {
        if ($path === $seam || $path === $definition) {
            continue;
        }
        if (str_contains($source, 'minorUnitsPerMajor() ?? Money::MINOR_UNITS_PER_MAJOR')) {
            $readers[] = $path;
        }
    }

    expect($readers)->toBe([]);
});

it('writes the log10 that turns a scale into a decimal count exactly once', function (): void {
    $seam = 'Modules/Ledger/Public/ValueObjects/CurrencyScale.php';

    $writers = [];
    foreach (minorUnitScaleSources() as $path => $source) {
        if ($path !== $seam && str_contains($source, 'log10')) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe([]);
});

it('answers the same scale and decimal count for a zero-, two- and three-decimal currency', function (string $code, int $scale, int $decimals): void {
    expect(CurrencyScale::minorUnitsPerMajor($code))->toBe($scale)
        ->and(CurrencyScale::decimals($code))->toBe($decimals)
        ->and(CurrencyScale::decimalsOfScale($scale))->toBe($decimals)
        ->and(MoneyInput::decimalPlaces($code))->toBe($decimals);
})->with([
    ['JPY', 1, 0],
    ['EUR', 100, 2],
    ['USD', 100, 2],
    ['KWD', 1000, 3],
]);

it('falls back to the two-decimal assumption for no code and for a code no currency table knows', function (): void {
    expect(CurrencyScale::minorUnitsPerMajor(null))->toBe(Money::MINOR_UNITS_PER_MAJOR)
        ->and(CurrencyScale::decimals(null))->toBe(2)
        ->and(CurrencyScale::minorUnitsPerMajor('ZZZ'))->toBe(Money::MINOR_UNITS_PER_MAJOR)
        ->and(CurrencyScale::decimals('ZZZ'))->toBe(2);
});
