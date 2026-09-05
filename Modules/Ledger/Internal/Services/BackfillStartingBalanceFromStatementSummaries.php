<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Contracts\AnchorsStartingBalanceFromStatements;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Services\AccountWriter;

// Re-running is safe: an account whose pair is already set is left alone, so
// a user-confirmed override survives later imports.
/**
 * @link ../../../../.docs/features/ledger/reconcile-needs-an-anchor.md
 */
final readonly class BackfillStartingBalanceFromStatementSummaries implements AnchorsStartingBalanceFromStatements
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private AccountWriter $accounts,
    ) {}

    public function run(): void
    {
        $this->anchor(null);
    }

    public function anchorForUser(User $user): int
    {
        return $this->anchor($user->id);
    }

    private function anchor(?int $userId): int
    {
        $connection = $this->db->connection();

        // The reads run in a transaction and the writes after it. The seam
        // announces each account it anchors, and an op emitted inside an outer
        // transaction becomes a savepoint of it: a rollback would drop the op
        // while the clock that stamped it had already moved on.
        $earliestPerAccount = $connection->transaction(function () use ($connection, $userId): array {
            // The summary is written while the preview is being built, so a
            // file the reader discarded -- or previewed and walked away from --
            // leaves one behind. Anchoring off that set the account's opening
            // balance from a statement that never entered the ledger.
            $candidates = $connection->table('statement_summaries')
                ->join('import_runs', 'import_runs.id', '=', 'statement_summaries.import_run_id')
                ->where('import_runs.status', ImportRunStatus::Confirmed->value)
                ->select([
                    'statement_summaries.account_id',
                    'statement_summaries.user_id',
                    'statement_summaries.import_run_id',
                    'statement_summaries.opening_balance_minor',
                    'statement_summaries.opening_balance_date',
                ])
                ->whereNotNull('statement_summaries.account_id')
                ->whereNotNull('statement_summaries.opening_balance_minor')
                ->whereNotNull('statement_summaries.opening_balance_date')
                ->whereNotNull('statement_summaries.user_id')
                ->when($userId !== null, static fn (Builder $q): Builder => $q->where('statement_summaries.user_id', $userId))
                ->orderBy('statement_summaries.account_id')
                ->orderBy('statement_summaries.opening_balance_date')
                ->orderBy('statement_summaries.id')
                ->get();

            /** @var array<int, array{user_id: int, minor: int, date: string}> $earliestPerAccount */
            $earliestPerAccount = [];
            foreach ($candidates as $row) {
                $accountId = self::toInt($row->account_id);
                if (isset($earliestPerAccount[$accountId])) {
                    continue;
                }
                $rowUserId = self::toInt($row->user_id);
                $earliestPerAccount[$accountId] = [
                    'user_id' => $rowUserId,
                    'minor' => self::toInt($row->opening_balance_minor),
                    'date' => $this->anchorDate(
                        self::dateOnly(self::toString($row->opening_balance_date)),
                        $rowUserId,
                        $accountId,
                        self::toInt($row->import_run_id),
                    ),
                ];
            }

            return $earliestPerAccount;
        });

        $anchored = 0;
        foreach ($earliestPerAccount as $accountId => $pick) {
            // The whereNull pair was the filter and the guard at once. It is
            // asked as its own question now, because the write below is a
            // column list rather than a predicate: an override the reader
            // confirmed must survive every later import.
            $unanchored = $connection->table('accounts')
                ->where('id', $accountId)
                ->where('user_id', $pick['user_id'])
                ->whereNull('starting_balance_minor')
                ->whereNull('starting_balance_date')
                ->exists();

            if (! $unanchored) {
                continue;
            }

            $anchored += $this->accounts->write($pick['user_id'], $accountId, [
                'starting_balance_minor' => $pick['minor'],
                'starting_balance_date' => $pick['date'],
            ]);
        }

        return $anchored;
    }

    // A statement's period start and its rows' transaction dates are two
    // different clocks: an ICS card reports a purchase made days before the
    // cycle opened. The opening balance precedes every row the statement
    // brought, so the anchor cannot sit later than the earliest of them.
    private function anchorDate(string $periodDate, int $userId, int $accountId, int $importRunId): string
    {
        $earliestPosted = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('import_run_id', $importRunId)
            ->min('posted_at');

        if (! is_string($earliestPosted) || $earliestPosted === '') {
            return $periodDate;
        }

        $earliestDay = self::dateOnly($earliestPosted);

        return $earliestDay < $periodDate ? $earliestDay : $periodDate;
    }

    // The source column is dateTime, but accounts.starting_balance_date
    // is a date — round to the ISO date so SQLite stores a stable value.
    private static function dateOnly(string $raw): string
    {
        $spacePos = strpos($raw, ' ');
        if ($spacePos === false) {
            return $raw;
        }

        return substr($raw, 0, $spacePos);
    }
}
