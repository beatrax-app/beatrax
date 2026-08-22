<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Closure;
use Revolt\EventLoop;

// The repeating timer a daemon hangs periodic work on, behind a seam because the
// event loop is a third-party thing. Overlap is refused here rather than by each
// caller: work on this loop is synchronous, so a slow tick must cost one delay,
// never a queue of ticks behind it.
final class DaemonTicker implements DaemonTimer
{
    private bool $running = false;

    private ?string $callbackId = null;

    // $tick is responsible for its own failures: anything thrown escapes into
    // the loop's error handler, which is not where a daemon wants to find out.
    public function every(float $seconds, Closure $tick): void
    {
        if ($this->callbackId !== null) {
            return;
        }

        $this->callbackId = EventLoop::repeat($seconds, function () use ($tick): void {
            if ($this->running) {
                return;
            }

            $this->running = true;

            try {
                $tick();
            } finally {
                $this->running = false;
            }
        });
    }

    public function stop(): void
    {
        if ($this->callbackId === null) {
            return;
        }

        EventLoop::cancel($this->callbackId);
        $this->callbackId = null;
    }
}
