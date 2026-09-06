<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;

// A clock the case moves by hand. The subject is how long a reader goes on
// believing an active row's stamp, so the passage of time is the input, not
// something the test waits for.
final class StrandedSessionClock implements Clock
{
    public function __construct(private CarbonImmutable $at) {}

    public function now(): CarbonImmutable
    {
        return $this->at;
    }

    public function travelTo(CarbonImmutable $at): void
    {
        $this->at = $at;
    }
}
