<?php

declare(strict_types=1);

namespace Modules\Core\Public\Scheduling;

use Illuminate\Container\Container;
use WeakMap;

/**
 * @link ../../../../.docs/features/mobile/architecture.md#two-loaders-reach-the-console-routes-and-only-one-is-idempotent
 */
final class ScheduleRegistrationGuard
{
    /** @var WeakMap<Container, array<string, true>>|null */
    private static ?WeakMap $loaded = null;

    // Keyed on the application rather than on a plain static, because a test
    // process builds a new one per test and its Schedule starts empty: a
    // process-wide latch would leave every application after the first with no
    // schedule at all.
    public static function firstLoad(string $file): bool
    {
        /** @var WeakMap<Container, array<string, true>> $loaded */
        $loaded = self::$loaded ?? new WeakMap;
        self::$loaded = $loaded;

        $application = Container::getInstance();

        /** @var array<string, true> $seen */
        $seen = $loaded[$application] ?? [];

        if (isset($seen[$file])) {
            return false;
        }

        // Marked before the caller registers anything: one of the two loaders
        // fires from a container `resolving` hook, so a re-entrant load can
        // begin while the first is still running its own first line.
        $seen[$file] = true;
        $loaded[$application] = $seen;

        return true;
    }
}
