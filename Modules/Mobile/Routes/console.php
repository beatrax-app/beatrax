<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

// `onAnyNetwork` is a macro nativephp/mobile-background-tasks registers, and
// only the mobile composer root requires that package — so this guard is what
// makes the entry below mobile-only without asking the runtime anything.
if (Event::hasMacro('onAnyNetwork')) {
    // The bounded sync burst is NOT scheduled here, and
    // MobileBackgroundSchedule::impossibleOnDevice() carries the reason: an
    // OS-scheduled tick holds no session, so it holds no app-lock key, so the
    // device identity it would sync with never opens.

    // The phone's only queue worker is a thread MainActivity starts, so with
    // the app closed every job the app-root schedule dispatches waits in
    // `jobs` until it is next opened. Bounded, and safe beside the in-app
    // worker because a reserved job cannot be reserved twice.
    Schedule::command('queue:work --stop-when-empty --max-time=55 --quiet')
        ->name('mobile.queue-drain')
        ->everyFifteenMinutes()
        ->withoutOverlapping(10);
}
