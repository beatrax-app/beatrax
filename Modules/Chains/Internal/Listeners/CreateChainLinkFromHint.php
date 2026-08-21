<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Exceptions\EvidenceEncodingFailedException;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Public\Contracts\Clock;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;

/**
 * @internal Row consumed exclusively by the Chains module's own resolvers
 *           + review-queue UI. Subscription wired in ChainsServiceProvider::boot().
 */
final class CreateChainLinkFromHint
{
    // Confidence for a hint-only candidate: half-confident pending
    // resolver promotion.
    private const HINT_CONFIDENCE = '0.500';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function handle(ChainHintDetected $event): void
    {
        $payload = $event->hintPayload;

        $row = match (true) {
            $event->hintType === ChainHintType::FundedByCard && $payload instanceof FundedByCardPayload => [
                'kind' => ChainLinkKind::FundedByCardHint->value,
                'evidence' => [
                    'card_last4' => $payload->cardLast4,
                    'source_evidence' => $event->evidence,
                ],
            ],
            $event->hintType === ChainHintType::RefundOf && $payload instanceof RefundOfPayload => [
                'kind' => ChainLinkKind::RefundOfHint->value,
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

        // Any state, so a manually-rejected row stays rejected — a repeated
        // event will not propose a fresh candidate over it.
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
            throw new EvidenceEncodingFailedException('hint event');
        }

        $now = $this->clock->now()->toDateTimeString();

        $connection->table('chain_links')->insert([
            'user_id' => $event->userId,
            'from_transaction_id' => $event->sourceTransactionId,
            'to_transaction_id' => null,
            'kind' => $row['kind'],
            'state' => ChainLinkState::Candidate->value,
            'confidence' => self::HINT_CONFIDENCE,
            'resolver' => 'auto',
            'evidence' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
