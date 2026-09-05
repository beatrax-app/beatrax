<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * @link ../../../../.docs/features/desktop/architecture.md#state-a-shell-event-writes-does-not-outlive-its-request
 */
// The shell serves the app through `php -S`, so every _native/api/events POST
// runs its own PHP process state and gets its own container. A fact one shell
// event has to hand to the next one — or to the window — cannot live on an
// object, however carefully that object is singleton-bound. It lives here.
final readonly class ShellState
{
    // The database store, not the configured default: CACHE_STORE is `file`
    // here, and neither an OS-supplied document path nor the counter that
    // decides whether a critical alert fires belongs in storage/framework at
    // whatever the umask of the day allows. The app's own SQLite file is not.
    private const string STORE = 'database';

    public function __construct(
        private CacheFactory $cache,
    ) {}

    /**
     * @return array<array-key, mixed>|null
     */
    public function read(string $slot): ?array
    {
        $fact = $this->store()->get($slot);

        return is_array($fact) ? $fact : null;
    }

    /**
     * @param  array<array-key, scalar>  $fact
     */
    public function write(string $slot, array $fact, ?int $ttlSeconds = null): void
    {
        if ($ttlSeconds === null) {
            $this->store()->forever($slot, $fact);
        } else {
            $this->store()->put($slot, $fact, $ttlSeconds);
        }
    }

    public function forget(string $slot): void
    {
        $this->store()->forget($slot);
    }

    private function store(): CacheRepository
    {
        return $this->cache->store(self::STORE);
    }
}
