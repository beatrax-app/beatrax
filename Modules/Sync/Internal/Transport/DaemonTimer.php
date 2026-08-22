<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Closure;

// "Do this every N seconds, until I stop you", in this codebase's own terms.
// The daemon that answers pairing traffic now hangs the pending-pairing courier
// on one of these, so what a ceremony needs in order to finish is a scheduled
// tick — and a test can supply that tick without an event loop underneath it.
interface DaemonTimer
{
    // Idempotent: a second call while a tick is already scheduled is ignored,
    // so a caller cannot accidentally double the rate.
    public function every(float $seconds, Closure $tick): void;

    public function stop(): void;
}
