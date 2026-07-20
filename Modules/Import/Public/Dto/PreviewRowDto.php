<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PaymentType;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class PreviewRowDto extends Data
{
    /**
     * @param  array<string, array{from: ?string, to: string}>|null  $diff
     */
    public function __construct(
        public readonly int $rowIndex,
        public readonly string $status,
        public readonly ?int $accountId,
        public readonly ?string $bookedAt,
        public readonly ?string $counterpartyName,
        // Shown in the "Funding source" column and as the second-tier
        // fallback in "Counterparty" when the name is missing.
        public readonly ?string $counterpartyIban,
        // Last visible fallback when both counterpartyName and
        // counterpartyIban are absent (bank-fee, interest, ATM rows).
        public readonly ?string $description,
        public readonly ?string $categoryName,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $error,
        public readonly ?array $diff = null,
        public readonly ?PaymentType $paymentType = null,
        public readonly ?string $aliasFriendlyName = null,
    ) {}
}
