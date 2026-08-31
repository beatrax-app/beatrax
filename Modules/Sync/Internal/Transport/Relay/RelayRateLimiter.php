<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Transport\FixedWindowThrottle;

// Per-source-IP fixed-window throttle for EVERY relay endpoint, not just the
// open deliver one it was named for: drain and confirm each resolve a row
// from the database inside their own auth check, so an unauthenticated flood
// cost a query per request. In-process; the daemon outlives the window map.
final class RelayRateLimiter
{
    public const int MAX_PER_WINDOW = 120;

    // Cap on distinct source keys held at once: the prune sweep only runs
    // when the map grows past this, so steady traffic never pays for it.
    private const int MAX_TRACKED_SOURCES = 4096;

    /** @var array<string, array{start: int, count: int}> */
    private array $windows = [];

    public function __construct(private readonly Clock $clock) {}

    // True while the source is under its per-window budget. A source first
    // seen (or whose window has rolled over) opens a fresh window; a source
    // already at the cap inside a live window is refused (caller returns 429).
    public function allow(string $sourceKey): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $window = $this->windows[$sourceKey] ?? null;

        if ($window === null || $now - $window['start'] >= FixedWindowThrottle::windowSeconds()) {
            if ($window === null && count($this->windows) >= self::MAX_TRACKED_SOURCES) {
                $this->pruneExpired($now);
            }

            $this->windows[$sourceKey] = ['start' => $now, 'count' => 1];

            return true;
        }

        if ($window['count'] >= self::MAX_PER_WINDOW) {
            return false;
        }

        $this->windows[$sourceKey]['count'] = $window['count'] + 1;

        return true;
    }

    // Drops every window whose full duration has elapsed so the map cannot
    // grow one permanent entry per source IP ever seen — the limiter must not
    // itself become the resource-exhaustion vector it guards against.
    private function pruneExpired(int $now): void
    {
        foreach ($this->windows as $key => $window) {
            if ($now - $window['start'] >= FixedWindowThrottle::windowSeconds()) {
                unset($this->windows[$key]);
            }
        }
    }
}
