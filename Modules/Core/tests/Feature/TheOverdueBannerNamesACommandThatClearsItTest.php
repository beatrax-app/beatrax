<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// The banner that says the backup is stale tells the reader what to do about
// it. It named 03:00 — the hour the entry was moved off, because the phone's
// background runner takes a repeat interval and no wall clock at all, and
// keeping the hour meant the one device that may hold the only copy of this
// data never backed it up. Naming an hour here is a promise neither platform
// keeps: the desktop fires at midnight and the phone whenever its runner does.

/**
 * @return list<string> the wall-clock hours the daily backup copy still names, by locale
 */
function localesPromisingAnHour(): array
{
    $promising = [];

    foreach ((array) glob(base_path('Modules/Core/Resources/lang/*/alerts.php')) as $file) {
        /** @var array{messages: array<string, string>} $strings */
        $strings = require (string) $file;
        $copy = $strings['messages']['backup_overdue'];

        foreach (['03:00', '3:00', '03.00', '3.00', '03H00'] as $hour) {
            if (str_contains($copy, $hour)) {
                $promising[] = basename(dirname((string) $file));
                break;
            }
        }
    }

    return $promising;
}

it('is scheduled on an interval, with no hour a reader could be told to wait for', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $matched = null;
    foreach ($schedule->events() as $event) {
        /** @var Event $event */
        if ($event->description === 'db.backup-daily') {
            $matched = $event;
            break;
        }
    }

    expect($matched)->not->toBeNull();
    expect($matched->expression)->toBe('0 0 * * *');
});

it('does not promise a scheduled hour in any language', function (): void {
    $promising = localesPromisingAnHour();

    expect($promising)->toBe([], 'These locales still name a scheduled hour the backup does not run at: '.implode(', ', $promising).'.');
});
