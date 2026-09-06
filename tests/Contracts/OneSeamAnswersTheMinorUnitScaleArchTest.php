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
    // Every root that ships PHP or Blade. "Exactly once" is a claim about the
    // whole application, and a view or a release script turning a scale into a
    // decimal count is the same second reader as a service doing it.
    foreach (['Modules', 'app', 'database', 'config', 'routes', 'resources', 'bootstrap', 'lang', 'scripts'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }
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

const MINOR_UNIT_SCALE_SEAM = 'Modules/Ledger/Public/ValueObjects/CurrencyScale.php';

it('keeps the fallback to a hundred inside the seam that answers the scale', function (): void {
    $sources = minorUnitScaleSources();

    expect(count($sources))->toBeGreaterThan(2000, 'The walk read almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    $readers = [];
    foreach ($sources as $path => $source) {
        if ($path === MINOR_UNIT_SCALE_SEAM) {
            continue;
        }
        if (str_contains($source, 'minorUnitsPerMajor() ?? Money::MINOR_UNITS_PER_MAJOR')) {
            $readers[] = $path;
        }
    }

    expect($readers)->toBe(
        [],
        'CurrencyScale::minorUnitsPerMajor() is the one place the two-decimal assumption is made for a '
        .'currency Brick does not know. A second site spelling the fallback out is a second reader of one '
        ."fact, and the one that drifts renders a yen at a hundredth of itself. Offenders:\n  "
        .implode("\n  ", $readers),
    );
});

it('writes the log10 that turns a scale into a decimal count exactly once', function (): void {
    $sources = minorUnitScaleSources();

    expect(count($sources))->toBeGreaterThan(2000, 'The walk read almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    $writers = [];
    foreach ($sources as $path => $source) {
        if ($path !== MINOR_UNIT_SCALE_SEAM && str_contains($source, 'log10')) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe(
        [],
        'CurrencyScale::decimalsOfScale() is the one conversion from a minor-unit scale to a decimal count. '
        ."Three classes each wrote their own log10 once already, and they drifted. Offenders:\n  "
        .implode("\n  ", $writers),
    );
});

// The two rules above are exemptions with one file named in each. A pin that
// excuses nothing is worse than no pin: it reads as considered.
it('still holds the seam to both of the things its exemption was granted for', function (): void {
    $seam = minorUnitScaleSources()[MINOR_UNIT_SCALE_SEAM] ?? null;

    expect($seam)->toBeString(MINOR_UNIT_SCALE_SEAM.' is exempted from both rules above and the walk no longer reaches it.');

    expect(str_contains((string) $seam, 'minorUnitsPerMajor() ?? Money::MINOR_UNITS_PER_MAJOR'))
        ->toBeTrue('The seam no longer makes the two-decimal fallback, so its exemption from the first rule excuses nothing. Delete it, or move it to wherever the fallback went.')
        ->and(str_contains((string) $seam, 'log10'))
        ->toBeTrue('The seam no longer turns a scale into a decimal count, so its exemption from the second rule excuses nothing. Delete it, or move it to wherever the conversion went.');
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
