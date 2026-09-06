<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Calendar\Internal\Services\CalendarGrid;
use Modules\Calendar\Internal\Services\CalendarMonthWindow;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\WeekStart;
use Modules\Counterparties\Internal\Support\RollingTwelveMonths;
use Modules\Ledger\Internal\Enums\DateRangePreset;
use Modules\Ledger\Public\Services\CalendarSpan;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 * @link ../../.docs/conventions/arch-invariants.md
 */

// Two windows that must agree, each computed from its own rule, are correct in
// isolation and part at the edges. Eight pairs shipped that way. A pinned date
// proves nothing here: the calendar pair was wrong on 364 of 365 today-values
// and right on one, so every pair below is swept over a whole year.

// One year from a 1st, which walks every day of every month and crosses a
// month boundary from both a 30- and a 31-day month, plus a leap day and the
// day before it, which is where the NoOverflow variants earn their name.
/** @return list<string> */
function windowSweepDays(): array
{
    $days = [];
    $cursor = CarbonImmutable::parse('2026-01-01');
    for ($i = 0; $i < 365; $i++) {
        $days[] = $cursor->toDateString();
        $cursor = $cursor->addDay();
    }

    return [...$days, '2028-02-28', '2028-02-29', '2028-03-01'];
}

function windowSweepClock(string $day): Clock
{
    return new class($day) implements Clock
    {
        public function __construct(private readonly string $day) {}

        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse($this->day.' 12:00:00');
        }
    };
}

function windowSweepPeriods(string $day, int $periodStartDay): PeriodQuery
{
    $currentUser = new class($periodStartDay) implements CurrentUser
    {
        public function __construct(private readonly int $startDay) {}

        public function user(): User
        {
            return new User(['period_start_day' => $this->startDay]);
        }

        public function id(): int
        {
            return 1;
        }

        public function isAuthenticated(): bool
        {
            return true;
        }

        public function periodStartDay(): int
        {
            return $this->startDay;
        }
    };

    return new PeriodQuery(windowSweepClock($day), $currentUser);
}

// Every finding is reported, never the first: the count is what says whether a
// rule is wrong at an edge or wrong outright, and it is what refuted the claim
// that the calendar pair was a leap-year corner.
/**
 * @param  list<string>  $disagreements
 */
function windowSweepMessage(string $rule, array $disagreements): string
{
    return $rule."\n".count($disagreements).' of '.count(windowSweepDays())
        ." today-values disagreed. First ten:\n  ".implode("\n  ", array_slice($disagreements, 0, 10));
}

it('never lets the calendar draw a cell the projection it reads has not reached', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        $window = new CalendarMonthWindow(windowSweepClock($day));
        $gridEnd = $window->lastDrawableDay();
        $lastProjected = $window->lastProjectedDay();

        if ($gridEnd->greaterThan($lastProjected)) {
            $disagreements[] = $day.': grid to '.$gridEnd->toDateString()
                .', projection to '.$lastProjected->toDateString()
                .' ('.$lastProjected->diffInDays($gridEnd).' cells with no point)';
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'The calendar grid must not run past the projection that fills it. A cell '
        .'past the last point renders "—" and holds the summary strip on '
        .'"Projection updating…" with no run in flight to finish.',
        $disagreements,
    ));
});

// Reaching far enough is the other half: a ceiling that stopped short would
// pass the rule above and silently cost the reader a month of navigation.
it('reaches the furthest month whose whole strip the projection still covers', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        $window = new CalendarMonthWindow(windowSweepClock($day));
        $nextUp = $window->ceilingMonth()->addMonthNoOverflow();

        if (CalendarGrid::endFor($nextUp)->lessThanOrEqualTo($window->lastProjectedDay())) {
            $disagreements[] = $day.': '.$nextUp->format('Y-m').' fits and is refused';
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'The calendar ceiling is derived from the projection, so it must stop at the '
        .'last month the projection wholly covers — not one earlier.',
        $disagreements,
    ));
});

