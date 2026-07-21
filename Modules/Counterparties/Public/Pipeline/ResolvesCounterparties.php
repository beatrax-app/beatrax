<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Pipeline;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The Public seam ImportPipeline consumes without reaching into
// Modules\Counterparties\Internal (BoundaryArchTest enforces this).
// Implementations MUST be side-effect-free on resolver failure: return
// the transaction unmodified rather than aborting the import.
/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
interface ResolvesCounterparties
{
    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction;
}
