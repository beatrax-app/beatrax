<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class RecurringOccurrenceDto extends Data
{
    /**
     * @param  string  $observedCurrency  kept alongside $observedAmount for explicit
     *                                    cross-currency clarity even though Money already carries the currency code; stays
     *                                    in lockstep with the schema column so read-site projections need not deconstruct
     *                                    the Money VO
     */
    public function __construct(
        public readonly int $occurrenceId,
        public readonly int $recurringSeriesId,
        public readonly int $transactionId,
        public readonly CarbonImmutable $observedAt,
        public readonly Money $observedAmount,
        public readonly string $observedCurrency,
    ) {}
}
