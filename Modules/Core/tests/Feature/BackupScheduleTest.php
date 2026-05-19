<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Locks the scheduler registration for `db:backup`: the entry MUST
 * resolve from the application's Schedule instance with the
 * description `db.backup-daily`, a cron expression of `0 3 * * *`
 * (daily at 03:00), a command string containing `db:backup --force`,
 * and a non-empty mutexName() proving `withoutOverlapping()` is wired.
 *
 * `--force` is intentionally part of the scheduler invocation: the
 * data_version smart-skip would otherwise silence a quiet day, the
 * 48h BackupFreshnessProbe threshold would then trip, and the
 * resulting `backup_overdue` banner could not be cleared by a
 * follow-up manual `db:backup` (still smart-skipping for the same
 * reason).
 *
 * Resolution is done via the container so no facade is required and
 * the test does not need to physically fire the scheduler.
 */

it('registers a db.backup-daily schedule entry at 03:00 with --force and a withoutOverlapping mutex', function (): void {
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
    expect((string) $matched->command)->toContain('--force');
    expect($matched->mutexName())->not->toBe('');
});
