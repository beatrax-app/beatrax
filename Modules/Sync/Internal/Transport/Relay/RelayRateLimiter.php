<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Transport\BoundedSourceWindows;

// Per-source-IP fixed-window throttle for EVERY relay endpoint, not just the
// open deliver one it was named for: drain and confirm each resolve a row
// from the database inside their own auth check, so an unauthenticated flood
// cost a query per request. In-process; the daemon outlives the window map.
final class RelayRateLimiter
{
    public const int MAX_PER_WINDOW = 120;

    // How many distinct source IPs may be held at once. The map enforces this
    // by evicting its oldest window, rather than only sweeping expired ones —
    // a burst of addresses inside a single window expires nothing, and this is
    // the endpoint a stranger reaches without authenticating at all.
    private const int MAX_TRACKED_SOURCES = 4096;

    private readonly BoundedSourceWindows $windows;

    public function __construct(private readonly Clock $clock)
    {
        $this->windows = new BoundedSourceWindows(self::MAX_TRACKED_SOURCES, self::MAX_PER_WINDOW);
    }

    // True while the source is under its per-window budget. A source first
    // seen (or whose window has rolled over) opens a fresh window; a source
    // already at the cap inside a live window is refused (caller returns 429).
    public function allow(string $sourceKey): bool
    {
        return $this->windows->admits($sourceKey, $this->clock->now()->getTimestamp());
    }
}
