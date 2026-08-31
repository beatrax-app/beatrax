<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Public\Exceptions\LockStoreNotConfiguredException;

final class LockStore
{
    public static function forUniqueJobs(): Repository
    {
        $store = config('cache.locks_store');
        if (! is_string($store) || $store === '') {
            throw new LockStoreNotConfiguredException(
                'cache.locks_store must be a non-empty store name; got: '.var_export($store, true),
            );
        }

        return Cache::store($store);
    }

    // uniqueVia() has to hand back a Repository, and Repository forwards lock()
    // to its store through __call — so a caller taking a lock has no typed door
    // and gets no error when the configured store cannot provide one.
    public static function lockProvider(): LockProvider
    {
        $store = self::forUniqueJobs()->getStore();

        if (! $store instanceof LockProvider) {
            throw new LockStoreNotConfiguredException(
                'cache.locks_store names a store that cannot provide locks: '.$store::class,
            );
        }

        return $store;
    }
}
