<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// Runs the op-log backfill at the two moments it can matter: switching sync on,
// and a peer joining. Signing needs the unlocked key, so this only ever works
// from inside an unlocked session — never a daemon and never a queue worker,
// which is why a capture too large for one request is sliced across several.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md
 */
final readonly class PreSyncHistoryCapture
{
    public function __construct(
        private Container $container,
        private DatabaseManager $db,
        private LoggerInterface $log,
        private BackfillProgress $progress,
        private Clock $clock,
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

    // Returns the rows captured BY THIS SLICE; 0 when there was nothing to do or
    // the attempt failed. Never throws: capture is a best-effort improvement on
    // an empty log and neither caller can usefully abort. A slice that runs out
    // of budget leaves the rest owed for the resume driver.
    public function capture(int $userId, ?BackfillBudget $budget = null): int
    {
        if ($this->holdsNoEpoch($userId)) {
            return 0;
        }

        $this->progress->open($userId);

        return $this->slice($userId, $budget);
    }

    // Continues a capture already owed, and does nothing at all otherwise.
    // Separate from capture() because the driver runs on every request: it may
    // finish work somebody asked for, and must never start work nobody did.
    public function resume(int $userId): int
    {
        if (! $this->progress->isOpen($userId) || $this->holdsNoEpoch($userId)) {
            return 0;
        }

        return $this->slice($userId, null);
    }

    private function slice(int $userId, ?BackfillBudget $budget): int
    {
        $writer = $this->signingWriter();

        // Owed, not failed. Retiring the walk here would mean locking the app
        // mid-capture abandoned it until the next pairing.
        if ($writer === null) {
            return 0;
        }

        try {
            $backfiller = $this->container->make(OpLogBackfiller::class);

            $captured = $backfiller->backfill(
                $userId,
                $writer,
                $budget ?? BackfillBudget::forOneSlice($this->clock),
            );

            if ($captured > 0) {
                $this->log->info('PreSyncHistoryCapture: captured pre-sync rows.', [
                    'user_id' => $userId,
                    'rows' => $captured,
                    'complete' => ! $this->progress->isOpen($userId),
                ]);
            }

            return $captured;
        } catch (Throwable $e) {
            // Retired rather than left owed. A row this device cannot read is a
            // permanent verdict, and a driver that runs on every request would
            // otherwise reach the same failing chunk every few seconds forever.
            // Both callers of capture() are user actions that happen again.
            $this->progress->close($userId);

            $this->log->error('PreSyncHistoryCapture: capture failed.', [
                'user_id' => $userId,
                ...SafeExceptionContext::describe($e),
            ]);

            return 0;
        }
    }

    // Null whenever this session cannot sign — an app-lock that is engaged, or
    // a device with no identity yet. Separated from the walk's own failures
    // because only one of the two is a verdict on the rows.
    private function signingWriter(): ?OpLogWriter
    {
        try {
            return $this->container->make(OpLogWriter::class);
        } catch (Throwable) {
            return null;
        }
    }
}
