<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

// Carbon normalises 31 February forward, so a whole-month step off the 29th,
// 30th or 31st lands in the month after the one asked for, and a startOfMonth()
// written underneath flattens that wrong month rather than undoing it. Ten
// sites shipped this way, each looking like success. The NoOverflow variants
// answer the other question, and on a first-of-month anchor they answer the
// same one — which is why the rule here is absolute rather than conditional on
// the anchor. Proving an anchor is day-1 needs dataflow a guard cannot do
// honestly, and a guard that mis-reads an anchor reports a clean tree.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-month-step-off-a-day-the-next-month-does-not-have

// Units of variable length: a step of one lands on a day-of-month the target
// may not have. Days, weeks, hours and everything below them are fixed-length
// and cannot overflow, so they are not named here.
const WHOLE_MONTH_STEP_UNITS = [
    'Month', 'Months',
    'Quarter', 'Quarters',
    'Year', 'Years',
    'Decade', 'Decades',
    'Century', 'Centuries',
    'Millennium', 'Millennia',
];

// The generic steppers reach the same arithmetic under a unit argument, so a
// call site blocked by the rule above can otherwise restate it here and land in
// exactly the same March.
const WHOLE_MONTH_STEP_GENERIC_CALLS = ['add', 'sub', 'addUnit', 'subUnit', 'addRealUnit', 'subRealUnit'];

// Deliberately empty, and the stronger for it. The last entry here was
// CalendarMonthWindow's five day-1-anchored steps, converted rather than
// explained: on a first-of-month anchor the NoOverflow variant returns the
// identical date, so a pin bought nothing and cost a claim to keep true.
//
// Each entry would name a file whose bare steps are all taken off the first of
// a month, why, and how many there are — `proves` re-run against the file and
// `sites` against the walk, so an anchor that stops being day-1 or a step added
// beside them fails here rather than being waved through.
/** @var array<string, array{reason: string, sites: int, proves: list<string>}> */
const WHOLE_MONTH_STEP_PINS = [];

// Not exemptions, and not deliberate. Each is a bare step that should be
// converted and could not be reached in the round that wrote this rule, because
// another owner held the file. It is carried as a debt with a count rather than
// waved through with a justification: an entry leaves by being converted, and
// when that happens the count stops matching and this goes red, which is how the
// table empties itself. `proves` records only why leaving it is safe meanwhile —
// each anchors on the first of a month, so none is a live date-dependent flake.
/** @var array<string, array{owner: string, sites: int, proves: list<string>}> */
const WHOLE_MONTH_STEP_HANDOVERS = [
    'Modules/Calendar/tests/Feature/TheCalendarStopsAtItsHistoryFloorTest.php' => [
        'owner' => 'Calendar',
        'sites' => 2,
        'proves' => [
            '/->startOfMonth\(\)->subMonths\(CalendarMonthWindow::HISTORY_MONTHS\)/',
            '/\$aboveFloor = \$floor->addMonth\(\)/',
        ],
    ],
    'Modules/Calendar/tests/Feature/TheProjectionHorizonEndsWhereTheGridDoesTest.php' => [
        'owner' => 'Calendar',
        'sites' => 1,
        'proves' => ['/\$nextUp = \$window->ceilingMonth\(\)->addMonth\(\)/'],
    ],
];

/** @return list<string> the `add`/`sub` methods that step by a variable-length unit */
function wholeMonthStepMethods(): array
{
    $names = [];
    foreach (WHOLE_MONTH_STEP_UNITS as $unit) {
        $names[] = 'add'.$unit;
        $names[] = 'sub'.$unit;
    }

    return $names;
}

/**
 * Modules, app and the whole test tree, migrations and fixtures included. A
 * fixture date built as now()->addMonths(n) is a different date on the 29th,
 * 30th and 31st than on the 1st, so a bare step in a test is a suite that
 * passes twenty-eight days a month — which is not a suite that passes.
 *
 * The four roots beside them hold PHP that runs: a scheduled closure in routes,
 * a seeder under database, a horizon in a config array. The rule reads "every
 * whole-month step", and a walk that opened three roots while saying so is the
 * shape the file below is about.
 *
 * @return list<string>
 */
