<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Contracts;

use Modules\Core\Models\User;

// Implementations MUST NOT write the transactions table (analytical-only;
// enforced by the noTransactionWritesFromRecurring arch test) and must
// never be imported from Internal\Http or Resources — detector work runs
// on the queue, never in the request lifecycle.

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
interface SeriesDetector
{
    public function detectForUser(User $user): void;
}
