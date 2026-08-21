<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// Runs the op-log backfill at the two moments it can matter: switching sync
// on, and a peer joining. Signing needs the unlocked key, so this only ever
// works from inside an unlocked session — never from a headless daemon.
final readonly class PreSyncHistoryCapture
{
    public function __construct(
        private Container $container,
        private DatabaseManager $db,
        private LoggerInterface $log,
    ) {}

    // Only a device that already HOLDS epochs has history worth capturing. A
    // device joining an account enables its identity through
    // enableSyncIdentityWithoutEpoch() — deliberately epoch-less — and its
    // only rows are the defaults every install seeds.

    // A first-time desktop is epoch-less at the instant sync is switched on
    // too; it is captured moments later at pairing-confirm, which runs after
    // the encryption migration has minted epoch 1.
    private function holdsNoEpoch(int $userId): bool
    {
        return $this->db->connection()
            ->table('sync_encryption_state')
            ->where('user_id', $userId)
            ->whereNotNull('current_epoch')
            ->doesntExist();
    }

    // Returns the number of rows captured; 0 when there was nothing to do or
    // the attempt failed. Never throws: capture is a best-effort improvement
    // on an empty log, and neither caller can usefully abort.
    public function capture(int $userId): int
    {
        if ($this->holdsNoEpoch($userId)) {
            return 0;
        }

        try {
            $writer = $this->container->make(OpLogWriter::class);
            $backfiller = $this->container->make(OpLogBackfiller::class);

            // One transaction around the whole capture. Each entry opens its
            // own otherwise, and thousands of individual commits turn a
            // second of work into minutes of fsync on SQLite.
            $captured = $this->db->connection()->transaction(
                static fn (): int => $backfiller->backfill($userId, $writer),
            );

            if ($captured > 0) {
                $this->log->info('PreSyncHistoryCapture: captured pre-sync rows.', [
                    'user_id' => $userId,
                    'rows' => $captured,
                ]);
            }

            return $captured;
        } catch (Throwable $e) {
            $this->log->error('PreSyncHistoryCapture: capture failed.', [
                'user_id' => $userId,
                ...SafeExceptionContext::describe($e),
            ]);

            return 0;
        }
    }
}
