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
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ImportSyncCapture implements CapturesImportForSync, CapturesTransactionsForSync
{
    // Parents before children, so a peer replaying these accepts them: a
    // transaction carries a NOT NULL import_run_id and an account_id, and an
    // insert naming a row the peer has never seen fails the foreign key.
    private const ORDER = ['import_runs', 'accounts', 'transactions'];

    public function __construct(
        private DatabaseManager $db,
        private OpLogBackfiller $backfiller,
        private LoggerInterface $log,
        private Container $container,
    ) {}

    public function capture(ImportRun $importRun, User $user): void
    {
        $writer = $this->writerOrNull($user);

        if ($writer === null) {
            return;
        }

        $userId = $user->id;
        $transactionIds = $this->transactionIdsFor($importRun->id, $userId);

        $ids = [
            'import_runs' => [$importRun->id],
            'accounts' => $this->accountIdsFor($transactionIds, $userId),
            'transactions' => $transactionIds,
        ];

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

        $ids = [
            'import_runs' => $this->importRunIdsFor($transactionIds, $userId),
            'accounts' => $this->accountIdsFor($transactionIds, $userId),
            'transactions' => $transactionIds,
        ];

        $this->captureInOrder($ids, $userId, $writer, []);
    }

    // Stops at the first failure rather than carrying on: ORDER is a
    // dependency order, and children emitted after their parent failed name
    // rows the peer never received, so its foreign keys drop them. Never
    // throws out — a capture failure costs the peer this write, not the data.
    /**
     * @param  array<string, list<int|string>>  $ids
     * @param  array<string, mixed>  $context
     */
    private function captureInOrder(array $ids, int $userId, OpLogWriter $writer, array $context): void
    {
        foreach (self::ORDER as $table) {
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

    /**
     * @param  list<int>  $transactionIds
     * @return list<int>
     */
    private function importRunIdsFor(array $transactionIds, int $userId): array
    {
        return self::intIds(
            $this->db->connection()->table('transactions')
                ->where('user_id', $userId)
                ->whereIn('id', $transactionIds)
                ->distinct()
                ->pluck('import_run_id'),
        );
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
     * @param  list<int>  $transactionIds
     * @return list<int>
     */
    private function accountIdsFor(array $transactionIds, int $userId): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return self::intIds(
            $this->db->connection()->table('transactions')
                ->where('user_id', $userId)
                ->whereIn('id', $transactionIds)
                ->distinct()
                ->pluck('account_id'),
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
