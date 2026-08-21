<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Enums\ChainHintType;

// Bridges matcher output (ParsedReceiptDto) into the import pipeline's
// SourceTransactionDto. Receipts have no booked-vs-posted-vs-value-date
// lag, so all three canonical date fields collapse to the matcher's
// single bookedAt value.
final class ReceiptSourceAdapter
{
    public function toSourceDto(ParsedReceiptDto $parsed, int $sourceRowIndex = 0): SourceTransactionDto
    {
        $rawPayload = $parsed->rawPayload;
        // Threads chainHints[] through rawPayload as an array-of-arrays
        // so the canonical raw_payload column carries them; a later
        // listener (DispatchChainHintsFromReceipt) re-hydrates each
        // entry once the canonical transaction id is known.
        if ($parsed->chainHints !== []) {
            $hints = [];
            foreach ($parsed->chainHints as $payload) {
                $hints[] = $this->serializeChainHint($payload, $parsed->rawPayload);
            }
            $rawPayload['chain_hints'] = $hints;
        }

        return new SourceTransactionDto(
            bookedAt: $parsed->bookedAt,
            postedAt: $parsed->bookedAt,
            valueDate: $parsed->bookedAt,
            ownIban: $parsed->ownIban,
            counterpartyIban: null,
            counterpartyName: $parsed->merchantName,
            currency: $parsed->currency,
            amountMinor: $parsed->amountMinor,
            sourceRef: $parsed->referenceId,
            description: $parsed->description,
            rawPayload: $rawPayload,
            sourceRowIndex: $sourceRowIndex,
            settledAmountMinor: $parsed->settledAmountMinor ?? $parsed->amountMinor,
            settledCurrency: $parsed->settledCurrency ?? $parsed->currency,
            fxRateUsed: null,
        );
    }

    /**
     * @param  array<int|string, mixed>  $matcherRawPayload
     * @return array<string, mixed>
     */
    private function serializeChainHint(object $payload, array $matcherRawPayload): array
    {
        $evidence = '';
        $rawEvidence = $matcherRawPayload['chain_hint_evidence'] ?? null;
        if (is_string($rawEvidence)) {
            $evidence = $rawEvidence;
        }

        if ($payload instanceof FundedByCardPayload) {
            return [
                'hint_type' => ChainHintType::FundedByCard->value,
                'card_last4' => $payload->cardLast4,
                'evidence' => $evidence,
            ];
        }
        if ($payload instanceof RefundOfPayload) {
            return [
                'hint_type' => ChainHintType::RefundOf->value,
                'original_reference_id' => $payload->originalReferenceId,
                'evidence' => $evidence,
            ];
        }

        // Forward-compat: an unrecognised payload class lands as
        // hint_type=unknown so the downstream listener can drop it
        // safely.
        return [
            'hint_type' => ChainHintType::Unknown->value,
            'evidence' => $evidence,
        ];
    }
}
