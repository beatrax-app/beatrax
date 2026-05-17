<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Events\ChainHintDetected;

/**
 * Listens for the Receipts module's `ChainHintDetected` event and
 * persists a candidate `chain_links` row in the Chains ledger.
 *
 * The Receipts module emits the event AFTER a canonical transaction
 * has been written (from `RecordReceipt::__invoke`), so the event
 * payload's `sourceTransactionId` is the id of an existing row. The
 * listener does not validate that the transactions row exists at
 * read-time — the schema's foreign-key constraint enforces that
 * invariant; an event for a deleted transaction simply fails the
 * insert with a constraint violation, which surfaces as a job
 * failure for triage.
 *
 * Two hint types are handled in v1:
 *
 *  - `funded_by_card` -> `chain_links.kind = 'funded_by_card_hint'`.
 *    Emitted when an ICS receipt surfaces a card last-four; the
 *    candidate row waits for a future Chains resolver to bind it to
 *    the matching ICS card statement once the funder lands.
 *
 *  - `refund_of` -> `chain_links.kind = 'refund_of_hint'`. Emitted
 *    when a refund-shaped receipt surfaces the original-order
 *    reference id. (No matcher in plan 03 emits this hint type yet —
 *    refund handling is a deferred v2 capability — but the listener
 *    branch is in place so the Public event contract is total.)
 *
 * Cross-user safety (T-07-09 mitigation): the listener trusts the
 * `userId` field on the event payload as the authoritative source.
 * The dispatcher (`RecordReceipt`) populates that field from the
 * `User` argument it received, so any cross-user leak would have to
 * originate upstream of this listener. The chain_links row's
 * `user_id` is written from `$event->userId` only — never inferred
 * from the current HTTP session or any global state.
 *
 * Idempotency: re-dispatching the same event is a no-op. A pre-INSERT
 * existence check on `(user_id, from_transaction_id, kind)` skips
 * when a row is already present in ANY state — a manually-rejected
 * row stays rejected because the listener refuses to write a fresh
 * candidate over it.
 *
 * Forward-compat: unknown `hintType` values are silently ignored. The
 * sum-type of payloads is closed in v1 (FundedByCardPayload +
 * RefundOfPayload), so an unknown type indicates a producer that has
 * not yet been integrated; dropping the event is the safest default.
 *
 * @internal Wave 2 listener — kept under Internal because the row it
 *           writes is consumed exclusively by the Chains module's own
 *           resolvers + review-queue UI. Subscription is wired in
 *           `ChainsServiceProvider::boot()`.
 */
final class CreateChainLinkFromHint
{
    /** Confidence for a hint-only candidate — half-confident pending resolver promotion. */
    private const HINT_CONFIDENCE = '0.500';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function handle(ChainHintDetected $event): void
    {
        $payload = $event->hintPayload;

        $row = match (true) {
            $event->hintType === 'funded_by_card' && $payload instanceof FundedByCardPayload => [
                'kind' => 'funded_by_card_hint',
                'evidence' => [
                    'card_last4' => $payload->cardLast4,
                    'source_evidence' => $event->evidence,
                ],
            ],
            $event->hintType === 'refund_of' && $payload instanceof RefundOfPayload => [
                'kind' => 'refund_of_hint',
                'evidence' => [
                    'original_reference_id' => $payload->originalReferenceId,
                    'source_evidence' => $event->evidence,
                ],
            ],
            default => null,
        };

        if ($row === null) {
            // Unknown hint type / payload mismatch — silently drop the
            // event rather than writing a row with an invalid kind that
            // the schema trigger would reject anyway.
            return;
        }

        $connection = $this->db->connection();

        // Idempotency: skip when a row for the (user, from, kind)
        // triple already exists in any state. A manually-rejected row
        // stays rejected — a repeated event will not propose a fresh
        // candidate over it.
        $exists = $connection->table('chain_links')
            ->where('user_id', $event->userId)
            ->where('from_transaction_id', $event->sourceTransactionId)
            ->where('kind', $row['kind'])
            ->exists();
        if ($exists) {
            return;
        }

        $encoded = json_encode(
            $row['evidence'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($encoded === false) {
            // Loud failure: a non-encodable evidence payload is a bug
            // upstream (e.g. a resource value sneaking into the
            // payload). Surface it at write time rather than silently
            // writing an empty string into a NOT NULL column.
            throw new \RuntimeException('Failed to json_encode chain_links.evidence for hint event');
        }

        $now = $this->clock->now()->toDateTimeString();

        $connection->table('chain_links')->insert([
            'user_id' => $event->userId,
            'from_transaction_id' => $event->sourceTransactionId,
            'to_transaction_id' => null,
            'kind' => $row['kind'],
            'state' => 'candidate',
            'confidence' => self::HINT_CONFIDENCE,
            'resolver' => 'auto',
            'evidence' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
