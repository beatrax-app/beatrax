<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Events\ChainHintDetected;
use Psr\Log\LoggerInterface;

// Bridges TransactionImported into ChainHintDetected: RecordReceipt
// cannot dispatch the hint event itself since the canonical
// transactions row doesn't exist yet. Hints ride through
// raw_payload['chain_hints'] until this listener re-hydrates them.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class DispatchChainHintsFromReceipt
{
    private const RECEIPT_FORMATS = ['eml', 'mbox'];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(TransactionImported $event): void
    {
        $transaction = $event->transaction;

        if (! in_array($transaction->source_format, self::RECEIPT_FORMATS, true)) {
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
     * @return array{string, object, string}|null
     */
    private function rehydrate(array $hint): ?array
    {
        $type = $hint['hint_type'] ?? null;
        if (! is_string($type)) {
            return null;
        }
        $rawEvidence = $hint['evidence'] ?? '';
        $evidence = is_string($rawEvidence) ? $rawEvidence : '';

        if ($type === 'funded_by_card') {
            $cardLast4 = $hint['card_last4'] ?? null;
            if (! is_string($cardLast4) || $cardLast4 === '') {
                return null;
            }

            return [$type, new FundedByCardPayload(cardLast4: $cardLast4), $evidence];
        }
        if ($type === 'refund_of') {
            $original = $hint['original_reference_id'] ?? null;
            if (! is_string($original) || $original === '') {
                return null;
            }

            return [$type, new RefundOfPayload(originalReferenceId: $original), $evidence];
        }

        return null;
    }
}
