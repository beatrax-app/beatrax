<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Listeners;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\Core\Public\Contracts\Clock;

// Registered through QueueManager::looping() in DevModeServiceProvider, inside
// a closure that re-resolves this listener from the container each tick.
final readonly class WriteWorkerHeartbeat
{
    public const CACHE_KEY = 'dev_mode.queue_worker_heartbeat';

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
