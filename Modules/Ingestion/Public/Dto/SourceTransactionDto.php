<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of a source statement, parsed into a typed shape but NOT yet
 * normalized into a CanonicalTransaction. The Import pipeline's
 * NormalizeStage maps these to canonical rows (counterparty_normalized,
 * fingerprint composition, account_id resolution, etc.).
 *
 * `rawPayload` preserves the original source cells (positionally indexed
 * column strings for CSV adapters, nested arrays for adapters that need to
 * carry structured metadata like SEPA references) so the source row can
 * be re-inspected for audit without re-reading the file. `sourceRowIndex`
 * is monotonically increasing starting at 0 across one parse run.
 *
 * `settledAmountMinor` and `settledCurrency` carry the foreign-currency
 * settled-EUR pair when the source row provides it (ICS PDF, future
 * PayPal); EUR-native sources leave them `null` and NormalizeStage
 * mirrors the native pair into the canonical settled fields. `fxRateUsed`
 * is always `null` at the adapter boundary — NormalizeStage derives it
 * from the native + settled pair when the currencies differ.
 */
final class SourceTransactionDto extends Data
{
    /**
     * @param  array<int|string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly CarbonImmutable $bookedAt,
        public readonly CarbonImmutable $postedAt,
        public readonly CarbonImmutable $valueDate,
        public readonly string $ownIban,
        public readonly ?string $counterpartyIban,
        public readonly ?string $counterpartyName,
        public readonly string $currency,
        public readonly int $amountMinor,
        public readonly ?string $sourceRef,
        public readonly ?string $description,
        public readonly array $rawPayload,
        public readonly int $sourceRowIndex,
        public readonly ?int $settledAmountMinor = null,
        public readonly ?string $settledCurrency = null,
        public readonly ?string $fxRateUsed = null,
    ) {}
}
