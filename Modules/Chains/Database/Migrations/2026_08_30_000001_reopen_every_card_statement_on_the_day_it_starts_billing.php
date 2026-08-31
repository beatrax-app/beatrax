<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Ledger\Public\Enums\AccountKind;
use Psr\Log\LoggerInterface;

// IcsPdfAdapter derived a card statement's period from the min/max BOOKED day
// while every reader of that period tests membership on posted_at, and ICS
// books a charge on or after the day the card was used -- so a statement always
// opened later than the earliest charge it billed. The derivation now reads
// posted_at, and UNIQUE(user_id, account_id, period_start, period_end) is what
// decides whether a re-import matches the statement already stored: left as
// they are, every stored statement is minted a second time under the period the
// new derivation gives it, and the stale one stays open forever.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-period-derived-from-one-column-and-tested-on-another
 */
return new class extends ModuleMigration
{
    // Repaired in this order because only card_statements carries a UNIQUE over
    // the pair: a summary left behind would re-mint the statement at the old
    // period on the next CardStatementUpserter healing pass, whatever this
    // repaired downstream of it.
    private const array PERIOD_TABLES = ['statement_summaries', 'card_statements'];

    private const int CHUNK = 200;

    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('transactions') || ! $schema->hasTable('accounts')) {
            return;
        }

        foreach (self::PERIOD_TABLES as $table) {
            if ($schema->hasTable($table)) {
                $this->repair($table);
            }
        }
    }

    // Every day written here was read off the rows the statement bills, so a
    // rollback has no earlier day to restore. Re-running up() is a no-op for the
    // same reason the repair is guarded: a statement already opening on its
    // earliest charge is left exactly as found.
    public function down(): void {}

    private function repair(string $table): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->table($table)
            ->join('accounts', 'accounts.id', '=', $table.'.account_id')
            ->where('accounts.kind', AccountKind::IcsCard->value)
            ->whereNotNull($table.'.period_start')
            ->whereNotNull($table.'.period_end')
            ->select([
                $table.'.id as id',
                $table.'.account_id as account_id',
                $table.'.period_start as period_start',
                $table.'.period_end as period_end',
            ])
            ->chunkById(self::CHUNK, function (Collection $rows) use ($connection, $table): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $this->repairOne($connection, $table, $row);
                }
            }, $table.'.id', 'id');
    }

    private function repairOne(Connection $connection, string $table, stdClass $row): void
    {
        $startDay = self::day($row->period_start ?? null);
        $endDay = self::day($row->period_end ?? null);
        if ($startDay === '' || $endDay === '') {
            return;
        }

        // The inverse of the derivation being replaced: these are exactly the
        // rows whose booked days produced the period stored on this statement.
        $bounds = $connection->table('transactions')
            ->where('account_id', is_numeric($row->account_id ?? null) ? (int) $row->account_id : 0)
            ->whereRaw(self::dayExpression($connection, 'booked_at').' between ? and ?', [$startDay, $endDay])
            ->first([
                $connection->raw('min('.self::dayExpression($connection, 'posted_at').') as first_day'),
                $connection->raw('max('.self::dayExpression($connection, 'posted_at').') as last_day'),
            ]);

        $firstDay = is_string($bounds->first_day ?? null) ? $bounds->first_day : '';
        $lastDay = is_string($bounds->last_day ?? null) ? $bounds->last_day : '';

        // A statement whose rows were deleted has nothing to recompute from, and
        // one already opening on or before its earliest charge is either
        // untouched by this defect or repaired by an earlier pass. Both keep the
        // days they have: both columns are NOT NULL, and a range invented to
        // fill them would be a worse answer than the stale one.
        if ($firstDay === '' || $lastDay === '' || $firstDay >= $startDay) {
            return;
        }

        // updated_at is deliberately left alone. Neither table is replicated --
        // both are derived on whichever device read the statement -- so every
        // device repairs its own rows and a bumped timestamp would be a clock
        // this migration made up.
        $values = [
            'period_start' => $firstDay.' 00:00:00',
            'period_end' => $lastDay.' 00:00:00',
        ];

        // The ICS reader writes both balance dates off the period it derived, so
        // a period that moves and a balance date that does not would leave the
        // summary disagreeing with the statement promoted from it.
        if ($table === 'statement_summaries') {
            $values['opening_balance_date'] = $firstDay.' 00:00:00';
            $values['closing_balance_date'] = $lastDay.' 00:00:00';
        }

        try {
            $connection->table($table)
                ->where('id', is_numeric($row->id ?? null) ? (int) $row->id : 0)
                ->update($values);
        } catch (Throwable $e) {
            // Only card_statements can refuse this, and only when another
            // statement already holds the period being written -- the duplicate
            // this migration exists to prevent, already on disk. The phone
            // cannot roll a partial migration back, so that one row is left as
            // found and every other statement is still repaired.
            Container::getInstance()->make(LoggerInterface::class)->warning(
                'A card statement period could not be moved onto the days it bills.',
                ['table' => $table, 'id' => $row->id ?? null, 'reason' => $e->getMessage()],
            );
        }
    }

    private static function dayExpression(Connection $connection, string $column): string
    {
        return 'substr('.$connection->getQueryGrammar()->wrap($column).', 1, 10)';
    }

    private static function day(mixed $value): string
    {
        return is_string($value) ? substr($value, 0, 10) : '';
    }
};
