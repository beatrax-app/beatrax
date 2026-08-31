<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use NativePHP\BackgroundTasks\SchedulerManifestGenerator;

// Measured on a Samsung SM-S928B: of twenty-one schedule events the phone built
// a manifest from, one survived. Nineteen were `Schedule::call()` closures with
// no artisan name to invoke, and `db:backup --force` was dropped for a cron the
// runner has no repeat interval for. Nothing failed; an INFO line said so.

/** @return list<string> the artisan commands a phone manifest built from this schedule would carry */
function manifestCarriedCommands(): array
{
    return MobileBackgroundSchedule::carriedBy(app(Schedule::class)->events());
}

/** @return list<string> the name of every registered schedule entry, duplicates kept */
function scheduledTaskNames(): array
{
    return array_map(
        static fn (Event $event): string => (string) $event->description,
        app(Schedule::class)->events(),
    );
}

// MobileBackgroundSchedule::mobileRootOnly() is deliberately not asserted here.
// MobileServiceProvider `require_once`s Modules/Mobile/Routes/console.php, so it
// registers on the first application in a process and on no later one; only a
// fresh process sees those two. IosBackgroundTaskManifestTest measures them in
// exactly that, and that is where they are asserted.
/** @return array<string, string> schedule name => artisan command */
function manifestRequiredCommands(): array
{
    return MobileBackgroundSchedule::requiredOnDevice();
}

it('carries every scheduled task the phone must run, db:backup among them', function (): void {
    $carried = manifestCarriedCommands();
    $required = manifestRequiredCommands();

    $missing = array_values(array_diff(array_values($required), $carried));

    expect($missing)->toBe([], implode("\n", [
        'These tasks never reach the phone. A phone can be the only device a household owns,',
        'so each one here is work with nothing else to fall back on:',
        '  '.implode("\n  ", $missing),
        '',
        'A task reaches the manifest only as a Schedule::command() on an expression listed in',
        'MobileBackgroundSchedule::RUNNER_INTERVALS. A Schedule::call() closure has no artisan',
        'name for the runner to invoke, and dailyAt() is a wall clock the runner cannot express.',
    ]));

    expect(in_array('db:backup --force', $carried, true))->toBeTrue(
        'Automatic backups do not exist on the phone. In a local-first app the phone may be the '
        .'only device holding the data, so this is the one entry that cannot be allowed to lapse.',
    );
});

it('leaves no scheduled task unaccounted for, as phone work or as a stated desktop-only decision', function (): void {
    $scheduled = array_map(
        static fn (Event $event): string => (string) $event->description,
        app(Schedule::class)->events(),
    );

    $declared = MobileBackgroundSchedule::requiredOnDevice()
        + MobileBackgroundSchedule::mobileRootOnly()
        + MobileBackgroundSchedule::desktopOnly();

    $unaccounted = array_values(array_diff($scheduled, array_keys($declared)));

    expect($unaccounted)->toBe([], implode("\n", [
        'A scheduled task is neither declared as phone work nor declared desktop-only:',
        '  '.implode("\n  ", $unaccounted),
        '',
        'Twenty tasks went missing on a real device precisely because nothing had to say which',
        'ones were meant to be there. Add it to MobileBackgroundSchedule::requiredOnDevice() as a',
        'Schedule::command(), or to ::desktopOnly() with the reason a phone does not run it.',
    ]));

    $orphaned = array_values(array_diff(
        array_keys(MobileBackgroundSchedule::requiredOnDevice() + MobileBackgroundSchedule::desktopOnly()),
        $scheduled,
    ));

    expect($orphaned)->toBe(
        [],
        'These names are declared in MobileBackgroundSchedule but nothing schedules them, so the '
        ."declaration is describing a task that no longer exists:\n  ".implode("\n  ", $orphaned),
    );
});

// Nothing loads routes/console.php exactly once. On the mobile root
// nativephp/mobile-background-tasks `require_once`s it the moment Schedule
// first resolves, and Kernel::discoverCommands() then plain-`require`s the same
// path a second time — every entry below registered twice.
it('registers each scheduled task once however often routes/console.php is loaded', function (): void {
    $before = scheduledTaskNames();

    require base_path('routes/console.php');

    $duplicated = array_keys(array_filter(
        array_count_values(scheduledTaskNames()),
        static fn (int $times): bool => $times > 1,
    ));

    expect(scheduledTaskNames())->toBe($before, implode("\n", [
        'Loading routes/console.php a second time registered every task in it a second time:',
        '  '.implode("\n  ", $duplicated),
        '',
        'Android WorkManager replaces by unique name and swallows this. iOS',
        'BGTaskScheduler.register throws NSInternalInconsistencyException on the second',
        'handler for one identifier, and NativePHP does not catch it — the app aborts on',
        'launch before it draws a frame.',
    ]));
});

it('schedules every phone task on an expression the runner has a repeat interval for', function (): void {
    $offenders = [];

    foreach (app(Schedule::class)->events() as $event) {
        $name = (string) $event->description;
        if (! array_key_exists($name, manifestRequiredCommands())) {
            continue;
        }
        if (! in_array($event->expression, MobileBackgroundSchedule::RUNNER_INTERVALS, true)) {
            $offenders[] = $name.' => '.$event->expression;
        }
    }

    expect($offenders)->toBe(
        [],
        "The runner takes a repeat period, never a wall clock, so these are dropped:\n  "
        .implode("\n  ", $offenders),
    );
});

it('agrees with the manifest nativephp/mobile-background-tasks actually builds', function (): void {
    if (! class_exists(SchedulerManifestGenerator::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $vendor = array_values(array_unique(array_column((new SchedulerManifestGenerator)->generate(), 'command')));
    $ours = manifestCarriedCommands();
    sort($vendor);
    sort($ours);

    expect($ours)->toBe(
        $vendor,
        'MobileBackgroundSchedule reimplements the vendor generator\'s two filters so beatrax:doctor '
        .'can run them at the desktop root, where the package is not installed. They have drifted.',
    );
});

it('pins the expressions the vendor generator has an interval for', function (): void {
    if (! class_exists(SchedulerManifestGenerator::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $method = (new ReflectionClass(SchedulerManifestGenerator::class))->getMethod('cronToIntervalMinutes');
    $generator = new SchedulerManifestGenerator;

    $accepted = [];
    foreach (MobileBackgroundSchedule::RUNNER_INTERVALS as $expression) {
        if ($method->invoke($generator, $expression) !== null) {
            $accepted[] = $expression;
        }
    }

    expect($accepted)->toBe(
        MobileBackgroundSchedule::RUNNER_INTERVALS,
        'An expression this codebase believes the runner can express, it cannot.',
    );

    foreach (['15 9 * * *', '0 3 * * *', '0 9 * * *', '30 4 * * *', '0 6 * * *'] as $wallClock) {
        expect($method->invoke($generator, $wallClock))->toBeNull(
            "The generator now has an interval for {$wallClock}; RUNNER_INTERVALS is missing it.",
        );
    }
});
