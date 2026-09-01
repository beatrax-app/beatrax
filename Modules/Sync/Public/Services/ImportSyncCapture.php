<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\CapturesTransactionsForSync;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ImportSyncCapture implements CapturesImportForSync, CapturesTransactionsForSync
{
    // The applier seeds user_id from the local user and never writes the value
    // the wire carried, so the peer's own users row is neither needed nor
    // wanted here — every other parent of a captured row is.
    private const string SEEDED_PARENT = 'users';

    public function __construct(
        private DatabaseManager $db,
        private OpLogBackfiller $backfiller,
        private LoggerInterface $log,
        private Container $container,
        private CoveredTableOrder $tableOrder,
    ) {}

    public function capture(ImportRun $importRun, User $user): void
    {
        $writer = $this->writerOrNull($user);

        if ($writer === null) {
            return;
        }

        $userId = $user->id;
        $transactionIds = $this->transactionIdsFor($importRun->id, $userId);

        // Named outright as well as derived: a run whose rows were all dropped
        // has no transaction to read it off, and the run still has to travel.
        $ids = $this->parentIdsFor($transactionIds, $userId);
        $ids['import_runs'] = array_values(array_unique([...$ids['import_runs'] ?? [], $importRun->id]));
        $ids['transactions'] = $transactionIds;

        $this->captureInOrder($ids, $userId, $writer, ['importRunId' => $importRun->id]);
    }

    // Every writer of ledger rows goes through RecordTransactions, so this is
    // the one hook that covers imports, the cash book, e-mail receipts and the
    // migration pipeline alike. Capturing only the import path left a cash
    // entry on the phone that typed it, for good.
    public function captureTransactions(array $transactionIds, User $user): void
    {
        if ($transactionIds === []) {
            return;
        }

        $writer = $this->writerOrNull($user);

        if ($writer === null) {
            return;
        }

        $userId = $user->id;

        $ids = $this->parentIdsFor($transactionIds, $userId);
        $ids['transactions'] = $transactionIds;

        $this->captureInOrder($ids, $userId, $writer, []);
    }

    // Every covered parent these rows point at, read off the live foreign keys.
    // This was three names written by hand — import_runs, accounts,
    // transactions — and transactions.category_id was missing from it, so a
    // peer refused every transaction naming a category it had never been sent.
    /**
     * @param  list<int>  $transactionIds
     * @return array<string, list<int>>
     */
    private function parentIdsFor(array $transactionIds, int $userId): array
    {
        $ids = [];

        foreach ($this->tableOrder->parentColumns('transactions') as $column => $parent) {
            if ($parent === self::SEEDED_PARENT) {
                continue;
            }

            $ids[$parent] = $transactionIds === [] ? [] : self::intIds(
                $this->db->connection()->table('transactions')
                    ->where('user_id', $userId)
                    ->whereIn('id', $transactionIds)
                    ->whereNotNull($column)
                    ->distinct()
                    ->pluck($column),
            );
        }

        return $ids;
    }

    // Parents before children, in the same insertion order the backfill and
    // the replayer both take, so the three cannot disagree about which row a
    // peer needs first. Tables with nothing to say are dropped rather than
    // asked for an empty capture.
    /**
     * @param  array<string, list<int|string>>  $ids
     * @return list<string>
     */
    private function orderedTables(array $ids): array
    {
        $ordered = [];

        foreach ($this->tableOrder->insertionOrder() as $table) {
            if (($ids[$table] ?? []) !== []) {
                $ordered[] = $table;
            }
        }

        return $ordered;
    }

    // Stops at the first failure rather than carrying on: the order is a
    // dependency order, and children emitted after their parent failed name
    // rows the peer never received, so its foreign keys drop them. Never
    // throws out — a capture failure costs the peer this write, not the data.
    /**
     * @param  array<string, list<int|string>>  $ids
     * @param  array<string, mixed>  $context
     */
    private function captureInOrder(array $ids, int $userId, OpLogWriter $writer, array $context): void
    {
        foreach ($this->orderedTables($ids) as $table) {
            try {
                $this->backfiller->captureRowsById($table, $ids[$table], $userId, $writer);
            } catch (Throwable $e) {
                $this->log->warning('ImportSyncCapture: capture failed; stopped before emitting any child rows.', [
                    'table' => $table,
                    'userId' => $userId,
                    'exception' => $e::class,
                    ...SafeExceptionContext::describe($e),
                ] + $context);

                return;
            }
        }
    }

    // Null when there is no usable device identity — sync off, or the app
    // locked. The write still happened; it travels on the next backfill.
    private function writerOrNull(User $user): ?OpLogWriter
    {
        try {
            return $this->container->make(OpLogWriter::class);
        } catch (Throwable $e) {
            $this->log->debug('ImportSyncCapture: no op-log writer available; nothing captured.', [
                'userId' => $user->id,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /** @return list<int> */
    private function transactionIdsFor(int $importRunId, int $userId): array
    {
        return self::intIds(
            $this->db->connection()->table('transactions')
                ->where('user_id', $userId)
                ->where('import_run_id', $importRunId)
                ->pluck('id'),
        );
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<int>
     */
    private static function intIds(Collection $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
