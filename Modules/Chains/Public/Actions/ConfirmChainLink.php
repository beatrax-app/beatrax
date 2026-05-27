<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public action that promotes a `state='candidate'` chain_link to
 * `state='confirmed'`. After the promotion, runs the D-87/D-88 auto-
 * promotion learning loop:
 *
 *   1. Read the row's `evidence.signature_hash`.
 *   2. Count confirmed rows of the user sharing that signature.
 *   3. If the count reaches `AUTO_PROMOTE_THRESHOLD` (3), update
 *      every remaining same-signature candidate to
 *      `state='confirmed'`, `resolver='rule'` in the same DB
 *      transaction.
 *
 * The `resolver='rule'` distinction matters at the UI level (D-91):
 *   - `confirmed` + `auto` + 1.0 → "Deterministic" chip
 *   - `confirmed` + `auto` + <1.0 → "Confirmed (resolver-suggested)"
 *   - `confirmed` + `user` → reserved for future explicit user-edit path
 *   - `confirmed` + `rule` → "Confirmed via learning loop" — promoted
 *     by this action's auto-promotion pass.
 *
 * Cross-user safety: `firstOrFail()` against `(id, user_id)` raises
 * NotFoundHttpException (404) when the target row belongs to another
 * user. Same defensive pattern as
 * `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php`.
 *
 * The `whereJsonContains('evidence->signature_hash', $hash)` path is
 * the same JSON1 contract verified by
 * `ChainLinksJsonContainsSmokeTest`. If a future SQLite build
 * regresses, swap for `whereRaw("json_extract(evidence, '$.signature_hash') = ?", [$hash])`
 * — a one-line change documented in this method's body.
 */
final class ConfirmChainLink
{
    private const AUTO_PROMOTE_THRESHOLD = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink|null $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            // Surface the canonical 404 (the same exception the Livewire
            // 404-handler renders for cross-user reads). Throwing it
            // explicitly here keeps the contract testable outside the
            // HTTP boundary — `firstOrFail` would raise a Eloquent-layer
            // ModelNotFoundException that the framework converts at the
            // router but bare unit tests would not see.
            throw new NotFoundHttpException('Chain link not found.');
        }

        if ($link->to_transaction_id === null) {
            // Hint-shaped rows (exceeded-tolerance ics_bulk_settle
            // candidates + funded_by_card_hint / refund_of_hint kinds)
            // are permitted to carry a NULL endpoint only while in
            // candidate state. The schema's
            // chain_links_to_transaction_id_check_update trigger
            // refuses to flip state='confirmed' on these rows because
            // the carve-out depends on state='candidate'. Trip the
            // typed exception BEFORE the save so the caller renders
            // a user-readable error instead of an SQLSTATE 23000.
            throw ChainLinkRequiresConcretePartnerException::from($link);
        }

        $this->db->connection()->transaction(function () use ($link, $user): void {
            // Phase 1 — promote the targeted row. Preserve the
            // resolver value the resolver wrote (typically 'auto');
            // the user merely confirmed an existing suggestion.
            $link->state = 'confirmed';
            $link->save();

            // Phase 2 — auto-promotion learning loop.
            $evidence = $link->evidence;
            $signatureHash = $evidence['signature_hash'] ?? null;
            if (! is_string($signatureHash) || $signatureHash === '') {
                return;
            }

            $confirmedCount = $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', 'confirmed')
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->count();

            if ($confirmedCount < self::AUTO_PROMOTE_THRESHOLD) {
                return;
            }

            $now = $this->clock->now()->toDateTimeString();
            $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', 'candidate')
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->update([
                    'state' => 'confirmed',
                    'resolver' => 'rule',
                    'updated_at' => $now,
                ]);
        });
    }
}
