<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;

/*
 * Phase 16-04b Task 2 — W-8 regression: the queue-worker heartbeat
 * MUST fire when the Illuminate\Queue\Events\Looping event is
 * dispatched (the event `queue:work` fires before every loop tick via
 * Worker::daemon). QueueManager::looping(closure) is sugar for
 * `events->listen(Looping::class, $closure)`; both register at the
 * same listener seam.
 *
 * The W-8 framing in the plan ("DI form vs event-listener form" not
 * firing) was specifically about Laravel 12 / earlier versions where
 * the looping callback was kept on a separate registry; in Laravel 13
 * the two paths are unified via the Event Dispatcher. We use the
 * closure form per the plan's directive; this test asserts the
 * heartbeat key appears in the cache after the Looping event fires.
 */

it('writes dev_mode.queue_worker_heartbeat to cache when invoked directly', function (): void {
    /** @var CacheRepository $cache */
    $cache = app(CacheRepository::class);
    $cache->forget(WriteWorkerHeartbeat::CACHE_KEY);

    expect($cache->has(WriteWorkerHeartbeat::CACHE_KEY))->toBeFalse();

    /** @var WriteWorkerHeartbeat $listener */
    $listener = app(WriteWorkerHeartbeat::class);
    ($listener)();

    expect($cache->has(WriteWorkerHeartbeat::CACHE_KEY))->toBeTrue();
    $value = $cache->get(WriteWorkerHeartbeat::CACHE_KEY);
    expect($value)->toBeInt();
    expect($value)->toBeGreaterThan(time() - 5);
});

it('fires the heartbeat closure when the Looping event dispatches (W-8 — verifies queue:work tick wires through)', function (): void {
    /** @var CacheRepository $cache */
    $cache = app(CacheRepository::class);
    $cache->forget(WriteWorkerHeartbeat::CACHE_KEY);

    expect($cache->has(WriteWorkerHeartbeat::CACHE_KEY))->toBeFalse();

    // Dispatching Looping mimics exactly what Worker::daemon does at
    // the top of every loop iteration:
    //   $this->events->until(new Looping($connection, $queue, $options));
    // Our QueueManager::looping(...) call inside DevModeServiceProvider
    // boot registered the heartbeat closure as a listener for THIS
    // event. If the registration is missing or wired to a different
    // event class, the cache key will not appear.
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Looping('sync', 'default', new WorkerOptions));

    expect($cache->has(WriteWorkerHeartbeat::CACHE_KEY))->toBeTrue();
});
