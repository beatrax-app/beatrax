<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Modules\Import\Public\Events\TransactionImported;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use RuntimeException;

/**
 * Deterministic pair-detection event listener.
 *
 * Subscribes to TransactionImported (fired synchronously inside the
 * Ledger module's RecordTransactions action, in the outer DB
 * transaction frame) and delegates the single source of truth for
 * matching to {@see PairsTransferLegs::pairOne()}.
 *
 * The listener was extracted from a former handler that owned the
 * match logic directly. The bulk-orphan-sweep path used by the Chains
 * module's ResolveChainLinksJob needs the same matcher; rather than
 * duplicate, the matcher moved to a shared service and this listener
 * became a thin event-to-service adapter.
 *
 * Listener invariants — unchanged from the pre-refactor handler:
 *
 *   - Same user (cross-user pairing is impossible — every query inside
 *     `pairOne()` filters on `$user->id`).
 *   - The listener is NOT `ShouldHandleEventsAfterCommit` and NOT
 *     `ShouldQueue`: same-import-batch pair-detection requires
 *     observing partner rows that were inserted earlier in the same
 *     outer transaction.
 *
 * The user-id cross-check below catches a regression in event
 * construction (the payload's user is supposed to be the row's owner;
 * anything else is a bug in the dispatcher). The matcher itself would
 * still scope correctly, but failing loud here keeps the invariant
 * one layer closer to its violation.
 */
final readonly class PairTransferCandidates
{
    public function __construct(
        private PairsTransferLegs $pairer,
    ) {}

    public function handle(TransactionImported $event): void
    {
        if ($event->transaction->user_id !== $event->user->id) {
            throw new RuntimeException(
                'TransactionImported.user.id does not match transaction.user_id — refusing to pair.'
            );
        }

        $this->pairer->pairOne($event->transaction, $event->user);
    }
}
