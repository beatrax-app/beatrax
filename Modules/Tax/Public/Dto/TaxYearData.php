<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

// Each $categories element has id/name/shortName/subtotalMinor/
// incomeSubtotalMinor/rows keys; subtotalMinor is deductions only, so the
// sections add up to $deductionsTotalMinor. See TaxYearQuery::forUser() for
// the per-row key list.
final class TaxYearData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  string  $currency  the reader's reporting currency — every total and
     *                            subtotal above is denominated in it
     * @param  list<string>  $unconvertedCurrencies  codes left out of every total for
     *                                               want of a rate, so a renderer can say the figures are partial
     */
    public function __construct(
        public readonly int $year,
        public readonly int $deductionsTotalMinor,
        public readonly int $incomeTotalMinor,
        public readonly int $itemCount,
        public readonly array $categories,
        public readonly string $currency = '',
        public readonly array $unconvertedCurrencies = [],
    ) {}

    public function isPartial(): bool
    {
        return $this->unconvertedCurrencies !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconvertedCurrencies);
    }
}
