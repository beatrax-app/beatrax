<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// postedAt arrives through LedgerDay::shown(), which is where the choice of
// day and its spelling are both made. secondaryAmount is set only in
// original-currency mode when the native currency differs; a null
// counterpartySlug renders plain text.
final class TransactionRowDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $postedAt,
        public readonly ?string $counterpartyName,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly Money $amount,
        public readonly ?Money $secondaryAmount = null,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
