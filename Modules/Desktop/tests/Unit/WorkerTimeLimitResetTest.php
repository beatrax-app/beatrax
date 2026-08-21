<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;

// NativePHP spawns the bundled `queue:work` daemon under a 120s wall-clock
// ceiling. On Windows ext-pcntl is absent, so Laravel's alarm-based timeout
// never arms and the long-lived worker accrues 120s of real time before the
// SAPI kills it mid-job; a looping callback resets the limit every tick.
it('resets the execution time limit to unlimited on every queue worker loop', function (): void {
    // Seed a finite ceiling: the CLI SAPI defaults max_execution_time to 0, which
    // would mask a no-op callback. ini_set rather than set_time_limit keeps the
    // test runner from flagging a risky timeout mutation.
    ini_set('max_execution_time', '120');
    expect((int) ini_get('max_execution_time'))->toBe(120);

    // Fire the event the running worker emits between jobs.
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new Looping(connectionName: 'database', queue: 'default'));

    expect((int) ini_get('max_execution_time'))->toBe(0);
});
