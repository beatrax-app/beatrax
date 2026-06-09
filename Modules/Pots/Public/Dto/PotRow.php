<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One pot card's data on the /pots page.
 *
 * `balanceMinor` is the signed SUM of the pot's movements — always >= 0 in
 * normal operation (PotWriter prevents funding past unallocated). It may
 * temporarily be 0 for a newly-created pot before any funding.
 *
 * `recentMovements` is the inline history list (D-17). The view renders it
 * under an Alpine x-show toggle — no per-pot detail page.
 *
 * `categorySpentMinor` is null when no category is linked (D-12). When linked,
 * it holds the category's current-month actual spend so the pot card can show
 * coverage insight.
 *
 * All money is signed minor units in `currency` (the pot's native currency,
 * set from the account at creation — D-05).
 */
final class PotRow extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly int $balanceMinor,
        public readonly string $currency,
        public readonly string $status,          // active | archived
        public readonly ?int $goalId,
        public readonly ?string $goalName,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        /** Current-month spend for a category-linked pot (D-12). null when no category link. */
        public readonly ?int $categorySpentMinor,
        /** @var list<PotMovementRow> */
        public readonly array $recentMovements,
    ) {}
}
