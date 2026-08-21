<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

// `bookedAt` arrives pre-formatted in the reader's locale (Fmt::shortDate), so
// the Blade must not reformat it; a null `counterpartySlug` renders plain text.
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
