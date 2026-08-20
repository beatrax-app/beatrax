<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\AccountKind;

/**
 * @link ../../../../.docs/features/chains/card-statement-lifecycle.md
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
        /** @var list<\stdClass> $rows */
        $rows = $this->buildCandidatesQuery($user)->get()->values()->all();

        return $this->promoteCandidates($rows, $user);
    }

    private function buildCandidatesQuery(User $user): Builder
    {
        return $this->db->connection()
            ->table('statement_summaries')
            ->join('accounts', 'accounts.id', '=', 'statement_summaries.account_id')
            ->where('statement_summaries.user_id', $user->id)
            ->where('accounts.kind', AccountKind::IcsCard->value)
            ->select(
                'statement_summaries.account_id',
                'statement_summaries.import_run_id',
                'statement_summaries.period_start',
                'statement_summaries.period_end',
                'statement_summaries.closing_balance_minor',
            );
    }

    /**
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
                continue;
            }

            $closing = self::intProp($row, 'closing_balance_minor');
            $importRunId = self::intProp($row, 'import_run_id');

            // insertOrIgnore returns 0 or 1 per row, so the sum is the count.
            $inserted += $connection->table('card_statements')->insertOrIgnore([
                'user_id' => $user->id,
                'account_id' => self::intProp($row, 'account_id'),
                'import_run_id' => $importRunId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_amount_minor' => $closing,
                'open_balance_minor' => abs($closing),
                'state' => CardStatementState::Open->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $inserted;
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
