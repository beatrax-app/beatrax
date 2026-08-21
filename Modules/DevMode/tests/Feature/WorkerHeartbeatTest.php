<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;

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

it('fires the heartbeat closure when the Looping event dispatches (verifies queue:work tick wires through)', function (): void {
    /** @var CacheRepository $cache */
    $cache = app(CacheRepository::class);
    $cache->forget(WriteWorkerHeartbeat::CACHE_KEY);

    expect($cache->has(WriteWorkerHeartbeat::CACHE_KEY))->toBeFalse();

    // Worker::daemon fires this same event at the top of every loop
    // iteration, and QueueManager::looping() is a listen() on it — so a
    // registration wired to a different event class shows up as a miss here.
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Looping('sync', 'default', new WorkerOptions));

    expect($cache->has(WriteWorkerHeartbeat::CACHE_KEY))->toBeTrue();
});
