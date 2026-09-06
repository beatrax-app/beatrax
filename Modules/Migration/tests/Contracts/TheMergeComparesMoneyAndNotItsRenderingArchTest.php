<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Two of the resolver's five reconcilers compare names, with `===` on strings,
// and the other two compare money. A money field added by copying the string
// branch beside it reads correctly and decides on a rendering: whether a cell
// changed would then depend on the reader's locale, and on the phone, where
// every non-English locale falls back to a different formatter, on the build.

const MERGE_MONEY_RESOLVER = 'Modules/Migration/Internal/Pipeline/ThreeWayMergeResolver.php';

const MERGE_MONEY_PIPELINE_DIR = 'Modules/Migration/Internal/Pipeline';

// The list this rule used to walk named six of the fifteen files in that
// directory. The other nine were not decided about, they were never opened, and
// one of them renders money. Walking the directory puts a file added tomorrow
// in scope the day it lands.
/** @var array<string, array{reason: string, proves: string}> */
const MERGE_MONEY_RENDERS_FOR_A_SCREEN = [
    'Modules/Migration/Internal/Pipeline/PreviewSummaryBuilder.php' => [
        'reason' => 'the rule is compare the value and render only for a screen, and this is the screen: its one rendering is the last step before a PreviewSummary leaves for the Blade view the reader confirms the merge on. It decides nothing about what gets written',
        'proves' => ': PreviewSummary',
    ],
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

/** @return list<string> every file the merge pipeline is built out of, as repo-relative paths */
function mergeMoneyPipelineFiles(): array
{
    $root = base_path(MERGE_MONEY_PIPELINE_DIR);
    $paths = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($walk as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $paths[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    sort($paths);

    return $paths;
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
    $files = mergeMoneyPipelineFiles();

    // Counted first: a walk that reached nothing reports the same empty
    // offender list a pipeline that renders nothing reports.
    expect(count($files))->toBeGreaterThan(
        8,
        'The walk over '.MERGE_MONEY_PIPELINE_DIR.' reached '.count($files).' files, which is too few to be '
        .'the merge pipeline. Every verdict below would be read off a directory nobody opened.'
    );

    $rendered = [];

    foreach ($files as $relativePath) {
        if (array_key_exists($relativePath, MERGE_MONEY_RENDERS_FOR_A_SCREEN)) {
            continue;
        }

        if (PatternScan::matches(MERGE_MONEY_RENDERING_PATTERN, mergeMoneyStrippedSource($relativePath))) {
            $rendered[] = $relativePath;
        }
    }

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

it('still holds each pipeline file that renders for a screen to the reason it was allowed to', function (): void {
    $files = mergeMoneyPipelineFiles();
    $expired = [];

    foreach (MERGE_MONEY_RENDERS_FOR_A_SCREEN as $relativePath => $pin) {
        if (! in_array($relativePath, $files, strict: true)) {
            $expired[] = $relativePath.' is exempted and the walk no longer reaches it';

            continue;
        }

        $source = (string) file_get_contents(base_path($relativePath));

        if (! str_contains($source, $pin['proves'])) {
            $expired[] = $relativePath.' no longer holds "'.$pin['proves'].'", so it is no longer '.$pin['reason'];
        }

        if (! PatternScan::matches(MERGE_MONEY_RENDERING_PATTERN, mergeMoneyStrippedSource($relativePath))) {
            $expired[] = $relativePath.' renders no money any more, so the exemption excuses nothing — delete it';
        }
    }

    expect($expired)->toBe([], implode("\n  ", [
        'These exemptions have outlived what earned them. A file was allowed to render money because it '
            .'renders for a screen and decides nothing, and that no longer reads:',
        ...$expired,
    ]));
});

it('settles money equality on the amount and the currency it is denominated in', function (): void {
    $source = mergeMoneyStrippedSource(MERGE_MONEY_RESOLVER);

    $body = PatternScan::first('/function moneyEquals\([^)]*\)\s*:\s*bool\s*\{(.*?)\n    \}/s', $source);

    expect($body)->not->toBe([], 'Could not locate ThreeWayMergeResolver::moneyEquals() to read its body.');

    expect($body[1])
        ->toContain('Money::ofMinor(')
        ->toContain('->equals(');
});
