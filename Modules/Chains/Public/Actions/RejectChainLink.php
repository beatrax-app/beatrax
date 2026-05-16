<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action that marks a `chain_link` as `state='rejected'`.
 *
 * Per D-89, rejection is strictly per-pair: the signature counter
 * stays NEUTRAL. A rejected row neither demotes existing same-
 * signature confirmed rows nor blocks the auto-promotion threshold
 * from accumulating across OTHER same-signature confirmations. The
 * only place the rejected state matters is the resolver's per-pair
 * pre-insert guard (`ChainLinkInsertHelper`), which refuses to
 * re-propose a candidate for an already-rejected (from, to, kind,
 * user) tuple — the user's rejection is final.
 *
 * Cross-user safety: `firstOrFail()` against `(id, user_id)` raises
 * NotFoundHttpException (404) when the target row belongs to another
 * user.
 *
 * DI shape: no DatabaseManager — the action's only write path is
 * Eloquent's `save()` which routes through the model's own
 * connection. A future batch-reject extension that needs raw query
 * builder access can DI DatabaseManager when added; until then the
 * single-row save() is small enough that the model facade-free
 * boundary is satisfied without extra collaborators.
 */
final class RejectChainLink
{
    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink|null $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            // Surface the canonical 404 explicitly so the contract is
            // testable outside the HTTP layer. The framework would
            // convert a `firstOrFail()` ModelNotFoundException to the
            // same 404 at the router, but explicit throwing keeps the
            // action's signature self-documenting.
            throw new NotFoundHttpException('Chain link not found.');
        }

        $link->state = 'rejected';
        $link->save();
    }
}
