<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

// Wraps set_time_limit() as a mockable seam: PDO::ATTR_TIMEOUT is
// connection-only (lock-wait), not a query-duration cap, so this is the
// real mechanism; tests need to assert it without touching the runner's
// own execution-time budget. NOT `final` so test doubles can extend it.
class WallClockCap
{
    public function apply(int $seconds): void
    {
        set_time_limit($seconds);
    }
}
