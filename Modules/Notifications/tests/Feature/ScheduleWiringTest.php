<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Public\Scheduling\DailyLocalWindow;
use Modules\Notifications\Internal\Console\EmitDailyNotificationTriggersCommand;

// Reminders, digest and savings prompts always shared one 09:15 slot, and
// `15 9 * * *` is a wall clock the phone's background runner cannot express —
// it takes a repeat period. The three entries are one command on a supported
// interval now, with the 09:15 decision moved inside it.

function swtFindEvent(Schedule $schedule, string $description): ?ScheduledEvent
{
    foreach ($schedule->events() as $event) {
        /** @var ScheduledEvent $event */
        if ($event->description === $description) {
            return $event;
        }
    }

    return null;
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('registers notifications.daily-triggers on a fifteen-minute interval', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.daily-triggers');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.daily-triggers".');
    expect($event->expression)->toBe('*/15 * * * *');
    expect((string) $event->command)->toContain('notifications:daily-triggers');
    expect($event->mutexName())->not->toBe('');
});

it('registers no separate reminders, digest or savings-prompts entry any more', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    foreach (['notifications.reminders', 'notifications.digest', 'notifications.savings-prompts'] as $retired) {
        expect(swtFindEvent($schedule, $retired))->toBeNull(
            "{$retired} is dispatched by notifications:daily-triggers now; a second entry would emit it twice.",
        );
    }
});

it('registers notifications.budget-nudges hourly', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.budget-nudges');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.budget-nudges".');
    expect($event->expression)->toBe('0 * * * *');
    expect((string) $event->command)->toContain('budgets:emit-nudges');
    expect($event->mutexName())->not->toBe('');
});

it('registers notifications.prune daily', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.prune');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.prune".');
    expect($event->expression)->toBe('0 0 * * *');
    expect((string) $event->command)->toContain('notifications:prune');
    expect($event->mutexName())->not->toBe('');
});

// The ordering the 09:15 slot bought — FX first, so a converted figure in the
// digest uses the day's rate — now comes from the window, not the cron.
it('keeps the daily notification pass behind fx.daily-refresh', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    expect(swtFindEvent($schedule, 'fx.daily-refresh')?->expression)->toBe('0 0 * * *');
    expect(EmitDailyNotificationTriggersCommand::LOCAL_TIME)->toBe('09:15');
});

it('lets the schedule filter through only on the first tick at or after 09:15', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.daily-triggers');
    expect($event)->not->toBeNull();

    CarbonImmutable::setTestNow('2026-08-29 09:00:00');
    expect($event->filtersPass($this->app))->toBeFalse();

    CarbonImmutable::setTestNow('2026-08-29 09:15:00');
    expect($event->filtersPass($this->app))->toBeTrue();

    $this->app->make(DailyLocalWindow::class)->claim(
        EmitDailyNotificationTriggersCommand::WINDOW_KEY,
        EmitDailyNotificationTriggersCommand::LOCAL_TIME,
    );

    CarbonImmutable::setTestNow('2026-08-29 09:30:00');
    expect($event->filtersPass($this->app))->toBeFalse();
});

it('registers exactly one entry per surviving name', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    foreach (['notifications.daily-triggers', 'notifications.budget-nudges', 'notifications.prune'] as $name) {
        $matches = array_filter(
            $schedule->events(),
            static fn (ScheduledEvent $event): bool => $event->description === $name,
        );

        expect($matches)->toHaveCount(1, "Expected exactly one registered schedule entry named \"{$name}\".");
    }
});
