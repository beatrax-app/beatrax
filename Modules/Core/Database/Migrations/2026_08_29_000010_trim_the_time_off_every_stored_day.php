<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Modules\Core\Database\Support\ModuleMigration;
use Psr\Log\LoggerInterface;

// The casts hold every DATE column to a bare day from here on, but the rows
// already on disk keep whatever shape wrote them, and one measured install held
// both in forecast_shortfall_windows at once. Two shapes in one column is the
// failure: '2026-09-16 00:00:00' <= '2026-09-16' is false, so the long rows
// drop out of a range the short ones stay in.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-date-column-carrying-a-time
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        foreach ($this->storedDayColumns() as $table => $columns) {
            if (! $this->schema()->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if ($this->schema()->hasColumn($table, $column)) {
                    $this->trim($table, $column);
                }
            }
        }
    }

    // The day each value names is preserved and only the time is dropped, so a
    // rollback has nothing to restore: the hour it would put back was never
    // part of the value.
    public function down(): void {}

    /**
     * Every DATE column in the schema except the per-run import staging tables,
     * which are truncated between runs and hold nothing to repair.
     *
     * @return array<string, list<string>>
     */
    private function storedDayColumns(): array
    {
        return [
            'accounts' => ['starting_balance_date', 'opening_balance_as_of_date'],
            'envelope_assignments' => ['period_start'],
            'envelope_moves' => ['period_start'],
            'exchange_rates' => ['rate_date'],
            'forecast_shortfall_windows' => ['starts_at', 'ends_at'],
            'goals' => ['start_date', 'target_date'],
            'recurring_series' => ['next_expected_at'],
            'recurring_series_occurrences' => ['observed_at'],
            'transactions' => ['posted_at', 'value_date'],
        ];
    }

    // Only the exact 'Y-m-d H:i:s' shape, so a value nothing recognises is left
    // as found rather than replaced by a guess at the day it meant -- and a
    // second run matches nothing.
    private function trim(string $table, string $column): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $quoted = $connection->getQueryGrammar()->wrap($column);

        try {
            $connection->table($table)
                ->whereRaw('length('.$quoted.') = 19')
                ->whereRaw('substr('.$quoted.', 11, 1) = \' \'')
                ->update([$column => $connection->raw('substr('.$quoted.', 1, 10)')]);
        } catch (Throwable $e) {
            // exchange_rates and envelope_assignments each carry a UNIQUE index
            // over their day column, so an install holding both shapes of one day
            // would collide here. The phone cannot roll back a partial migration,
            // so that one table is left as found and the rest are still repaired.
            Container::getInstance()->make(LoggerInterface::class)->warning(
                'Stored-day repair skipped a table it could not rewrite.',
                ['table' => $table, 'column' => $column, 'reason' => $e->getMessage()],
            );
        }
    }
};
