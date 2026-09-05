<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Transport\BoundedSourceWindows;

// Per-source-IP fixed-window throttle, so a pairing route cannot be used as a
// cheap probe for whether a pairing is in flight. Each route holds its OWN
// instance: one bucket for a human typing and a phone polling let the timer
// starve the human within half a minute.
final class PairingOfferRateLimiter
{
    // A human types one code, gets it wrong once or twice, and is done.
    // Anything past a handful inside a minute is not that.
    public const int MAX_PER_WINDOW = 10;

    // How many distinct source keys may be held at once. The map enforces this
    // by evicting its oldest window, rather than only sweeping expired ones —
    // a burst of keys inside a single window expires nothing, and a limiter
    // that grows for each of them is the exhaustion vector it guards against.
    private const int MAX_TRACKED_SOURCES = 1024;

    private readonly BoundedSourceWindows $windows;

    public function __construct(
        private readonly Clock $clock,
        int $maxPerWindow = self::MAX_PER_WINDOW,
    ) {
        $this->windows = new BoundedSourceWindows(self::MAX_TRACKED_SOURCES, $maxPerWindow);
    }

    // A sibling limiter on the same clock with its own window map, for a route
    // whose legitimate caller is not a human hand.
    public function withLimit(int $maxPerWindow): self
    {
        return new self($this->clock, $maxPerWindow);
    }

    public function allow(string $sourceKey): bool
    {
        return $this->windows->admits($sourceKey, $this->clock->now()->getTimestamp());
    }
}
