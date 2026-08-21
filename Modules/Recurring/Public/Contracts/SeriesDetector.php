<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Contracts;

use Modules\Core\Models\User;

// Implementations are analytical only: they must never write the transactions
// table, and must never be imported from Internal\Http or Resources. The job
// that drives them is the sole entry point, whether queued or dispatched sync.

interface SeriesDetector
{
    public function detectForUser(User $user): void;
}
