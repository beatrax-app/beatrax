<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

// `beatrax:failed-jobs prune --older-than=30d` reads like a retention policy
// and is not one. The exhaustive operational-artefact table in the spec's
// data-retention appendix carries failed-job records as "pruned only on
// explicit command", and the operator runbook files the command under
// maintenance somebody runs. The 30-day default belongs to that invocation.
//
// Scheduling it would create an automatic deletion absent from a list the
// spec declares exhaustive, so the absence is the decision — and an absence
// nothing explains is indistinguishable from a task dropped by accident,
// which is how twenty scheduled tasks went missing on a real device.

// `mobile-app/routes` is a symlink to `../routes`, so today both roots read one
// file. The paths are kept unresolved rather than collapsed by realpath: the day
// that symlink becomes a copy, this reads two files instead of one and neither
// escapes the scan.
/** @return list<string> the path each Composer root reads its console routes from */
function failedJobsConsoleRoutePaths(): array
{
    $candidates = [
        base_path('routes/console.php'),
        base_path('mobile-app/routes/console.php'),
        dirname(base_path()).'/routes/console.php',
    ];

    $paths = [];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && ! in_array($candidate, $paths, true)) {
            $paths[] = $candidate;
        }
    }

    return $paths;
}

/** @return list<string> the lines of $source that schedule the failed-jobs command */
function failedJobsScheduleLinesIn(string $source): array
{
    $offending = [];

    foreach (explode("\n", $source) as $number => $line) {
        if (str_contains($line, 'beatrax:failed-jobs')) {
            $offending[] = ($number + 1).': '.trim($line);
        }
    }

    return $offending;
}

it('reads a console route file at both Composer roots, so finding nothing cannot pass this guard', function (): void {
    $paths = failedJobsConsoleRoutePaths();

    expect(count($paths))->toBeGreaterThanOrEqual(2, implode("\n", [
        'Only these console route paths were found, so the scan below covers one root at most:',
        '  '.implode("\n  ", $paths),
        '',
        'The desktop root reads routes/console.php and mobile-app/ reads mobile-app/routes/console.php,',
        'and the mobile CI job sets base_path() to mobile-app/. A schedule reachable from one root',
        'and not the other is a task that exists on one platform only.',
    ]));

    foreach ($paths as $path) {
        $schedules = str_contains((string) file_get_contents($path), 'Schedule::command(');

        expect($schedules)->toBeTrue(
            "{$path} schedules nothing at all, so scanning it for one command proves nothing.",
        );
    }
});

it('schedules beatrax:failed-jobs at neither Composer root', function (): void {
    $offenders = [];

    foreach (failedJobsConsoleRoutePaths() as $path) {
        foreach (failedJobsScheduleLinesIn((string) file_get_contents($path)) as $line) {
            $offenders[] = $path.' '.$line;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'The failed-jobs prune is scheduled:',
        '  '.implode("\n  ", $offenders),
        '',
        'It must not be. The spec\'s data-retention appendix lists every automatic deletion the',
        'product performs and declares that list exhaustive; failed-job records appear on it as',
        '"pruned only on explicit command". A timer running this would be a deletion absent from',
        'that list, which the appendix defines as a defect. Run it from the operator runbook, or',
        'change the appendix first.',
    ]));
});

it('registers no scheduled task that would invoke the failed-jobs prune', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $offenders = [];
    foreach ($schedule->events() as $event) {
        /** @var Event $event */
        if (str_contains((string) $event->command, 'beatrax:failed-jobs')) {
            $offenders[] = (string) $event->description.' => '.(string) $event->command;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These scheduled entries invoke the failed-jobs prune:',
        '  '.implode("\n  ", $offenders),
        '',
        'Failed-job records are pruned only on explicit command. A scheduled prune is an',
        'automatic deletion the spec\'s exhaustive retention table does not carry.',
    ]));
});

// The absence being pinned is "on no schedule", never "not shipped". Losing the
// command would satisfy every assertion above and take the operator's only
// route to a growing failed_jobs table with it.
it('keeps the prune reachable as a command an operator runs', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);

    $registered = in_array('beatrax:failed-jobs', array_keys($kernel->all()), true);

    expect($registered)->toBeTrue(
        'The retention table promises a prune on explicit command. Nothing offers one.',
    );
});

it('reports a scheduled prune, so the scan above can fail', function (): void {
    // Assembled at runtime so this file cannot read its own fixture as a real
    // offender when a future guard scans the tests too.
    $command = 'Schedule::command'."('beatrax:failed-jobs prune --older-than=30d')";
    $planted = "<?php\n".$command."\n    ->name('core.failed-jobs-prune')\n    ->daily();";

    expect(failedJobsScheduleLinesIn($planted))->toBe(["2: {$command}"]);
});
