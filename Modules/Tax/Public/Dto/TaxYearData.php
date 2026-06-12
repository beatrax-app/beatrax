<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Full grouped year data for the TaxPage.
 *
 * Produced by TaxYearQuery (Plan 02). Each element in $categories is
 * an associative array with keys: id, name, shortName, subtotalMinor,
 * rows[].
 *
 * Each row in a category's `rows` array has keys:
 *   transactionId, bookedAt, accountName, counterpartyName,
 *   counterpartyIban, description, note, settledAmountMinor,
 *   settledCurrency, amountMinor, currency, transactionType,
 *   categoryId, categoryName, categoryShortName, taxYearOverride,
 *   sourceFormat, importRunId, fingerprint
 */
final class TaxYearData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    public function __construct(
        public readonly int $year,
        public readonly int $deductionsTotalMinor,
        public readonly int $incomeTotalMinor,
        public readonly int $itemCount,
        public readonly array $categories,
    ) {}
}
