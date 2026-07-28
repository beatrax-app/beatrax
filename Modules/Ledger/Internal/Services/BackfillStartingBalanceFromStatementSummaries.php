<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;

// Writes the earliest opening balance per account into
// accounts.starting_balance_minor + starting_balance_date. Re-running
// is safe: rows whose pair is already set are left untouched, so a
// user-confirmed override survives later imports.
final class BackfillStartingBalanceFromStatementSummaries
{
    use CoercesScalars;

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
