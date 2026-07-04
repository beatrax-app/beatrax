<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row in an envelope's inline recent-moves history (D-19), mirroring
 * `Modules\Pots\Public\Dto\PotMovementRow`.
 *
 * `direction` is 'in' | 'out' (derived from the row's `kind`: 'move_in' /
 * 'move_out'). `amountMinor` is SIGNED: positive for an 'in' row, negative
 * for an 'out' row. `counterpartCategoryId` / `counterpartCategoryName` are
 * always non-null — every envelope move has a real counterpart category
 * (D-02). `createdAt` is formatted as 'Y-m-d H:i' for compact display.
 */
final class EnvelopeMoveRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $direction,        // in | out
        public readonly int $amountMinor,     // signed
        public readonly int $counterpartCategoryId,
        public readonly string $counterpartCategoryName,
        public readonly ?string $memo,
        public readonly string $createdAt,        // 'Y-m-d H:i'
    ) {}
}
