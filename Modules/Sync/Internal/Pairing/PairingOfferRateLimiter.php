<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Core\Public\Contracts\Clock;

// Per-source-IP fixed-window throttle for the two pairing routes. The token is
// 128 bits, so guessing one is already infeasible; this bounds the guessing
// anyway, and stops either endpoint being usable as a cheap probe for whether a
// pairing is currently in flight. In-process — the daemon is long-lived.
//
// Each route holds its OWN instance, so neither can spend the other's budget:
// the offer route is driven by a human typing a code, the frame route by a
// phone polling on a timer, and one bucket for both let the timer starve the
// human within half a minute.
final class PairingOfferRateLimiter
{
    // A human types one code, gets it wrong once or twice, and is done.
    // Anything past a handful inside a minute is not that.
    public const int MAX_PER_WINDOW = 10;

    private const int WINDOW_SECONDS = 60;

    // Cap on distinct source keys held at once: the prune sweep runs only
    // when the map grows past this, so ordinary traffic never pays for it.
    private const int MAX_TRACKED_SOURCES = 1024;

    /** @var array<string, array{start: int, count: int}> */
    private array $windows = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly int $maxPerWindow = self::MAX_PER_WINDOW,
    ) {}

    // A sibling limiter on the same clock with its own window map, for a route
    // whose legitimate caller is not a human hand.
    public function withLimit(int $maxPerWindow): self
    {
        return new self($this->clock, $maxPerWindow);
    }

    public function allow(string $sourceKey): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $window = $this->windows[$sourceKey] ?? null;

        if ($window === null || $now - $window['start'] >= self::WINDOW_SECONDS) {
            if ($window === null && count($this->windows) >= self::MAX_TRACKED_SOURCES) {
                $this->pruneExpired($now);
            }

            $this->windows[$sourceKey] = ['start' => $now, 'count' => 1];

            return true;
        }

        if ($window['count'] >= $this->maxPerWindow) {
            return false;
        }

        $this->windows[$sourceKey]['count'] = $window['count'] + 1;

        return true;
    }

    // Drops every window whose full duration has elapsed, so the map cannot
    // grow one permanent entry per source ever seen — a limiter must not
    // become the exhaustion vector it guards against.
    private function pruneExpired(int $now): void
    {
        foreach ($this->windows as $key => $window) {
            if ($now - $window['start'] >= self::WINDOW_SECONDS) {
                unset($this->windows[$key]);
            }
        }
    }
}