// The banner over the grid answers about the days the grid draws. Bounded at
// the ceiling month's own end it went blind to that strip's lead-out cells,
// and drew a booked charge under "No upcoming payments".
it('asks its empty state over every day the grid draws', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        $window = new CalendarMonthWindow(windowSweepClock($day));
        $ceilingMonthEnd = $window->ceilingMonth()->endOfMonth()->startOfDay();

        if ($window->lastDrawableDay()->lessThan($ceilingMonthEnd)) {
            $disagreements[] = $day.': probe to '.$window->lastDrawableDay()->toDateString()
                .', ceiling month ends '.$ceilingMonthEnd->toDateString();
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'CalendarQuery::hasProjectableEntries() probes to CalendarMonthWindow::lastDrawableDay(), '
        .'which must cover the whole of the ceiling month and its strip.',
        $disagreements,
    ));
});

it('gives /transactions and /reports one window for "this year"', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        $now = CarbonImmutable::parse($day.' 12:00:00');
        $periods = windowSweepPeriods($day, 1);
        $report = (new PeriodPresetResolver($periods, windowSweepClock($day)))->resolve('this_year');
        [$after, $before] = DateRangePreset::ThisYear->range($periods, $now, 1);

        if ($report->start->toDateString() !== $after
            || CalendarSpan::lastDayOf($report)->toDateString() !== $before
            || $after !== $now->format('Y').'-01-01'
            || $before !== $now->format('Y').'-12-31') {
            $disagreements[] = $day.': /reports '.$report->start->toDateString().'…'
                .CalendarSpan::lastDayOf($report)->toDateString()
                .' vs /transactions '.$after.'…'.$before;
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'A window a reader calls "this year" is CalendarSpan::year() on both surfaces. '
        .'"Year to date" is a different question and is named one.',
        $disagreements,
    ));
});

// Swept on the start days a month can and cannot contain: 1 coincides with the
// calendar month and hid this pair entirely, 28 is the ceiling the settings
// validator clamps to, and 25 is what the second demo persona ships.
it('gives /transactions and /reports one window for "this month"', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        foreach ([PeriodQuery::MIN_START_DAY, 25, PeriodQuery::MAX_START_DAY] as $startDay) {
            $now = CarbonImmutable::parse($day.' 12:00:00');
            $periods = windowSweepPeriods($day, $startDay);
            $report = (new PeriodPresetResolver($periods, windowSweepClock($day)))->resolve('this_month');
            [$after, $before] = DateRangePreset::ThisMonth->range($periods, $now, $startDay);

            if ($report->start->toDateString() !== $after
                || CalendarSpan::lastDayOf($report)->toDateString() !== $before) {
                $disagreements[] = $day.' (start day '.$startDay.'): /reports '
                    .$report->start->toDateString().'…'.CalendarSpan::lastDayOf($report)->toDateString()
                    .' vs /transactions '.$after.'…'.$before;
            }
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'A month is whatever PeriodQuery says the reader\'s month is, on every surface '
        .'that offers the word. A calendar month is a different window and not that one.',
        $disagreements,
    ));
});

it('opens a counterparty twelve-month total on the first bar it draws', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        $now = CarbonImmutable::parse($day.' 12:00:00');
        $months = RollingTwelveMonths::months($now);
        $expected = $months[0].'-01';

        if (RollingTwelveMonths::startDate($now) !== $expected || count($months) !== RollingTwelveMonths::MONTHS) {
            $disagreements[] = $day.': total opens '.RollingTwelveMonths::startDate($now)
                .', bars open '.$expected.' ('.count($months).' bars)';
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'The sparkline IS the twelve-month total, decomposed. Money inside the total '
        .'and inside no bar is money the reader cannot find.',
        $disagreements,
    ));
});

