<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// bookedAt is pre-formatted as d-m-Y. secondaryAmount carries the
// settled-EUR amount only in original-currency mode when the native
// currency differs, driving the two-line stack; null otherwise.
// counterpartySlug null renders the name as plain text, not a link.
final class TransactionRowDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly Money $amount,
        public readonly ?Money $secondaryAmount = null,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
