<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Promotes ICS-kind statement_summaries rows into card_statements rows
 * at the post-import boundary.
 *
 * Called from `Modules\Import\Public\Actions\ConfirmImport` AFTER the
 * import's outer DB transaction commits and BEFORE the chain resolver
 * job is dispatched, so the resolver sees fresh card_statements rows
 * for every ICS PDF the user just imported.
 *
 * Algorithm:
 *
 *   1. Read every statement_summaries row written under the given
 *      import_run for the given user, joined to its owning Account,
 *      filtered by `accounts.kind='ics_card'`.
 *   2. For each surviving row with non-null `period_start` AND
 *      non-null `period_end`, build a card_statements payload:
 *        - total_amount_minor preserves the sign of
 *          closing_balance_minor verbatim (negative — amount owed).
 *        - open_balance_minor is the absolute value (positive —
 *          remaining to settle).
 *        - state is always 'open' (new statements start open; the
 *          state machine drives transitions thereafter).
 *   3. Write via `insertOrIgnore` against the UNIQUE
 *      (user_id, account_id, period_start, period_end) constraint.
 *      Existing rows' `state` column is preserved — a card_statement
 *      that the state machine moved away from `open` does NOT get
 *      reset by the upserter.
 *   4. Return the count of rows actually inserted. `insertOrIgnore`
 *      returns the driver-reported affected-row count, which Laravel
 *      normalises to an integer across SQLite and MySQL — summing
 *      those return values is the canonical count without the cost
 *      of two extra COUNT queries.
 *
 * The `closing_balance_minor` sign convention matches the one the
 * 2026_05_16_010004 back-population migration uses: closing balance is
 * stored as a negative integer (the amount the user owes), and the
 * card_statement's `total_amount_minor` carries that sign forward
 * unchanged. The dashboard tile reads `open_balance_minor` (positive
 * magnitude) for display.
 *
 * @internal Bound to UpsertsCardStatements in ChainsServiceProvider —
 *           call sites depend on the contract, not this class directly.
 */
final class CardStatementUpserter implements UpsertsCardStatements
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function upsertForImportRun(int $importRunId, User $user): int
    {
        /** @var list<\stdClass> $rows */
        $rows = $this->buildCandidatesQuery($user)
            ->where('statement_summaries.import_run_id', $importRunId)
            ->get()
            ->values()
            ->all();

        return $this->promoteCandidates($rows, $user);
    }

    public function upsertForUser(User $user): int
    {
        // User-scoped backfill — drops the import_run_id predicate.
        // A statement_summary that already has a matching card_statements
        // row is short-circuited by the UNIQUE
        // (user_id, account_id, period_start, period_end) constraint
        // inside insertOrIgnore — so the cost of "re-scanning every
        // ICS statement_summary on every chain pass" is bounded by the
        // user's lifetime ICS import count (typically dozens), not the
        // chain dispatch frequency. Cheap enough that gating it on a
        // pre-check would add latency rather than save it.
        /** @var list<\stdClass> $rows */
        $rows = $this->buildCandidatesQuery($user)->get()->values()->all();

        return $this->promoteCandidates($rows, $user);
    }

    /**
     * Shared base query: ICS-kind statement_summaries for the user, with
     * the fields needed by promoteCandidates(). Callers narrow further
     * by import_run_id (per-import path) or leave it open (backfill).
     */
    private function buildCandidatesQuery(User $user): Builder
    {
        return $this->db->connection()
            ->table('statement_summaries')
            ->join('accounts', 'accounts.id', '=', 'statement_summaries.account_id')
            ->where('statement_summaries.user_id', $user->id)
            ->where('accounts.kind', 'ics_card')
            ->select(
                'statement_summaries.account_id',
                'statement_summaries.import_run_id',
                'statement_summaries.period_start',
                'statement_summaries.period_end',
                'statement_summaries.closing_balance_minor',
            );
    }

    /**
     * Promote each candidate row into card_statements via insertOrIgnore.
     * Idempotency is delegated to the UNIQUE
     * (user_id, account_id, period_start, period_end) constraint — a
     * re-run with the same candidate set returns 0.
     *
     * @param  list<\stdClass>  $candidates  raw query-builder rows
     *                                       carrying account_id,
     *                                       import_run_id,
     *                                       period_start, period_end,
     *                                       closing_balance_minor
     */
    private function promoteCandidates(array $candidates, User $user): int
    {
        if ($candidates === []) {
            return 0;
        }

        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();
        $inserted = 0;

        foreach ($candidates as $row) {
            $periodStart = self::nullableProp($row, 'period_start');
            $periodEnd = self::nullableProp($row, 'period_end');
            if ($periodStart === null || $periodEnd === null) {
                // card_statements UNIQUE requires both boundaries; the
                // back-population migration drops these too (same
                // invariant — see 2026_05_16_010004).
                continue;
            }

            $closing = self::intProp($row, 'closing_balance_minor');
            $importRunId = self::intProp($row, 'import_run_id');

            // `insertOrIgnore` returns the number of rows actually
            // inserted (0 when the UNIQUE constraint short-circuits,
            // 1 when the row is new). Summing the per-row return
            // value gives the same count the previous COUNT(*)
            // diff produced, with one fewer round trip per
            // statement.
            $inserted += $connection->table('card_statements')->insertOrIgnore([
                'user_id' => $user->id,
                'account_id' => self::intProp($row, 'account_id'),
                'import_run_id' => $importRunId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_amount_minor' => $closing,
                'open_balance_minor' => abs($closing),
                'state' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $inserted;
    }

    /**
     * Read a property off a raw query-builder stdClass row, returning
     * its string representation or null. Defensive against the
     * stdClass property-access lint without losing strict typing.
     */
    private static function nullableProp(\stdClass $row, string $name): ?string
    {
        if (! property_exists($row, $name)) {
            return null;
        }
        /** @var mixed $value */
        $value = $row->$name;
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Numeric coercion for raw query-builder column values. Matches the
     * shape used elsewhere in the codebase so the strict-rules
     * `cast.int` lint stays satisfied.
     */
    private static function intProp(\stdClass $row, string $name): int
    {
        if (! property_exists($row, $name)) {
            return 0;
        }
        /** @var mixed $value */
        $value = $row->$name;

        return is_numeric($value) ? (int) $value : 0;
    }
}
