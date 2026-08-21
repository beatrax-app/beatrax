<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// bookedAt arrives pre-formatted. secondaryAmount is set only in
// original-currency mode when the native currency differs, which is what
// drives the two-line stack; a null counterpartySlug renders plain text.
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
