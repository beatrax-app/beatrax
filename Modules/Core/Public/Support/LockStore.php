<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class LockStore
{
    public static function forUniqueJobs(): Repository
    {
        $store = config('cache.locks_store');
        if (! is_string($store) || $store === '') {
            throw new RuntimeException(
                'cache.locks_store must be a non-empty store name; got: '.var_export($store, true),
            );
        }

        return Cache::store($store);
    }
}
