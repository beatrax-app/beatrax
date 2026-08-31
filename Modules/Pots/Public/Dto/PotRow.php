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
        public readonly int $movementCount = 0,
        /** @var list<string> codes left out of $categorySpentMinor for want of a rate */
        public readonly array $categorySpentUnconverted = [],
    ) {}

    // The card the reader acted on, picked out of the list a screen already
    // holds. A second read for one row is a query the page has already paid
    // for, and the two answers could disagree mid-operation.
    /**
     * @param  list<self>  $rows
     */
    public static function withId(array $rows, int $potId): ?self
    {
        foreach ($rows as $row) {
            if ($row->id === $potId) {
                return $row;
            }
        }

        return null;
    }

    public function categorySpentIsPartial(): bool
    {
        return $this->categorySpentUnconverted !== [];
    }

    public function categorySpentUnconvertedList(): string
    {
        return implode(', ', $this->categorySpentUnconverted);
    }

    // The card shows the last ten and used to stop there with no sign that an
    // eleventh existed, so a pot's history read as complete when it was not.
    public function hasOlderMovements(): bool
    {
        return $this->movementCount > count($this->recentMovements);
    }
}