it('opens every calendar strip on the day WeekStart names', function (): void {
    $disagreements = [];

    foreach (windowSweepDays() as $day) {
        $anchor = CarbonImmutable::parse($day);
        $range = CalendarGrid::range($anchor->year, $anchor->month);

        if ($range['start']->dayOfWeek !== WeekStart::DAY
            || $range['end']->addDay()->dayOfWeek !== WeekStart::DAY) {
            $disagreements[] = $day.': strip runs '.$range['start']->toDateString()
                .'…'.$range['end']->toDateString();
        }
    }

    expect($disagreements)->toBe([], windowSweepMessage(
        'The calendar strip, its column headings and the date picker\'s grid all open '
        .'on WeekStart::DAY, so a locale change moves all three or none.',
        $disagreements,
    ));

    expect(CalendarGrid::weekdayLabelKeys())->toHaveCount(WeekStart::DAYS_IN_WEEK)
        ->and(CalendarGrid::weekdayLabelKeys()[0])
        ->toBe('calendar::messages.weekdays.mon', 'the heading row must lead with the week\'s own first day');
});

// The sweeps above prove the pairs agree today. This walk stops a NEW second
// copy of any of them from being written: a rule spelled in two files is one
// edit away from being two rules again, which is how every instance above got
// in. Each exemption states the different question that site is asking.
/** @return array<string, string> "path::spelling" => why that site may spell it */
function windowRuleExemptions(): array
{
    return [
        // The definitions themselves.
        'Modules/Ledger/Public/Services/CalendarSpan.php::startOfYear' => 'the one calendar-year definition',
        'Modules/Core/Public/Support/WeekStart.php::startOfWeek' => 'the one week-start definition',
        'Modules/Core/Public/Support/WeekStart.php::endOfWeek' => 'the one week-end definition',

        // A grocery run lands on a Saturday because that is when the persona
        // shops, which is a cadence and not the day a week is drawn from.
        'Modules/Ledger/Database/Seeders/Demo/DemoTransactionsSeeder.php::startOfWeek' => 'a demo shopping cadence, not a grid',
    ];
}

