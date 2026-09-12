<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\AdoptedBooking;
use Modules\Ledger\Models\ImportRun;

interface CapturesImportForSync
{
    // Called post-commit; an implementation must not throw into the import,
    // because a device that cannot capture has still imported.
    public function capture(ImportRun $importRun, User $user): void;

    // The rows a restatement moved, which the call above cannot carry: it
    // announces whole-row creates, and a create naming a row the peer already
    // holds is discarded. These travel as Sets, and the four terms of one
    // booking travel together or the peer seats the row on a mixture.
    /**
     * @param  list<AdoptedBooking>  $adopted
     */
    public function captureAdoptedBookings(array $adopted, User $user): void;
}
