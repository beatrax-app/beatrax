<?php

declare(strict_types=1);

namespace Modules\Position\Public\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Position\Internal\Jobs\EmitPositionDigestJob;

// The seam the notification pass reaches this module through. A cadence of
// `off` still dispatches: EmitPositionDigestJob returns immediately on it,
// keeping that decision in the one place that already owns it.
final readonly class PositionDigestDispatch
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    public function forUser(int $userId, DigestCadence $cadence): void
    {
        $this->bus->dispatch(new EmitPositionDigestJob($userId, $cadence));
    }

    // In-process, for a caller whose own session holds the app-lock key: a
    // digest queued from a request would be sealed by a worker that has no
    // session of its own, which is where the last one was refused.
    public function forUserNow(int $userId, DigestCadence $cadence): void
    {
        $this->bus->dispatchSync(new EmitPositionDigestJob($userId, $cadence));
    }
}
