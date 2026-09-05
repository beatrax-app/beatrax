<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Two of the resolver's five reconcilers compare names, with `===` on strings,
// and the other two compare money. A money field added by copying the string
// branch beside it reads correctly and decides on a rendering: whether a cell
// changed would then depend on the reader's locale, and on the phone, where
// every non-English locale falls back to a different formatter, on the build.

const MERGE_MONEY_RESOLVER = 'Modules/Migration/Internal/Pipeline/ThreeWayMergeResolver.php';

/** @var list<string> */
const MERGE_MONEY_PIPELINE = [
    'Modules/Migration/Internal/Pipeline/ThreeWayMergeResolver.php',
    'Modules/Migration/Internal/Pipeline/MergeApplier.php',
    'Modules/Migration/Internal/Pipeline/MergeDecision.php',
    'Modules/Migration/Internal/Pipeline/EntityChangeApplier.php',
    'Modules/Migration/Internal/Pipeline/ConflictValueCodec.php',
    'Modules/Migration/Internal/Pipeline/ConflictRow.php',
];

const MERGE_MONEY_RAW_COMPARISON_PATTERN = '/\$\w*[Mm]inor\b\s*(?:===|!==|==|!=)|(?:===|!==|==|!=)\s*\$\w*[Mm]inor\b/';

const MERGE_MONEY_RENDERING_PATTERN = '/->format\(\s*\)|\bnumber_format\s*\(|\bFmt::|\bMoneyText::/';

function mergeMoneyStrippedSource(string $relativePath): string
{
    $absolute = base_path($relativePath);
    expect(is_file($absolute))->toBeTrue("Expected {$relativePath} to exist.");

    return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($absolute))
        ?? (string) file_get_contents($absolute);
}

it('asks a money value whether the cell changed, never the operands beside it', function (): void {
    $source = mergeMoneyStrippedSource(MERGE_MONEY_RESOLVER);

    // Counted first: a resolver with no money comparison left in it would
    // report no raw one either, which is what a correct file reports.
    expect(PatternScan::count('/self::moneyEquals\(/', $source))->toBeGreaterThanOrEqual(4);

    $raw = PatternScan::all(MERGE_MONEY_RAW_COMPARISON_PATTERN, $source)[0] ?? [];

    expect($raw)->toBe(
        [],
        'A budget cell and a transaction amount are compared as money, through moneyEquals(), so '
        .'the amount and its currency are both part of the answer. These compare the minor units '
        ."beside it instead:\n  ".implode("\n  ", $raw),
    );

    $violatingSample = 'if ($sNewMinor === $baselineMinor) { continue; }';
    expect(PatternScan::matches(MERGE_MONEY_RAW_COMPARISON_PATTERN, $violatingSample))->toBeTrue();

    $safeSample = 'if (self::moneyEquals($sNewMinor, $baselineMinor, $currency)) { continue; }';
    expect(PatternScan::matches(MERGE_MONEY_RAW_COMPARISON_PATTERN, $safeSample))->toBeFalse();
});

it('renders no money anywhere it is deciding what to write', function (): void {
    $rendered = [];

    foreach (MERGE_MONEY_PIPELINE as $relativePath) {
        if (PatternScan::matches(MERGE_MONEY_RENDERING_PATTERN, mergeMoneyStrippedSource($relativePath))) {
            $rendered[] = $relativePath;
        }
    }

    // The list is walked rather than counted from the filesystem, and
    // mergeMoneyStrippedSource() asserts each file exists, so a renamed one
    // fails there rather than quietly shrinking the scan.
    expect(MERGE_MONEY_PIPELINE)->toHaveCount(6);

    expect($rendered)->toBe(
        [],
        'Money::format() follows the reader\'s locale and falls back to a second formatter on the '
        .'phone, so a merge that renders before it compares decides differently per install. '
        ."Compare the value; render only for a screen. Offenders:\n  ".implode("\n  ", $rendered),
    );

    $violatingSample = 'if ($new->format() === $baseline->format()) { continue; }';
    expect(PatternScan::matches(MERGE_MONEY_RENDERING_PATTERN, $violatingSample))->toBeTrue();

    $safeSample = '$periodStart->format(\'Y-m-d\');';
    expect(PatternScan::matches(MERGE_MONEY_RENDERING_PATTERN, $safeSample))->toBeFalse();
});

it('settles money equality on the amount and the currency it is denominated in', function (): void {
    $source = mergeMoneyStrippedSource(MERGE_MONEY_RESOLVER);

    $body = PatternScan::first('/function moneyEquals\([^)]*\)\s*:\s*bool\s*\{(.*?)\n    \}/s', $source);

    expect($body)->not->toBe([], 'Could not locate ThreeWayMergeResolver::moneyEquals() to read its body.');

    expect($body[1])
        ->toContain('Money::ofMinor(')
        ->toContain('->equals(');
});
