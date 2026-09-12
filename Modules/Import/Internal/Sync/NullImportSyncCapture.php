<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Sync;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Ledger\Models\ImportRun;

final class NullImportSyncCapture implements CapturesImportForSync
{
    // The default when Sync is not loaded: an import on a device that cannot
    // sync is still a complete import.
    public function capture(ImportRun $importRun, User $user): void {}

    // And a restatement on that device is a complete restatement: with no
    // Sync module there is no peer holding a second copy of the row, so there
    // is no second copy to move onto the day the bank booked it.
    public function captureAdoptedBookings(array $adopted, User $user): void {}
}
