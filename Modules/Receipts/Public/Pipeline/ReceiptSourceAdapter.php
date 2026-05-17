<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;

/**
 * Bridge between matcher output (`ParsedReceiptDto`) and the existing
 * import pipeline (`SourceTransactionDto`).
 *
 * Receipts have no booked-vs-posted-vs-value-date lag — the three
 * canonical date fields collapse to the single `bookedAt` value the
 * matcher extracted from the message's Date header (normalised to
 * `startOfDay()` so cross-format fingerprint parity with the
 * corresponding CSV row holds).
 *
 * `ownIban` carries the synthetic per-provider IBAN-shaped literal
 * (`PAYPAL`, `ICS-CARD`, `GOOGLE-PLAY`) the matcher assigned;
 * `AccountResolver` is already user-scoped so the literal is unambiguous
 * across users. Counterparties surface by display name only — receipts
 * never carry a merchant IBAN.
 *
 * `settledAmountMinor` and `settledCurrency` ride through verbatim from
 * the matcher; EUR-only receipts have them mirrored to the native pair
 * already inside the matcher so the `NormalizeStage` sees a non-null
 * settled pair on every row (parity with the PayPal CSV adapter shape).
 * `fxRateUsed` is left null — `NormalizeStage` derives it from the
 * native / settled pair via `Brick\Math\BigDecimal` when the currencies
 * differ.
 *
 * Stateless; safe to bind as a singleton.
 */
final class ReceiptSourceAdapter
{
    public function toSourceDto(ParsedReceiptDto $parsed, int $sourceRowIndex = 0): SourceTransactionDto
    {
        $rawPayload = $parsed->rawPayload;
        // Thread the matcher's chainHints[] through rawPayload as an
        // array-of-arrays so the canonical transaction's raw_payload
        // column carries them after persistence. A downstream listener
        // (`DispatchChainHintsFromReceipt`) re-hydrates each entry and
        // re-emits `ChainHintDetected` with the just-inserted
        // `transactions.id` as the canonical sourceTransactionId — the
        // Chains module's `CreateChainLinkFromHint` listener consumes
        // that event to write candidate chain_links rows. Pre-existing
        // chain_hints array entries on rawPayload are overwritten; the
        // matcher is the only producer.
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
     * Serialise one chain-hint payload sub-DTO into a flat array shape
     * suitable for JSON storage in `transactions.raw_payload`. The
     * downstream listener re-hydrates by `hintType`.
     *
     * The evidence string (when the matcher recorded one in
     * `rawPayload['chain_hint_evidence']`) is forwarded so the
     * downstream `ChainHintDetected` event carries audit provenance.
     *
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
                'hint_type' => 'funded_by_card',
                'card_last4' => $payload->cardLast4,
                'evidence' => $evidence,
            ];
        }
        if ($payload instanceof RefundOfPayload) {
            return [
                'hint_type' => 'refund_of',
                'original_reference_id' => $payload->originalReferenceId,
                'evidence' => $evidence,
            ];
        }

        // Forward-compat: an unrecognised payload class lands as
        // `hint_type=unknown` so the downstream listener can drop it
        // safely.
        return [
            'hint_type' => 'unknown',
            'evidence' => $evidence,
        ];
    }
}
