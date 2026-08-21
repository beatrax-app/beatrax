<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Services;

use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

// Exists so the concrete job class stays inside Recurring\Internal\Jobs; every
// call site is an unlocked HTTP request, which is what dispatchSync needs.

/**
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 *
 * @link ../../../../.docs/features/recurring/detection-encryption-posture.md#the-two-dispatch-origins
 */
final class BusRecurringDetectionDispatcher implements DispatchesRecurringDetection
{
    public function dispatchForUser(int $userId): void
    {
        DetectRecurringSeriesJob::dispatchSync($userId);
    }
}
