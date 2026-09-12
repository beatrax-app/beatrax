<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Clock;

// What the clock read when an entry was allocated. The two halves only ever
// travel together and neither means anything alone: a physical millisecond
// orders nothing without the counter that breaks its ties, and a counter
// orders nothing without the millisecond it counts within.
final readonly class HlcStamp
{
    public function __construct(
        public int $l,
        public int $c,
    ) {}
}
