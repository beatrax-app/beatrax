<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\EmailScan\Internal\Jobs\DetectIcsStatementReadyJob;

// The seam the notification replay reaches this module through, so nothing
// outside EmailScan has to name DetectIcsStatementReadyJob.
final readonly class IcsStatementReadyDispatch
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    // In-process, for a caller whose own session holds the app-lock key: the
    // job only reads inbox_messages and dispatches, so re-running it IS the
    // reconciliation from the rows the keyless scan already filed. Queueing it
    // would hand it back to the worker that could not seal it the first time.
    public function forUserNow(int $userId): void
    {
        $this->bus->dispatchSync(new DetectIcsStatementReadyJob($userId));
    }
}
