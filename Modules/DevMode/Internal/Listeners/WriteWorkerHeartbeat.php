<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\Core\Public\Contracts\Clock;

/**
 * Queue-worker liveness heartbeat producer.
 *
 * Wired via QueueManager::looping(closure) inside
 * DevModeServiceProvider::boot() — every queue-worker loop tick the
 * closure fires and writes `dev_mode.queue_worker_heartbeat` =
 * <timestamp> into the cache with a 60-second TTL.
 *
 * The runner page's pre-flight pill reads the cache key and shows
 * green when the timestamp is fresh (< now() - 60s), grey otherwise.
 *   - Fresh ≤ 60s after the worker's last loop → "Queue worker: RUNNING"
 *   - Stale or missing                          → "Queue worker: NOT RUNNING"
 *
 * Important: this listener is invoked via the
 * QueueManager::looping(closure) registration form, NOT via
 * $events->listen(Looping::class, ...). Laravel's queue:work does
 * not reliably dispatch Illuminate\\Queue\\Events\\Looping per loop
 * iteration; only the closure-form callbacks fire.
 */
final readonly class WriteWorkerHeartbeat
{
    /** Cache key the runner page's pre-flight pill reads. */
    public const CACHE_KEY = 'dev_mode.queue_worker_heartbeat';

    /** 60s TTL — the "alive within 60s" freshness window. */
    public const TTL_SECONDS = 60;

    public function __construct(
        private CacheRepository $cache,
        private Clock $clock,
    ) {}

    public function __invoke(): void
    {
        $this->cache->put(
            self::CACHE_KEY,
            $this->clock->now()->getTimestamp(),
            self::TTL_SECONDS,
        );
    }
}
