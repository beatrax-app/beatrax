<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

// set_time_limit() is the actual query-duration cap — PDO::ATTR_TIMEOUT only
// bounds the connection's lock wait. Wrapped, and deliberately not `final`, so
// a test double can assert the cap without burning the runner's own budget.
class WallClockCap
{
    public function apply(int $seconds): void
    {
        set_time_limit($seconds);
    }
}
