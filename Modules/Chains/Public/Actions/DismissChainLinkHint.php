<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action that permanently removes a hint-shaped `chain_link`
 * from the ledger.
 *
 * Hint kinds (`funded_by_card_hint`, `refund_of_hint`, and exceeded-
 * tolerance `ics_bulk_settle` candidates) are written by upstream
 * matchers in `state='candidate'` with `to_transaction_id=NULL` —
 * the schema's chain_links_to_transaction_id_check_* triggers permit
 * the NULL endpoint only under this exact carve-out. The
 * architectural design assumes a downstream resolver will eventually
 * find the partner row and promote the hint to a concrete
 * `state='confirmed'` chain_link, but in practice some hints never
 * find a partner (the data source the resolver expected does not
 * exist for this user's import shape). Those rows accumulate
 * indefinitely without a way for the user to clear them — the
 * standard Confirm / Reject buttons trip the trigger because both
 * state transitions require a non-NULL endpoint.
 *
 * Dismissal hard-deletes the row. This is safe because:
 *
 *   - Hints carry no downstream references: nothing FK's into
 *     chain_links from outside the table (chain_links.from_transaction_id
 *     and chain_links.to_transaction_id are the only FKs and they
 *     point OUT of the table). Removing a hint row therefore has no
 *     cascade effect.
 *
 *   - Hints are inherently re-emittable: the upstream matcher that
 *     produced the hint will produce an identical hint on the next
 *     import + chain pass IF the underlying conditions still hold.
 *     A user who dismisses by accident gets a fresh hint on the next
 *     resolver pass; one who dismisses on purpose stays dismissed
 *     because the matcher only fires on new receipts / new
 *     statements.
 *
 * Soft-delete via `state='rejected'` is structurally impossible:
 * the trigger refuses any state-flip on rows with NULL endpoint.
 * Adjusting the trigger to allow rejected+NULL would muddy the
 * invariant "every non-hint chain_link has both endpoints set"
 * which the IcsSettlementResolver + PaypalFundingResolver rely on.
 *
 * Cross-user safety: `firstOrFail`-style 404 when the row does not
 * belong to the calling user.
 *
 * Guard: throws RuntimeException when called against a non-hint row
 * (one with a concrete `to_transaction_id`). The Confirm / Reject
 * actions cover those rows; calling Dismiss on a regular candidate
 * would silently delete a row the user might have meant to keep.
 *
 * DI shape: no DatabaseManager — `delete()` routes through the
 * model's own connection. Consistent with `RejectChainLink`.
 */
final class DismissChainLinkHint
{
    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink|null $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            throw new NotFoundHttpException('Chain link not found.');
        }

        if ($link->to_transaction_id !== null) {
            // Only hint-shaped rows are dismissable; concrete rows
            // route through ConfirmChainLink / RejectChainLink.
            throw new RuntimeException(
                'DismissChainLinkHint refuses chain_link '.$chainLinkId
                    .' — has a concrete to_transaction_id, use confirm/reject instead.',
            );
        }

        $link->delete();
    }
}
