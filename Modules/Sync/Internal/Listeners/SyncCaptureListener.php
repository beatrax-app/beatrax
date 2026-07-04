<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Psr\Log\LoggerInterface;

/**
 * Routes TransactionMutated events to the OpLogWriter.
 *
 * Wired in SyncServiceProvider::boot() via a class_exists()-guarded
 * events->listen() call (D-01 / Plan 02 provider pattern).
 *
 * ## Emit-after-commit contract (WR-06)
 *
 * OpLogWriter::writeEntry() opens its OWN DB transaction (op insert + HLC
 * clock-state upsert). Emit sites MUST dispatch TransactionMutated only AFTER
 * the originating write transaction has COMMITTED — never from inside an open
 * transaction. If a mutation event were dispatched mid-transaction, the
 * writer's transaction would degrade into a savepoint of the outer one: an
 * outer rollback would then discard the op insert while the in-memory HLC tick
 * had already advanced (recovered only on next boot from hlc_clock_state),
 * breaking the op's atomicity-vs-outer-rollback guarantee. The reclassify path
 * (TransactionDetail::reclassify) already closes its transaction before
 * dispatching; all current and future emit sites must follow the same shape.
 *
 * ## Never-throw contract (D-07)
 *
 * The entire handler body is wrapped in try/catch(\Throwable). A capture
 * failure is logged but NEVER propagated — a broken op-log write must
 * never abort or roll back the user's originating save action. The user's
 * data is always written first; the op-log is a secondary concern.
 *
 * ## Lazy OpLogWriter resolution
 *
 * OpLogWriter requires runtime device credentials (deviceId, userId, secretKey,
 * publicKey) that are only available after Phase 12 configures the device identity.
 * The writer is resolved from the container lazily inside handle(), so if the
 * container cannot resolve it (e.g. no device creds bound yet, or in test contexts
 * that don't set up Sync), the BindingResolutionException is caught by the
 * try/catch and the listener returns normally — the originating save is never aborted.
 *
 * ## Routing by mutationType
 *
 * - 'edit'   → one OpLogWriter::writeSet() per dirty field
 * - 'delete' → OpLogWriter::writeDelete()
 * - 'create' → OpLogWriter::writeCreateRow()
 * - unknown  → logged and ignored (future-proof against new mutation types)
 */
final class SyncCaptureListener
{
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(TransactionMutated $event): void
    {
        try {
            // Lazy resolution — Phase 12 binds OpLogWriter with device creds.
            // Until then (or in test contexts without Sync setup), this throws
            // BindingResolutionException which the catch block swallows (D-07).
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleEdit($event, $writer),
                'delete' => $this->handleDelete($event, $writer),
                'create' => $this->handleCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'transactionId' => $event->transactionId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'transactionId' => $event->transactionId,
                'userId' => $event->userId,
            ]);
        }
    }

    /**
     * Routes TransactionSplitMutated events to the OpLogWriter with
     * table: 'transaction_splits' (13.1-03 / Req 10).
     *
     * Mirrors handle() exactly: same never-throw try/catch wrapper (D-07),
     * same lazy Container::make(OpLogWriter::class) resolution. The leg's
     * STABLE splitId (transaction_splits.id) is the pk — SaveTransactionSplit's
     * PK-preserving edit diff never regenerates it, so ops are keyed on a
     * stable per-(table,pk,field) identity across edits.
     */
    public function handleSplit(TransactionSplitMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleSplitEdit($event, $writer),
                'delete' => $this->handleSplitDelete($event, $writer),
                'create' => $this->handleSplitCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'splitId' => $event->splitId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: split capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'splitId' => $event->splitId,
                'transactionId' => $event->transactionId,
                'userId' => $event->userId,
            ]);
        }
    }

    private function handleEdit(TransactionMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'transactions',
                pk: $event->transactionId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleDelete(TransactionMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'transactions',
            pk: $event->transactionId,
        );
    }

    private function handleCreate(TransactionMutated $event, OpLogWriter $writer): void
    {
        // IN-02: write the CreateRow snapshot directly — the previous
        // writeCreateFields() one-line indirection added no value.
        $writer->writeCreateRow(
            table: 'transactions',
            pk: $event->transactionId,
            fields: $event->dirtyFields,
        );
    }

    private function handleSplitEdit(TransactionSplitMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'transaction_splits',
                pk: $event->splitId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleSplitDelete(TransactionSplitMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'transaction_splits',
            pk: $event->splitId,
        );
    }

    private function handleSplitCreate(TransactionSplitMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'transaction_splits',
            pk: $event->splitId,
            fields: $event->dirtyFields,
        );
    }
}