function wholeMonthStepFiles(): array
{
    $paths = [];

    foreach (['Modules', 'app', 'tests', 'routes', 'database', 'config', 'bootstrap'] as $root) {
        $directory = base_path($root);
        if (! is_dir($directory)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php')) {
                $paths[] = $path;
            }
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * Every whole-month step in the given files, told apart by whether it clamps.
 * `counted` covers both kinds, so a walk that reads nothing cannot pass for a
 * tree that steps nothing.
 *
 * @param  list<string>  $paths
 * @return array{bare: array<string, list<string>>, counted: int}
 */
function wholeMonthStepsIn(array $paths): array
{
    $stepping = wholeMonthStepMethods();
    $clamping = array_map(static fn (string $name): string => $name.'NoOverflow', $stepping);
    $unitArgument = '/\b(?:month|quarter|year|decade|centur|millenni)/i';
    $bare = [];
    $counted = 0;

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);
        $relative = str_replace(base_path().'/', '', $path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $caller = $tokens[$index - 1] ?? null;
            if (! is_array($caller) || ! in_array($caller[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $name = $token[1];

            if (in_array($name, $clamping, true)) {
                $counted++;

                continue;
            }

            if (in_array($name, $stepping, true)) {
                $counted++;
                $bare[$relative][] = $relative.':'.$token[2].' takes '.$name.'()';

                continue;
            }

            if (! in_array($name, WHOLE_MONTH_STEP_GENERIC_CALLS, true)) {
                continue;
            }

            $arguments = BackendSourceFiles::callArguments($tokens, $index);
            if ($arguments === '' || preg_match($unitArgument, $arguments) !== 1) {
                continue;
            }

            $counted++;
            $bare[$relative][] = $relative.':'.$token[2].' takes '.$name.'('.trim($arguments).')';
        }
    }

    ksort($bare);

    return ['bare' => $bare, 'counted' => $counted];
}

it('takes every whole-month step off a day the target month is certain to have', function (): void {
    $files = wholeMonthStepFiles();
    expect($files)->not->toBeEmpty('The walk opened no file at all, so every verdict below is about a tree nobody read.');

    $walk = wholeMonthStepsIn($files);
    $offenders = [];
    $reached = [];
    $handed = [];

    foreach ($walk['bare'] as $relative => $sites) {
        $pin = WHOLE_MONTH_STEP_PINS[$relative] ?? null;
        if ($pin !== null) {
            $reached[$relative] = true;

            if (count($sites) !== $pin['sites']) {
                $offenders[] = $relative.' is pinned at '.$pin['sites'].' first-of-month steps and now takes '.count($sites);
            }

            continue;
        }

        $handover = WHOLE_MONTH_STEP_HANDOVERS[$relative] ?? null;
        if ($handover === null) {
            $offenders = array_merge($offenders, $sites);

            continue;
        }

        $handed[$relative] = true;

        if (count($sites) !== $handover['sites']) {
            $offenders[] = $relative.' is handed to '.$handover['owner'].' at '.$handover['sites']
                .' unconverted steps and now has '.count($sites).' — convert them and delete the entry';
        }
    }

    // Below the count of steps this tree actually takes, so a walk that stops
    // reading fails here instead of reporting a clean tree.
    expect($walk['counted'])->toBeGreaterThan(
        100,
        'Almost no whole-unit step was read, so the empty offender list below is a scan that stopped rather than a tree that clamps.',
    );

    expect($offenders)->toBe([], implode("\n  ", [
        'A whole-month or whole-year step off the 29th, 30th or 31st overflows into',
        'the month after the one asked for, and a startOfMonth() written after it',
        'flattens that month rather than undoing the step. Use the NoOverflow',
        'variant — on a first-of-month anchor it is the same date, so there is no',
        'call site where the bare one is the answer. In a fixture it is the same',
        'defect wearing a date the suite only reaches on three days in thirty.',
        'Offenders:',
        ...$offenders,
    ]));

    // A pin nobody reaches is a claim about the tree that stopped being true.
    expect(array_keys($reached))->toBe(
        array_keys(WHOLE_MONTH_STEP_PINS),
        'a pin nobody reaches excuses nothing while reading as considered — delete the entry',
    );

    expect(array_keys($handed))->toBe(
        array_keys(WHOLE_MONTH_STEP_HANDOVERS),
        'a handover nobody reaches has been converted by its owner — delete the entry',
    );
});

it('still holds each pinned and handed-over file to what was written about it', function (): void {
    $claims = array_merge(WHOLE_MONTH_STEP_PINS, WHOLE_MONTH_STEP_HANDOVERS);
    $reproved = 0;

    foreach ($claims as $relative => $claim) {
        $source = (string) file_get_contents(base_path($relative));

        foreach ($claim['proves'] as $pattern) {
            expect($source)->toMatch($pattern, $relative.' no longer reads the way this entry describes it');
        }

        $reproved++;
    }

    // Counted rather than left implicit, so this states something with an empty
    // pin list too: nought of nought re-proved is the rule holding absolutely,
    // and it is an assertion rather than a test that quietly does nothing.
    expect($reproved)->toBe(
        count($claims),
        'every pin and handover must be re-proved against the file it was written for',
    );
});

it('sees a bare step and a unit argument, and leaves a clamped step and a fixed-length one alone', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'month-step').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedBareMonthStep
        {
            public function due(CarbonImmutable $from): CarbonImmutable
            {
                return $from->addMonths(6)->startOfMonth();
            }

            public function renewal(CarbonImmutable $from): CarbonImmutable
            {
                return $from->add('1 year');
            }
        }
        PHP);

    $clean = tempnam(sys_get_temp_dir(), 'month-step-clean').'.php';
    file_put_contents($clean, <<<'PHP'
        <?php
        final class PlantedClampedMonthStep
        {
            public function due(CarbonImmutable $from): CarbonImmutable
            {
                return $from->addMonthsNoOverflow(6)->startOfMonth();
            }

            public function window(CarbonImmutable $from): CarbonImmutable
            {
                return $from->subDays(90)->addWeek()->add($this->grace);
            }
        }
        PHP);

    try {
        $walk = wholeMonthStepsIn([$planted, $clean]);
    } finally {
        @unlink($planted);
        @unlink($clean);
    }

    $offenders = [];
    foreach ($walk['bare'] as $relative => $sites) {
        $offenders[basename($relative)] = count($sites);
    }

    expect($offenders)->toBe(
        [basename($planted) => 2],
        'the reader has to see the bare month step and the generic add() with a unit argument, and see neither the clamped step nor the fixed-length ones',
    );
    expect($walk['counted'])->toBe(
        3,
        'the denominator has to count the clamped step too, or a tree that clamps everything reads as a tree nobody scanned',
    );
});

it('reads the test tree, not only the code under it', function (): void {
    $files = wholeMonthStepFiles();

    $tests = array_filter($files, static fn (string $path): bool => str_contains($path, '/tests/'));
    $production = array_filter($files, static fn (string $path): bool => ! str_contains($path, '/tests/'));

    // The first scope this guard shipped with excluded the test tree, and the
    // tree then held eighty bare steps nothing was reading. Both halves are
    // asserted so a walk narrowed back to production fails loudly here.
    expect(count($tests))->toBeGreaterThan(500, 'the test tree fell out of the walk, and it held eighty bare steps the first scope never read');
    expect(count($production))->toBeGreaterThan(500, 'the production tree fell out of the walk');

    $clamped = wholeMonthStepsIn(array_values($tests));
    expect($clamped['counted'])->toBeGreaterThan(60, 'the test tree contributed almost no step, so the walk reads it in name only');
});
