<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Single sanctioned resolver of the cache store that backs queue-uniqueness
 * locks for `ShouldBeUniqueUntilProcessing` jobs.
 *
 * This is the only module file that uses the `Cache` facade and the `config()`
 * helper. The DI-only rule cannot apply here: Laravel invokes
 * `ShouldBeUniqueUntilProcessing::uniqueVia()` at queue-push time, before the
 * job's constructor DI has run, so an injected `Repository` is not reachable
 * from a `uniqueVia()` body. Confining that facade crossing to this one file
 * keeps the `BoundaryArchTest` facade carve-out a single allow-listed entry.
 *
 * The store name comes from `config('cache.locks_store')`: `database` in
 * shipped builds (SQLite-backed `cache_locks` table, no Redis daemon) and
 * `redis` on the developer's Herd box. `Cache::store()` resolves a named store
 * from `config('cache.stores')`.
 */
final class LockStore
{
    /**
     * Resolve the cache repository that `uniqueVia()` callbacks lock against.
     */
    public static function forUniqueJobs(): Repository
    {
        /** @var string $store */
        $store = config('cache.locks_store');

        return Cache::store($store);
    }
}
