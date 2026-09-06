<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

// The per-source fixed-window map both sync limiters count in, holding its own
// size as well as their budgets. It lives here rather than in each of them
// because the eviction below is one idea, and a third copy of a map that
// already differed only in its two numbers is how the first two drifted.
final class BoundedSourceWindows
{
    /** @var array<string, array{start: int, count: int}> */
    private array $windows = [];

    public function __construct(
        private readonly int $maxTrackedSources,
        private readonly int $maxPerWindow,
    ) {}

    // True while the source is under its budget for the current window. A
    // source first seen, or one whose window has rolled over, opens a fresh
    // window; one already at the budget inside a live window is refused.
    public function admits(string $sourceKey, int $now): bool
    {
        $window = $this->windows[$sourceKey] ?? null;

        if ($window === null || $now - $window['start'] >= FixedWindowThrottle::windowSeconds()) {
            $this->openWindow($sourceKey, $now);

            return true;
        }

        if ($window['count'] >= $this->maxPerWindow) {
            return false;
        }

        $this->windows[$sourceKey]['count'] = $window['count'] + 1;

        return true;
    }

    // Removed before it is written rather than overwritten where it sits, so
    // the map's insertion order stays the order its windows opened in. That is
    // the whole reason the eviction below can take the oldest window without
    // scanning for it, and it keeps a rollover off the front of the queue.
    private function openWindow(string $sourceKey, int $now): void
    {
        unset($this->windows[$sourceKey]);

        $this->makeRoom($now);

        $this->windows[$sourceKey] = ['start' => $now, 'count' => 1];
    }

    // Expiry first, because a window whose duration has elapsed is dead and a
    // live one is not. A burst of distinct keys inside one window frees nothing
    // that way, so eviction is what stands between the map and one entry per
    // source a stranger cares to spell — at the cost of that source's budget.
    private function makeRoom(int $now): void
    {
        if (count($this->windows) < $this->maxTrackedSources) {
            return;
        }

        foreach ($this->windows as $key => $window) {
            if ($now - $window['start'] >= FixedWindowThrottle::windowSeconds()) {
                unset($this->windows[$key]);
            }
        }

        while ($this->windows !== [] && count($this->windows) >= $this->maxTrackedSources) {
            unset($this->windows[array_key_first($this->windows)]);
        }
    }
}
