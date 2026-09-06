<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

// The dynamic half of this guard is .github/scripts/skip-budget.py, which holds
// each JOB's reported skips against the same budget. It can only see the tests
// that a job collected, so a marker that fires on nobody's machine is invisible
// to it. This half reads the tree instead: every call site is accounted for
// before it has ever been reached.
const SKIP_BUDGET = '.github/test-skip-budget.json';

/**
 * The pattern's own text does not match the pattern — the source carries
 * `skip\(` and the rule looks for `skip(` — so this file needs no exemption
 * from the sweep it performs.
 *
 * @return array<string, int>
 */
function skipMarkersInTree(): array
{
    $counts = [];
    $roots = array_merge([base_path('tests')], glob(base_path('Modules/*/tests'), GLOB_ONLYDIR) ?: []);

    foreach ($roots as $root) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }

            $found = PatternScan::count(
                '/markTestSkipped\(|->skip\(/',
                (string) file_get_contents($file->getPathname()),
            );

            if ($found > 0) {
                $counts[str_replace(base_path().'/', '', $file->getPathname())] = $found;
            }
        }
    }

    ksort($counts);

    return $counts;
}

/**
 * @return array{jobs: array<string, string>, files: array<string, array{markers: int, skipped: array<string, int>, runs_in: string, why: string}>}
 */
function skipBudgetPins(): array
{
    /** @var array{jobs: array<string, string>, files: array<string, array{markers: int, skipped: array<string, int>, runs_in: string, why: string}>} $budget */
    $budget = json_decode((string) file_get_contents(base_path(SKIP_BUDGET)), true, flags: JSON_THROW_ON_ERROR);

    return $budget;
}

it('accounts for every skip marker in the tree', function (): void {
    $found = skipMarkersInTree();
    $pinned = array_map(static fn (array $entry): int => $entry['markers'], skipBudgetPins()['files']);
    ksort($pinned);

    // 32 files carry a skip marker today. A walk that found none of them and a
    // budget that pins none would agree with each other about an empty tree.
    expect(count($found))->toBeGreaterThan(
        10,
        'The suite walk found no skip marker at all, so the comparison below holds an empty budget to an empty tree.'
    );
    expect(count($pinned))->toBeGreaterThan(
        10,
        'The budget pins no file at all, so the comparison below holds an empty tree to an empty budget.'
    );

    expect($found)->toBe($pinned, 'The skip markers in the tree and the budget in '.SKIP_BUDGET.' disagree. A skip is counted in the same line as a pass, so a capability no job supplies reads in every report as a guarantee that holds — which is why each one has to be written down with the job that still runs it. Add the file, correct its `markers` count, or drop the entry with the marker.');
});

it('gives every pinned skip a job that runs the tests it retires', function (): void {
    $budget = skipBudgetPins();

    // Both halves of the budget, read before the verdict: with no pinned file
    // the loop below never runs and reports a clean budget over an empty one.
    expect(count($budget['jobs']))->toBeGreaterThan(
        1,
        'The budget declares no job at all, so every entry below would be held to an empty list of them.'
    );
    expect(count($budget['files']))->toBeGreaterThan(
        10,
        'The budget pins no file at all, so this rule checked nothing.'
    );

    $wrong = [];

    foreach ($budget['files'] as $path => $entry) {
        if (! isset($budget['jobs'][$entry['runs_in']])) {
            $wrong[] = $path.'  names the job "'.$entry['runs_in'].'", which is not one of: '.implode(', ', array_keys($budget['jobs']));

            continue;
        }

        if (($entry['skipped'][$entry['runs_in']] ?? null) !== 0) {
            $wrong[] = $path.'  says it runs in "'.$entry['runs_in'].'" without pinning a zero skip count there.';
        }

        if (trim($entry['why']) === '') {
            $wrong[] = $path.'  carries no reason.';
        }
    }

    expect($wrong)->toBe([], "A pinned skip has to name a job that RUNS it, and pin zero skips in that job — there is deliberately no value meaning \"nowhere\", because a test that runs nowhere is worse than no test: it is counted. The job named here is the one .github/scripts/skip-budget.py then holds to the claim. Offenders:\n  ".implode("\n  ", $wrong));
});
