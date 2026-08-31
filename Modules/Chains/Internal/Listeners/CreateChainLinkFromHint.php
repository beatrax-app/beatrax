<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Listeners;

use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;

/**
 * @internal Row consumed exclusively by the Chains module's own resolvers
 *           + review-queue UI. Subscription wired in ChainsServiceProvider::boot().
 */
final readonly class CreateChainLinkFromHint
{
    // Confidence for a hint-only candidate: half-confident pending
    // resolver promotion.
    private const string HINT_CONFIDENCE = '0.500';

    public function __construct(
        private ChainLinkInsertHelper $inserter,
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

        // The helper's own guard tests every state, so a manually-rejected
        // row stays rejected: a repeated event will not propose a fresh
        // candidate over it. Hand-copied here once, the two guards diverged.
        $this->inserter->insertIfNotExists([
            'from_transaction_id' => $event->sourceTransactionId,
            'to_transaction_id' => null,
            'kind' => $row['kind'],
            'state' => ChainLinkState::Candidate->value,
            'confidence' => self::HINT_CONFIDENCE,
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => $row['evidence'],
        ], $event->userId);
    }
}
