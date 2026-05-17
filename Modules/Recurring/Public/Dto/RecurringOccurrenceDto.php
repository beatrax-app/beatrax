<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Single observation row payload — one transaction that contributed
 * to a recurring series. Consumed by the drill-in chart and the audit
 * surface that lists "what we used to compute this cluster".
 *
 * `observedCurrency` is kept alongside `observedAmount` for explicit
 * cross-currency clarity even though Money already carries the
 * currency code; the redundant field stays in lockstep with the
 * underlying schema column so projections do not have to deconstruct
 * the Money VO at the read-site boundary.
 */
final class RecurringOccurrenceDto extends Data
{
    public function __construct(
        public readonly int $occurrenceId,
        public readonly int $recurringSeriesId,
        public readonly int $transactionId,
        public readonly CarbonImmutable $observedAt,
        public readonly Money $observedAmount,
        public readonly string $observedCurrency,
    ) {}
}
