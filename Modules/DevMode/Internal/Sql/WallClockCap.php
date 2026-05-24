<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

/**
 * Single-method wrapper around set_time_limit() so the
 * ReadOnlySqliteConnection's wall-clock cap is a mockable seam.
 *
 * Why a wrapper exists:
 *
 *   - PDO::ATTR_TIMEOUT is documented as connection-only (lock-wait
 *     timeout) on SQLite, NOT as a query-duration cap.
 *     set_time_limit($n) is the reliable coarse-grained cap.
 *
 *   - Unit tests need to assert that the cap was applied with the
 *     correct value — without actually invoking set_time_limit()
 *     during the test (which would interact with the test runner's
 *     own execution-time budget). A one-method DI seam lets a test
 *     double override apply() and capture the call.
 *
 * The class is NOT `final` so test doubles can extend it.
 */
class WallClockCap
{
    public function apply(int $seconds): void
    {
        set_time_limit($seconds);
    }
}
