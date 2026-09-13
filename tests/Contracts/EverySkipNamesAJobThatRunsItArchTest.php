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

/**
 * @param  array<string, mixed>  $entry
 * @return list<string> what a runs-nowhere entry fails to say
 */
function skipBudgetNowhereFaults(string $path, array $entry): array
{
    $faults = [];

    /** @var array<string, string> $nowhere */
    $nowhere = is_array($entry['runs_nowhere'] ?? null) ? $entry['runs_nowhere'] : [];

    foreach (['artefact', 'answered_by'] as $field) {
        if (trim($nowhere[$field] ?? '') === '') {
            $faults[] = $path.'  runs nowhere and names no '.$field.'.';
        }
    }

    /** @var array<string, int> $skipped */
    $skipped = is_array($entry['skipped'] ?? null) ? $entry['skipped'] : [];

    if ($skipped === [] || in_array(0, $skipped, true)) {
        $faults[] = $path.'  runs nowhere, so every job it is collected in has to pin a NON-zero skip count.';
    }

    return $faults;
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

it('gives every pinned skip a job that runs it, or says why no job can', function (): void {
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
    $nowhere = [];

    foreach ($budget['files'] as $path => $entry) {
        if (trim($entry['why']) === '') {
            $wrong[] = $path.'  carries no reason.';
        }

        // The one value that is not a job. It costs more to write than a job
        // name does, deliberately: the alternative these two entries were
        // living under is an `expect(true)->toBeTrue()` that reports a pass in
        // every job, and a record that says so is the cheaper lie to find.
        if ($entry['runs_in'] === null) {
            $nowhere[] = $path;

            $wrong = [...$wrong, ...skipBudgetNowhereFaults($path, $entry)];

            continue;
        }

        if (! isset($budget['jobs'][$entry['runs_in']])) {
            $wrong[] = $path.'  names the job "'.$entry['runs_in'].'", which is not one of: '.implode(', ', array_keys($budget['jobs']));

            continue;
        }

        if (($entry['skipped'][$entry['runs_in']] ?? null) !== 0) {
            $wrong[] = $path.'  says it runs in "'.$entry['runs_in'].'" without pinning a zero skip count there.';
        }
    }

    expect($wrong)->toBe([], "A pinned skip names the job that RUNS it and pins zero skips there, or it names no job at all and says which artefact is missing and what would produce it. The job named here is the one .github/scripts/skip-budget.py then holds to the claim. Offenders:\n  ".implode("\n  ", $wrong));

    // Kept small on purpose. A gate no job answers is still a gate nobody is
    // holding, and the list growing is the signal that the pipeline, not the
    // budget, is what needs the change.
    expect(count($nowhere))->toBeLessThanOrEqual(
        2,
        'These tests run in no job at all: '.implode(', ', $nowhere).'. Each is a rule nothing enforces. '
        .'Give the pipeline a way to answer them rather than recording more of them.',
    );
});

it('reads a runs-nowhere entry as one only when it says what is missing', function (): void {
    $complete = ['markers' => 1, 'skipped' => ['quality shard' => 1], 'runs_in' => null, 'runs_nowhere' => ['artefact' => 'a.swift', 'answered_by' => 'a native build'], 'why' => 'because'];

    expect(skipBudgetNowhereFaults('a.php', $complete))->toBe([], 'a complete entry is the one shape this value exists for');

    expect(skipBudgetNowhereFaults('a.php', [...$complete, 'runs_nowhere' => ['artefact' => '', 'answered_by' => 'a native build']]))
        ->not->toBe([], 'an entry naming no artefact is a "nowhere" nobody can act on');

    // A zero here would say the file RAN in that job, which is the one thing a
    // runs-nowhere entry cannot also be true of.
    expect(skipBudgetNowhereFaults('a.php', [...$complete, 'skipped' => ['quality shard' => 0]]))
        ->not->toBe([], 'a zero skip count contradicts the claim that it runs nowhere');
});
