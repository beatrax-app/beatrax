<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Services;

use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;

// Wraps DetectRecurringSeriesJob::dispatchSync($userId) so the concrete
// job class stays inside Modules\Recurring\Internal\Jobs\ (other modules
// are forbidden from importing it directly). Every call site (ConfirmImport,
// FirstImportStep) is an unlocked HTTP/Livewire request — see the class @link.

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 *
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final class BusRecurringDetectionDispatcher implements DispatchesRecurringDetection
{
    public function dispatchForUser(int $userId): void
    {
        DetectRecurringSeriesJob::dispatchSync($userId);
    }
}
