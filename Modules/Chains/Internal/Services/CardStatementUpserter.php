<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

/**
 * @link ../../../../.docs/features/chains/card-statement-lifecycle.md
 *
 * @internal Bound to UpsertsCardStatements in ChainsServiceProvider —
 *           call sites depend on the contract, not this class directly.
 */
final readonly class CardStatementUpserter implements UpsertsCardStatements
{
    private const int CHUNK_SIZE = 200;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function upsertForImportRun(int $importRunId, User $user): int
    {
        return $this->promoteInChunks(
            $this->buildCandidatesQuery($user)->where('statement_summaries.import_run_id', $importRunId),
            $user,
        );
    }

    public function upsertForUser(User $user): int
    {
        return $this->promoteInChunks($this->buildCandidatesQuery($user), $user);
    }

    // statement_summaries gains a row per imported statement forever, so the
    // candidate set has no ceiling. chunkById walks the same rows in the same
    // id order while holding one chunk of them at a time.
    private function promoteInChunks(Builder $query, User $user): int
    {
        $inserted = 0;

        /** @param Collection<int, \stdClass> $chunk */
        $promote = function (Collection $chunk) use ($user, &$inserted): void {
            /** @var list<\stdClass> $rows */
            $rows = $chunk->values()->all();
            $inserted += $this->promoteCandidates($rows, $user);
        };

        $query->chunkById(self::CHUNK_SIZE, $promote, 'statement_summaries.id', 'id');

        return $inserted;
    }

    private function buildCandidatesQuery(User $user): Builder
    {
        return $this->db->connection()
            ->table('statement_summaries')
            ->join('accounts', 'accounts.id', '=', 'statement_summaries.account_id')
            ->where('statement_summaries.user_id', $user->id)
            ->where('accounts.kind', AccountKind::IcsCard->value)
            ->select(
                'statement_summaries.id',
                'statement_summaries.account_id',
                'statement_summaries.import_run_id',
                'statement_summaries.period_start',
                'statement_summaries.period_end',
                'statement_summaries.closing_balance_minor',
                'statement_summaries.closing_balance_currency',
                'statement_summaries.payment_due_date',
            );
    }

    /**
     * @param  list<\stdClass>  $candidates  raw query-builder rows
     *                                       carrying account_id,
     *                                       import_run_id,
     *                                       period_start, period_end,
     *                                       closing_balance_minor,
     *                                       closing_balance_currency,
     *                                       payment_due_date
     */
    private function promoteCandidates(array $candidates, User $user): int
    {
        if ($candidates === []) {
            return 0;
        }

        $now = $this->clock->now()->toDateTimeString();

        $rows = [];
        /** @var list<array{account_id: int, period_start: string, period_end: string, due_date: string}> $printed */
        $printed = [];
        foreach ($candidates as $row) {
            $periodStart = self::nullableProp($row, 'period_start');
            $periodEnd = self::nullableProp($row, 'period_end');
            if ($periodStart === null || $periodEnd === null) {
                continue;
            }

            $closing = self::intProp($row, 'closing_balance_minor');
            $dueDate = self::nullableProp($row, 'payment_due_date');
            if ($dueDate !== null) {
                $printed[] = [
                    'account_id' => self::intProp($row, 'account_id'),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'due_date' => $dueDate,
                ];
            }

            $rows[] = [
                'user_id' => $user->id,
                'account_id' => self::intProp($row, 'account_id'),
                'import_run_id' => self::intProp($row, 'import_run_id'),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => $dueDate,
                'total_amount_minor' => $closing,
                'open_balance_minor' => abs($closing),
                'currency' => self::currencyOf($row),
                'state' => CardStatementState::Open->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // UNIQUE(user_id, account_id, period_start, period_end) is what
        // decides which of these rows is new, and it decides for a whole
        // chunk in one statement as readily as for one row.
        $inserted = $this->db->connection()->table('card_statements')->insertOrIgnore($rows);

        $this->fillPrintedDueDates($printed, $user);

        return $inserted;
    }

    // A statement already on disk is left alone by insertOrIgnore, and every
    // one written before the reader was parsed carries no due date at all.
    // Filled only where it is still missing, so this reaches a shipped install
    // once and is a no-op on every pass after it.
    /**
     * @param  list<array{account_id: int, period_start: string, period_end: string, due_date: string}>  $printed
     */
    private function fillPrintedDueDates(array $printed, User $user): void
    {
        foreach ($printed as $statement) {
            $this->db->connection()->table('card_statements')
                ->where('user_id', $user->id)
                ->where('account_id', $statement['account_id'])
                ->where('period_start', $statement['period_start'])
                ->where('period_end', $statement['period_end'])
                ->whereNull('due_date')
                ->update(['due_date' => $statement['due_date']]);
        }
    }

    // The summary the statement is promoted from states the currency its
    // closing balance was read in. A summary written before that column
    // existed has none, and only the EUR-pinned ICS reader wrote those.
    private static function currencyOf(\stdClass $row): string
    {
        $currency = self::nullableProp($row, 'closing_balance_currency');

        return $currency === null || $currency === '' ? Currency::Eur->value : $currency;
    }

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
