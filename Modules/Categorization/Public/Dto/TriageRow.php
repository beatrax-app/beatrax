<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One uncategorized transaction as rendered on the `/uncategorized` page.
 * `bookedAt` is pre-formatted as `d-m-Y` so the Blade template renders
 * without any further date helper.
 *
 * `counterpartySlug` carries the resolved-counterparty slug for the
 * counterparty-name click-through anchor that routes to
 * `counterparties.profile`. NULL when the transaction has no resolved
 * counterparty — the Blade then renders the counterparty name as
 * plain text instead of a link.
 */
final class TriageRow extends Data
{
    public function __construct(
        public readonly int $transactionId,
        public readonly string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $description,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
