<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

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

it('registers notifications.reminders daily at 09:15', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.reminders');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.reminders".');
    expect($event->expression)->toBe('15 9 * * *');
    expect($event->mutexName())->not->toBe('');
});

it('registers notifications.digest daily at 09:15', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.digest');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.digest".');
    expect($event->expression)->toBe('15 9 * * *');
    expect($event->mutexName())->not->toBe('');
});

it('registers notifications.budget-nudges hourly', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.budget-nudges');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.budget-nudges".');
    expect($event->expression)->toBe('0 * * * *');
    expect($event->mutexName())->not->toBe('');
});

it('registers notifications.savings-prompts daily at 09:15', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.savings-prompts');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.savings-prompts".');
    expect($event->expression)->toBe('15 9 * * *');
    expect($event->mutexName())->not->toBe('');
});

it('registers notifications.prune daily at 04:30', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $event = swtFindEvent($schedule, 'notifications.prune');

    expect($event)->not->toBeNull('Expected a registered schedule entry with description "notifications.prune".');
    expect($event->expression)->toBe('30 4 * * *');
    expect($event->mutexName())->not->toBe('');
});

it('does not collide with fx.daily-refresh (09:00), db.backup-daily (03:00), or counterparties.gc (04:00)', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $fx = swtFindEvent($schedule, 'fx.daily-refresh');
    $backup = swtFindEvent($schedule, 'db.backup-daily');
    $gc = swtFindEvent($schedule, 'counterparties.gc');

    expect($fx)->not->toBeNull();
    expect($backup)->not->toBeNull();
    expect($gc)->not->toBeNull();

    expect($fx->expression)->toBe('0 9 * * *');
    expect($backup->expression)->toBe('0 3 * * *');
    expect($gc->expression)->toBe('0 4 * * *');

    $notificationExpressions = [
        'notifications.reminders' => swtFindEvent($schedule, 'notifications.reminders')?->expression,
        'notifications.digest' => swtFindEvent($schedule, 'notifications.digest')?->expression,
        'notifications.budget-nudges' => swtFindEvent($schedule, 'notifications.budget-nudges')?->expression,
        'notifications.savings-prompts' => swtFindEvent($schedule, 'notifications.savings-prompts')?->expression,
        'notifications.prune' => swtFindEvent($schedule, 'notifications.prune')?->expression,
    ];

    foreach ($notificationExpressions as $name => $expression) {
        expect($expression)->not->toBeNull("Expected {$name} to be registered.");
        expect($expression)->not->toBe($fx->expression, "{$name} must not collide with fx.daily-refresh's window.");
        expect($expression)->not->toBe($backup->expression, "{$name} must not collide with db.backup-daily's window.");
        expect($expression)->not->toBe($gc->expression, "{$name} must not collide with counterparties.gc's window.");
    }
});

it('registers no duplicate schedule entry names across the five new entries', function (): void {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);

    $names = [
        'notifications.reminders',
        'notifications.digest',
        'notifications.budget-nudges',
        'notifications.savings-prompts',
        'notifications.prune',
    ];

    foreach ($names as $name) {
        $matches = array_filter(
            $schedule->events(),
            static fn (ScheduledEvent $event): bool => $event->description === $name,
        );

        expect($matches)->toHaveCount(1, "Expected exactly one registered schedule entry named \"{$name}\".");
    }
});
