<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;

/*
 * Regression: the NativePHP desktop worker must reset PHP's execution-time
 * limit to unlimited on every queue loop.
 *
 * NativePHP spawns the bundled `queue:work` daemon with a 120s wall-clock
 * ceiling (NativeAppServiceProvider::phpIni()). On Windows ext-pcntl is
 * absent, so Laravel's per-job alarm-based timeout never arms and nothing
 * resets the limit per loop — the long-lived worker accrues 120s of real
 * time and the SAPI kills it mid-job.
 *
 * DesktopServiceProvider::boot() registers a QueueManager::looping() callback
 * that calls set_time_limit(0) every loop. QueueManager::looping() wires the
 * callback as a listener on Illuminate\Queue\Events\Looping, which the
 * framework Worker dispatches at the top of every tick, so dispatching that
 * event here drives the real registered reset — the same end-to-end seam the
 * DevMode WorkerHeartbeatTest exercises. This asserts behaviour, not platform
 * (the Docker toolchain has pcntl, which is fine; the callback is
 * platform-independent).
 *
 * @see \Modules\Desktop\Providers\DesktopServiceProvider
 */
it('resets the execution time limit to unlimited on every queue worker loop', function (): void {
    // Seed a finite ceiling so a passing assertion can only mean the callback
    // actively drove the limit to 0 — the CLI SAPI defaults max_execution_time
    // to 0, which would otherwise mask a no-op. ini_set (not set_time_limit)
    // avoids the test runner flagging a risky timeout-mutation warning.
    ini_set('max_execution_time', '120');
    expect((int) ini_get('max_execution_time'))->toBe(120);

    // Fire the event the running worker emits between jobs.
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new Looping(connectionName: 'database', queue: 'default'));

    expect((int) ini_get('max_execution_time'))->toBe(0);
});
