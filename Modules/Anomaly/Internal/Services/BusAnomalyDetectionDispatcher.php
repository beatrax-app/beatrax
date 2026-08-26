<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Anomaly\Internal\Jobs\DetectAnomaliesJob;
use Modules\Anomaly\Public\Contracts\DispatchesAnomalyDetection;

// Exists so the concrete job class stays inside Anomaly\Internal\Jobs, the same
// seam Chains and Recurring reach the importer through.

/**
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final readonly class BusAnomalyDetectionDispatcher implements DispatchesAnomalyDetection
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatchForImportRun(int $userId, int $importRunId): void
    {
        $this->bus->dispatch(new DetectAnomaliesJob(userId: $userId, importRunId: $importRunId));
    }
}
