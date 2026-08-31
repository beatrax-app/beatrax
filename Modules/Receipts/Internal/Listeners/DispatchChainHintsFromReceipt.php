<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;
use Psr\Log\LoggerInterface;

// Bridges TransactionImported into ChainHintDetected: RecordReceipt
// cannot dispatch the hint event itself since the canonical
// transactions row doesn't exist yet. Hints ride through
// raw_payload['chain_hints'] until this listener re-hydrates them.
final readonly class DispatchChainHintsFromReceipt
{
    public function __construct(
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    public function handle(TransactionImported $event): void
    {
        $transaction = $event->transaction;

        $format = SourceFormat::tryFrom($transaction->source_format);
        if ($format?->isReceiptFile() !== true) {
            return;
        }

        $rawPayload = $transaction->raw_payload;
        if (! is_array($rawPayload)) {
            $rawStored = $transaction->getRawOriginal('raw_payload');
            if (is_string($rawStored) && $rawStored !== '') {
                $this->logger->warning(
                    'DispatchChainHintsFromReceipt: raw_payload present but failed to decrypt/decode — chain hints skipped.',
                    ['transaction_id' => $transaction->id],
                );
            }

            return;
        }

        $hints = $rawPayload['chain_hints'] ?? null;
        if (! is_array($hints) || $hints === []) {
            return;
        }

        $userId = $event->user->id;
        $sourceTransactionId = $transaction->id;

        foreach ($hints as $hint) {
            if (! is_array($hint)) {
                continue;
            }
            $rehydrated = $this->rehydrate($hint);
            if ($rehydrated === null) {
                continue;
            }
            [$hintType, $payload, $evidence] = $rehydrated;

            $this->events->dispatch(new ChainHintDetected(
                sourceTransactionId: $sourceTransactionId,
                hintType: $hintType,
                hintPayload: $payload,
                evidence: $evidence,
                userId: $userId,
            ));
        }
    }

    /**
     * @param  array<int|string, mixed>  $hint
     * @return array{ChainHintType, object, string}|null
     */
    private function rehydrate(array $hint): ?array
    {
        $type = $hint['hint_type'] ?? null;
        if (! is_string($type)) {
            return null;
        }
        $rawEvidence = $hint['evidence'] ?? '';
        $evidence = is_string($rawEvidence) ? $rawEvidence : '';

        return match (ChainHintType::tryFrom($type)) {
            ChainHintType::FundedByCard => $this->rehydrateFundedByCard($hint, $evidence),
            ChainHintType::RefundOf => $this->rehydrateRefundOf($hint, $evidence),
            default => null,
        };
    }

    /**
     * @param  array<int|string, mixed>  $hint
     * @return array{ChainHintType, object, string}|null
     */
    private function rehydrateFundedByCard(array $hint, string $evidence): ?array
    {
        $cardLast4 = $hint['card_last4'] ?? null;
        if (! is_string($cardLast4) || $cardLast4 === '') {
            return null;
        }

        return [ChainHintType::FundedByCard, new FundedByCardPayload(cardLast4: $cardLast4), $evidence];
    }

    /**
     * @param  array<int|string, mixed>  $hint
     * @return array{ChainHintType, object, string}|null
     */
    private function rehydrateRefundOf(array $hint, string $evidence): ?array
    {
        $original = $hint['original_reference_id'] ?? null;
        if (! is_string($original) || $original === '') {
            return null;
        }

        return [ChainHintType::RefundOf, new RefundOfPayload(originalReferenceId: $original), $evidence];
    }
}
