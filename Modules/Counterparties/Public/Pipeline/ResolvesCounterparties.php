<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Pipeline;

use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Public Counterparties pipeline contract — the DI seam ImportPipeline
 * consumes to stamp `transactions.counterparty_id` on every canonical
 * row mid-pipeline. The default implementation lives at
 * `Modules\Counterparties\Internal\Pipeline\ResolveCounterpartyStage`
 * and is bound by `CounterpartiesServiceProvider`.
 *
 * Keeping the stage behind a Public contract is what lets
 * `Modules\Import\Internal\Pipeline\ImportPipeline` consume the
 * counterparty-resolution glue without crossing into
 * `Modules\Counterparties\Internal\*` — the project-wide module-boundary
 * arch invariant in `tests/Contracts/BoundaryArchTest.php` forbids any
 * such reach.
 *
 * Implementations MUST be side-effect-free on resolver failure: a
 * buggy resolver returns the input transaction unmodified rather than
 * aborting the import. The contract returns a (potentially new)
 * CanonicalTransaction so callers can compose the result back into
 * the per-row loop's `$normalized` accumulator without inspecting the
 * resolution outcome.
 */
interface ResolvesCounterparties
{
    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction;
}
