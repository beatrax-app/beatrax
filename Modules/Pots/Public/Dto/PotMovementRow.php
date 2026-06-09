<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One row in the pot's inline movement history (D-17).
 *
 * `amountMinor` is SIGNED: positive = into pot, negative = out of pot.
 * `kind` is one of: fund | withdraw | transfer_in | transfer_out.
 * `counterpartPotId` / `counterpartPotName` are non-null for transfer rows.
 * `createdAt` is formatted as 'Y-m-d H:i' for compact display.
 */
final class PotMovementRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $kind,            // fund | withdraw | transfer_in | transfer_out
        public readonly int $amountMinor,     // signed
        public readonly string $currency,
        public readonly ?int $counterpartPotId,
        public readonly ?string $counterpartPotName,
        public readonly ?string $memo,
        public readonly string $createdAt,       // 'Y-m-d H:i'
    ) {}
}
