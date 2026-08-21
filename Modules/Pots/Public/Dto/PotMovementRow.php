<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

final class PotMovementRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $counterpartPotId,
        public readonly ?string $counterpartPotName,
        public readonly ?string $memo,
        public readonly string $createdAt,
    ) {}
}
