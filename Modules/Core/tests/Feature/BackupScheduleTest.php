<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// `--force` on the scheduled run is deliberate: the smart-skip would otherwise
// silence a quiet day, the 48h freshness probe would then trip, and the
// resulting backup_overdue banner could not be cleared by a manual db:backup,
// which skips a genuinely unchanged database for the same reason.

// Midnight rather than `dailyAt('03:00')`: `0 3 * * *` is not an interval, and
// the phone's background runner takes nothing else — so the one device that may
// be holding the only copy of this data never backed it up.

it('registers a db.backup-daily schedule entry daily with --force and a withoutOverlapping mutex', function (): void {
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

    expect($matched->expression)->toBe('0 0 * * *');
    expect((string) $matched->command)->toContain('db:backup');
    expect((string) $matched->command)->toContain('--force');
    expect($matched->mutexName())->not->toBe('');
});
