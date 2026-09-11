<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

// Retiring the live database connection, and everything holding it. `purge()`
// alone takes the connection off the manager and not out of the services that
// already resolved it, and the framework then repairs the orphan by handing it
// the live connection's PDO with a transaction counter of its own.
/**
 * @link ../../../../.docs/features/core/a-purged-connection-two-services-still-hold.md
 */
final readonly class LiveConnectionPurge
{
    private const string DATABASE_DRIVER = 'database';

    private const string RESOLVED_DEFAULT_STORE = 'cache.store';

    public function __construct(
        private DatabaseManager $db,
        private CacheManager $cache,
        private ConfigRepository $config,
        private Container $container,
    ) {}

    public function purge(string $connection): void
    {
        $this->db->purge($connection);

        // The cache is the holder that matters: its stores are built while
        // providers boot, so they exist before any purge, and `increment()`
        // opens a transaction of its own. The session and queue drivers
        // resolve per request, after this has run.
        $this->cache->forgetDriver($this->storesOverTheDatabase());
        $this->container->forgetInstance(self::RESOLVED_DEFAULT_STORE);
    }

    /**
     * @return list<string>
     */
    private function storesOverTheDatabase(): array
    {
        $stores = $this->config->get('cache.stores');

        if (! is_array($stores)) {
            return [];
        }

        $named = [];

        foreach ($stores as $name => $store) {
            if (is_string($name) && is_array($store) && ($store['driver'] ?? null) === self::DATABASE_DRIVER) {
                $named[] = $name;
            }
        }

        return $named;
    }
}
