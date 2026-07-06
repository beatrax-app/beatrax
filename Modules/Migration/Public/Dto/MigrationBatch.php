<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * The root parsed-source intermediate representation a `ParsesMigrationSource`
 * implementation returns. Every bounded entity kind (categories, accounts,
 * payees, budget assignments, goals, schedules, already-known-unmapped items)
 * is a plain `Illuminate\Support\Collection` — small enough per format
 * (dozens to low hundreds of rows) to hold in memory whole.
 *
 * `transactions` is the ONE deliberate exception (D-04/D-05): a multi-year
 * export can carry tens of thousands of rows, so this field is typed
 * `iterable` and MUST be a lazy `Generator<MigrationTransactionDto>` the
 * parser yields from — never `iterator_to_array()`'d by the parser itself or
 * by any downstream pipeline stage. `StagingWriter` (Plan 05) consumes it in
 * bounded chunks (mirrors `Ledger\RecordTransactions`'s `CHUNK_SIZE = 500`
 * convention) so a whole-file import never materializes every row at once.
 *
 * `sourceProduct` is `'ynab4'|'nynab'|'actual'` (mirrors
 * `migration_runs.source_product`). `budgetCurrency` is the single
 * budget-file-level currency every transaction/assignment/goal in this batch
 * is stamped with when a source row carries no per-row currency of its own
 * (YNAB4/nYNAB are effectively single-currency exports; Actual's currency
 * lives in budget-file preferences, not per-transaction — RESEARCH.md
 * Format Schemas, Req 7's "otherwise the budget-file currency" fallback).
 */
final class MigrationBatch extends Data
{
    /**
     * @param  'ynab4'|'nynab'|'actual'  $sourceProduct
     * @param  Collection<int, MigrationCategoryDto>  $categories
     * @param  Collection<int, MigrationAccountDto>  $accounts
     * @param  Collection<int, MigrationPayeeDto>  $payees
     * @param  Collection<int, MigrationBudgetAssignmentDto>  $budgetAssignments
     * @param  Collection<int, MigrationGoalDto>  $goals
     * @param  Collection<int, MigrationScheduleDto>  $schedules
     * @param  Collection<int, UnmappedItemDto>  $unmapped
     * @param  iterable<int, MigrationTransactionDto>  $transactions  A lazy Generator — never materialized by parser or pipeline (D-05).
     */
    public function __construct(
        public readonly string $sourceProduct,
        public readonly string $budgetCurrency,
        public readonly Collection $categories,
        public readonly Collection $accounts,
        public readonly Collection $payees,
        public readonly Collection $budgetAssignments,
        public readonly Collection $goals,
        public readonly Collection $schedules,
        public readonly Collection $unmapped,
        public readonly iterable $transactions,
    ) {}
}
