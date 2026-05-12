<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Display-shape for one transaction row in the dashboard's "recent" panel and
 * in the `/transactions` list. The booked-at value is pre-formatted as
 * `d-m-Y` so the Blade template renders it without any further date helper.
 *
 * Holds a Money value object rather than a raw integer + currency string —
 * keeps the rendering layer one method call away from a typed amount and
 * matches the brick/money contract the rest of the codebase already uses.
 */
final class TransactionRowDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $bookedAt,
        public readonly ?string $counterpartyName,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly Money $amount,
    ) {}
}
