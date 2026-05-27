<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public contract for the per-import card_statements upsert path.
 *
 * `Modules\Import\Public\Actions\ConfirmImport` injects this contract
 * and calls `upsertForImportRun($importRunId, $user)` AFTER the import
 * transaction commits and BEFORE dispatching the chain resolver. The
 * concrete implementation promotes every `statement_summaries` row
 * written under the given import_run whose owning Account is of
 * `kind='ics_card'` into a `card_statements` row.
 *
 * The contract is idempotent: re-running against the same import_run
 * produces zero new rows because the underlying write site is
 * `insertOrIgnore` against the UNIQUE
 * `(user_id, account_id, period_start, period_end)` constraint.
 * Existing rows' `state` column is preserved — a card_statement that
 * the state machine moved from `open` to `partially_settled` does NOT
 * get reset to `open` when the upserter re-runs.
 *
 * Returns the number of card_statements rows actually inserted on the
 * call (zero on a no-op re-run). Callers use the count for telemetry
 * only; the contract makes no guarantees about deltas across concurrent
 * calls (the ResolveChainLinksJob unique-lock keyed on the user
 * collapses concurrent dispatches into a single pass, so the contract
 * is effectively single-threaded per user).
 */
interface UpsertsCardStatements
{
    /**
     * Promote every statement_summaries row written under the given
     * import_run for the given user into a card_statements row, where
     * the statement's owning account is of kind 'ics_card'. Idempotent
     * via the UNIQUE (user_id, account_id, period_start, period_end)
     * constraint + insertOrIgnore semantics. Returns the number of
     * card_statements rows actually inserted (0 on a no-op re-run).
     */
    public function upsertForImportRun(int $importRunId, User $user): int;

    /**
     * User-scoped backfill — promote every still-unpromoted ICS
     * statement_summaries row for the user into a card_statements row,
     * independent of import_run_id. Consumed by the chain-resolution
     * job's healing pass so a packaged-app install whose ConfirmImport
     * path missed the per-import upsert (rolled-back transaction, race
     * with chain dispatch, legacy data from a pre-Phase-16.1.2.1 build)
     * catches up on the next chain pass without a manual artisan call.
     *
     * Same idempotency contract as `upsertForImportRun`: insertOrIgnore
     * against the same UNIQUE constraint, existing rows' `state`
     * column preserved. Returns the count of newly-inserted rows.
     */
    public function upsertForUser(User $user): int;
}
