<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Position\Internal\Jobs\EmitPositionDigestJob;

// ScheduleWiringTest proves the entry is registered at the right minute.
// Nothing ran the closure, so an argument the callee cannot accept stayed
// invisible: the scheduler reports the throw and the digest never fires.

function tdsRun(string $description): void
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        /** @var ScheduledEvent $event */
        if ($event->description === $description) {
            $event->run(app());

            return;
        }
    }

    throw new RuntimeException('No scheduled entry named '.$description);
}

it('dispatches a position digest for each user when the schedule fires', function (): void {
    Bus::fake();

    User::query()->create([
        'username' => 'tds-reader',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    tdsRun('notifications.digest');

    Bus::assertDispatched(EmitPositionDigestJob::class);
});
