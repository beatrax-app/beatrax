<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Writes the earliest opening balance per account into
 * accounts.starting_balance_minor + starting_balance_date, skipping any
 * account whose starting-balance pair is already populated.
 *
 * Source rows come from statement_summaries — populated by every CAMT.053,
 * MT940, and ICS PDF import. The earliest opening_balance_date wins; ties
 * on date fall back to the lowest statement_summaries.id for a
 * deterministic pick.
 *
 * Re-running the service is safe: rows whose starting_balance_minor and
 * starting_balance_date are both already set are left untouched so a
 * user-confirmed override survives later imports.
 */
final class BackfillStartingBalanceFromStatementSummaries
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function run(): void
    {
        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection): void {
            $candidates = $connection->table('statement_summaries')
                ->select([
                    'account_id',
                    'user_id',
                    'opening_balance_minor',
                    'opening_balance_date',
                ])
                ->whereNotNull('account_id')
                ->whereNotNull('opening_balance_minor')
                ->whereNotNull('opening_balance_date')
                ->whereNotNull('user_id')
                ->orderBy('account_id')
                ->orderBy('opening_balance_date')
                ->orderBy('id')
                ->get();

            /** @var array<int, array{user_id: int, minor: int, date: string}> $earliestPerAccount */
            $earliestPerAccount = [];
            foreach ($candidates as $row) {
                $accountId = self::toInt($row->account_id);
                if (isset($earliestPerAccount[$accountId])) {
                    continue;
                }
                $earliestPerAccount[$accountId] = [
                    'user_id' => self::toInt($row->user_id),
                    'minor' => self::toInt($row->opening_balance_minor),
                    'date' => self::dateOnly(self::toString($row->opening_balance_date)),
                ];
            }

            foreach ($earliestPerAccount as $accountId => $pick) {
                $connection->table('accounts')
                    ->where('id', $accountId)
                    ->where('user_id', $pick['user_id'])
                    ->whereNull('starting_balance_minor')
                    ->whereNull('starting_balance_date')
                    ->update([
                        'starting_balance_minor' => $pick['minor'],
                        'starting_balance_date' => $pick['date'],
                    ]);
            }
        });
    }

    /**
     * Strip any time component from a statement_summaries.opening_balance_date
     * value. The source column is `dateTime`, but accounts.starting_balance_date
     * is a `date` — round to the ISO date so SQLite stores a stable value.
     */
    private static function dateOnly(string $raw): string
    {
        $spacePos = strpos($raw, ' ');
        if ($spacePos === false) {
            return $raw;
        }

        return substr($raw, 0, $spacePos);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
