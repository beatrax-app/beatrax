<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// cache.locks_store does not name a store. Every ShouldBeUnique job resolves
// its lock through this, so an unusable value has to stop the job rather than
// fall back to a default — two workers holding no shared lock would each run
// the same sync or scan against the same rows.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class LockStoreNotConfiguredException extends RuntimeException {}
