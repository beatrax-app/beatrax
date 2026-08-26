<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Contracts;

interface DispatchesAnomalyDetection
{
    public function dispatchForImportRun(int $userId, int $importRunId): void;
}
