<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Modules\Ingestion\Public\Dto\SourceTransactionDto;
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
            rawPayload: $parsed->rawPayload,
            sourceRowIndex: $sourceRowIndex,
            settledAmountMinor: $parsed->settledAmountMinor ?? $parsed->amountMinor,
            settledCurrency: $parsed->settledCurrency ?? $parsed->currency,
            fxRateUsed: null,
        );
    }
}
