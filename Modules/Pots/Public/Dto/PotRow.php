<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

final class PotRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly int $balanceMinor,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?int $goalId,
        public readonly ?string $goalName,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly ?int $categorySpentMinor,
        /** @var list<PotMovementRow> */
        public readonly array $recentMovements,
    ) {}
}