/** @return list<string> every non-test PHP and Blade file under Modules/ and app/ */
function windowRuleFiles(): array
{
    $files = [];
    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// Read off the source with comments stripped: the prose above several of these
// sites names the very call it is explaining, and a walk that counted those
// would report the explanation as the offence.
function windowRuleStrippedSource(string $path): string
{
    $stripped = '';

    foreach (token_get_all(BladePhpSource::forPath($path, (string) file_get_contents($path))) as $token) {
        $stripped .= is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
            : $token;
    }

    return $stripped;
}

function windowRuleCountsAMonthHorizon(string $source): bool
{
    return preg_match('/->startOfMonth\(\)->addMonths?\(/', $source) === 1;
}

/** @return array<string, list<string>> spelling => the relative paths that call it */
function windowRuleCallSites(): array
{
    $spellings = ['startOfYear', 'endOfYear', 'startOfWeek', 'endOfWeek'];
    $found = [];

    foreach (windowRuleFiles() as $path) {
        $stripped = windowRuleStrippedSource($path);

        foreach ($spellings as $spelling) {
            if (str_contains($stripped, '->'.$spelling.'(')) {
                $found[$spelling][] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    return $found;
}

// The two walks below read the same 6,471 files, and a walk that opened none of
// them reports the same clean tree a walk that found nothing does.
const WINDOW_RULE_FILE_FLOOR = 1_000;

// A year from a 1st, plus the leap day and the days either side of it.
const WINDOW_SWEEP_DAY_COUNT = 368;

it('sweeps a whole year rather than a date somebody picked', function (): void {
    expect(windowSweepDays())->toHaveCount(WINDOW_SWEEP_DAY_COUNT);
});

it('spells a calendar year and a week boundary in one place each (oneWindowDefinition)', function (): void {
    $callSites = windowRuleCallSites();

    // The walk ran and can see the definitions it is guarding. Without this a
    // stripped or renamed tree reports a clean sweep it never took.
    expect(count(windowRuleFiles()))->toBeGreaterThan(
        WINDOW_RULE_FILE_FLOOR,
        'The walk opened '.count(windowRuleFiles()).' files, so a clean answer here is a walk that read almost nothing.'
    );

    expect($callSites['startOfYear'] ?? [])->toContain('Modules/Ledger/Public/Services/CalendarSpan.php')
        ->and($callSites['startOfWeek'] ?? [])->toContain('Modules/Core/Public/Support/WeekStart.php');

    $exempt = windowRuleExemptions();
    $offenders = [];

    foreach ($callSites as $spelling => $paths) {
        foreach ($paths as $path) {
            if (! isset($exempt[$path.'::'.$spelling])) {
                $offenders[] = $path.' calls ->'.$spelling.'()';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A calendar year belongs to Modules\\Ledger\\Public\\Services\\CalendarSpan and a week',
        'boundary to Modules\\Core\\Public\\Support\\WeekStart. Spelled a second time, the two',
        'copies are one edit away from being two rules — which is how "this year" came to',
        'mean 2026-01-01…2026-12-31 on /transactions and 2026-01-01…today on /reports, under',
        'a heading that read "2026".',
        '',
        'Derive from the definition, or add the site to windowRuleExemptions() with the',
        'different question it is asking. Offenders:',
        ...$offenders,
    ]));
});

// A forward horizon counted in whole months always overshoots a grid extended
// to a week boundary, which is exactly the calendar defect. The reach is
// derived from the supply instead, so nothing may count one again.
it('counts no forward horizon in whole months (noMonthCountedHorizon)', function (): void {
    $offenders = [];
    $walked = 0;

    foreach (windowRuleFiles() as $path) {
        $walked++;

        if (windowRuleCountsAMonthHorizon(windowRuleStrippedSource($path))) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($walked)->toBeGreaterThan(
        WINDOW_RULE_FILE_FLOOR,
        'The walk opened '.$walked.' files, so a clean answer here is a walk that read almost nothing.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'A horizon stepped forward in whole months off the first of the month reached past',
        'the projection behind it on 364 of 365 today-values, and every cell past it read',
        '"—" under a strip saying "Projection updating…" with nothing in flight.',
        '',
        'Derive the reach from whatever supplies the data, the way',
        'CalendarMonthWindow::ceilingMonth() walks forward only while the whole next strip',
        'still lands inside CalendarMonthWindow::PROJECTION. Offenders:',
        ...$offenders,
    ]));
});

it('carries no exemption for a site that no longer spells the rule (noStaleWindowExemption)', function (): void {
    $callSites = windowRuleCallSites();
    $stale = [];

    foreach (array_keys(windowRuleExemptions()) as $key) {
        [$path, $spelling] = explode('::', $key, 2);
        if (! in_array($path, $callSites[$spelling] ?? [], true)) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], "An exemption that stops being true waves a real second copy through. Remove:\n  ".implode("\n  ", $stale));
});

// A guard that cannot go red says nothing, and the two readers above are each
// read off one boolean. They are checked against the shapes they were written
// for rather than against the tree.
it('finds a month-counted horizon and leaves a derived reach alone', function (string $body, bool $counts): void {
    expect(windowRuleCountsAMonthHorizon($body))->toBe($counts);
})->with([
    'months added to the first of the month' => ['$end = $now->startOfMonth()->addMonths(6);', true],
    'the singular spelling' => ['$end = $now->startOfMonth()->addMonth();', true],
    'a reach derived from the supply' => ['$end = CalendarGrid::endFor($window->ceilingMonth());', false],
    'months added to something that is not a month start' => ['$end = $now->addMonths(6);', false],
    'a month start with nothing after it' => ['$start = $now->startOfMonth();', false],
]);

it('reads past the prose that names the call it explains', function (): void {
    $path = sys_get_temp_dir().'/window-rule-'.bin2hex(random_bytes(8)).'.php';

    try {
        file_put_contents($path, "<?php\n// this used to be \$now->startOfYear();\n\$year = CalendarSpan::year(\$now);\n");

        expect(windowRuleStrippedSource($path))->not->toContain('->startOfYear(');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
