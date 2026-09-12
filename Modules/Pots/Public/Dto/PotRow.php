<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Modules\FX\Public\Dto\ConversionDisclosure;
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
        /** @var ?ConversionDisclosure the rates $categorySpentMinor was converted at, beside the codes it left out */
        public readonly ?ConversionDisclosure $categorySpentConversion = null,
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

    // Where this pot's money may go: another pot on the same account holding the
    // same currency. Both the sheet and the desktop modal draw the list, and
    // filtering it in each blade is how one of them came to offer a target the
    // writer refuses.
    /**
     * @param  list<self>  $onTheSameAccount
     * @return list<self>
     */
    public function moveTargetsAmong(array $onTheSameAccount): array
    {
        return array_values(array_filter(
            $onTheSameAccount,
            fn (self $candidate): bool => $candidate->id !== $this->id
                && $candidate->currency === $this->currency,
        ));
    }

    // Derived, never carried: a pot's balance is the signed sum of its
    // movements and a second field holding "that sum was negative" is a copy
    // that can disagree with it. The writer refuses a withdrawal past nought,
    // so this is only ever true of a sum two devices arrived at apart.
    /**
     * @link ../../../../.docs/features/pots/over-allocation-guard.md
     */
    public function isOverdrawn(): bool
    {
        return $this->balanceMinor < 0;
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
