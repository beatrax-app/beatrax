<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Locks the scheduler registration for `db:backup`: the entry MUST
 * resolve from the application's Schedule instance with the
 * description `db.backup-daily`, a cron expression of `0 3 * * *`
 * (daily at 03:00), a command string containing `db:backup`, and a
 * non-empty mutexName() proving `withoutOverlapping()` is wired.
 *
 * Resolution is done via the container so no facade is required and
 * the test does not need to physically fire the scheduler.
 */

it('registers a db.backup-daily schedule entry at 03:00 with withoutOverlapping mutex', function (): void {
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

    expect($matched)->not->toBeNull('Expected a registered schedule entry with description "db.backup-daily".');

    expect($matched->expression)->toBe('0 3 * * *');
    expect((string) $matched->command)->toContain('db:backup');
    expect($matched->mutexName())->not->toBe('');
});
