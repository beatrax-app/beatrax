<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/ingestion/architecture.md
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
