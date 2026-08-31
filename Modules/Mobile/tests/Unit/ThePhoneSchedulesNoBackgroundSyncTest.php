<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;

// Measured on a paired, fully synced Galaxy SM-S928B: six firings of the
// fifteen-minute background sync, six skips, no successes. The device identity
// is sealed by the app-lock key, which lives only in an unlocked session, and
// an OS-scheduled tick builds its own empty one.

it('declares the background work a phone cannot run, and why', function (): void {
    $impossible = MobileBackgroundSchedule::impossibleOnDevice();

    expect($impossible)->toHaveKey('mobile.sync-pull');

    foreach ($impossible as $name => $reason) {
        expect(strlen($reason))->toBeGreaterThan(40, "{$name} is declared unrunnable with no reason to read.");
    }
});

it('leaves no console-route entry scheduling work the phone cannot run', function (): void {
    $routes = (string) file_get_contents(base_path('Modules/Mobile/Routes/console.php'));

    expect($routes)->not->toContain('sync:mobile-pull');

    $offenders = [];
    foreach (array_keys(MobileBackgroundSchedule::impossibleOnDevice()) as $name) {
        if (str_contains($routes, "'".$name."'")) {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These schedule entries are still registered on the phone after being declared unrunnable there:',
        '  '.implode("\n  ", $offenders),
        '',
        'A fifteen-minute wake-up for work that can never happen costs battery and tells the reader,',
        'through the plain fact that it is scheduled, that their phone syncs while they are not looking.',
    ]));
});

it('schedules nothing it has declared the phone cannot run', function (): void {
    $scheduled = array_map(
        static fn (Event $event): string => (string) $event->description,
        app(Schedule::class)->events(),
    );

    $offenders = array_values(array_intersect(
        array_keys(MobileBackgroundSchedule::impossibleOnDevice()),
        $scheduled,
    ));

    expect($offenders)->toBe([], 'Declared unrunnable on a phone and scheduled anyway: '.implode(', ', $offenders));
});

it('declares each task exactly once across the four lists', function (): void {
    $lists = [
        'requiredOnDevice' => MobileBackgroundSchedule::requiredOnDevice(),
        'mobileRootOnly' => MobileBackgroundSchedule::mobileRootOnly(),
        'desktopOnly' => MobileBackgroundSchedule::desktopOnly(),
        'impossibleOnDevice' => MobileBackgroundSchedule::impossibleOnDevice(),
    ];

    $seen = [];
    $duplicates = [];
    foreach ($lists as $listName => $entries) {
        foreach (array_keys($entries) as $name) {
            if (isset($seen[$name])) {
                $duplicates[] = "{$name} ({$seen[$name]} and {$listName})";
            }
            $seen[$name] = $listName;
        }
    }

    expect($duplicates)->toBe([], 'A task declared twice states two different intents: '.implode(', ', $duplicates));
});
