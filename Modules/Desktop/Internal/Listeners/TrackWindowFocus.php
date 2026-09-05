<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\WindowFocusState;

// A named class rather than the two closures it replaces. A shell event's
// handler has to be something an arch guard can walk to the end of, and a
// closure body is not: it is the one binding shape that carries code no import
// scan can follow.
final readonly class TrackWindowFocus
{
    public function __construct(
        private WindowFocusState $focus,
    ) {}

    public function handleFocused(): void
    {
        $this->focus->markFocused();
    }

    public function handleBlurred(): void
    {
        $this->focus->markBlurred();
    }
}
