<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row in the preview table. The wizard renders a
 * NEW / DUPLICATE / ENRICHED / ERROR badge per row based on `status`.
 * `error` carries the per-row failure message when `status === 'error'`.
 * `diff` carries the field-level change a user will see when the row
 * is `status === 'enriched'` — currently scoped to source_ref but the
 * shape supports future fields (description, counterparty IBAN, etc.).
 */
final class PreviewRowDto extends Data
{
    /**
     * @param  array<string, array{from: ?string, to: string}>|null  $diff
     */
    public function __construct(
        public readonly int $rowIndex,
        /** 'new' | 'duplicate' | 'enriched' | 'error' */
        public readonly string $status,
        public readonly ?int $accountId,
        /** Resolved name of the user's own account this row debits/credits. */
        public readonly ?string $sourceAccountName,
        public readonly ?string $bookedAt,
        public readonly ?string $counterpartyName,
        /** Counterparty IBAN as a visible fallback when the name is missing. */
        public readonly ?string $counterpartyIban,
        /**
         * Joined payment-reference + free-text description from the source
         * row. Used as the last visible fallback when both
         * counterpartyName and counterpartyIban are absent — typical for
         * bank-fee, interest, or ATM rows where only the description carries
         * any identifying signal.
         */
        public readonly ?string $description,
        public readonly ?string $categoryName,
        public readonly ?int $amountMinor,
        public readonly ?string $currency,
        public readonly ?string $error,
        public readonly ?array $diff = null,
    ) {}
}
