<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

// `postedAt` arrives pre-formatted through LedgerDay::shown(), so the Blade
// must not reformat it; a null `counterpartySlug` renders plain text.
final class TriageRow extends Data
{
    public function __construct(
        public readonly int $transactionId,
        public readonly string $postedAt,
        public readonly ?string $counterpartyName,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $description,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
