<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;

/**
 * Default `Clock` implementation backed by the system clock. Uses
 * `CarbonImmutable::now()` — the class static method, not the `now()` helper.
 */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
