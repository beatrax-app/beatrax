<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\TransactionMutated;
use Psr\Log\LoggerInterface;

/**
 * Routes TransactionMutated events to the OpLogWriter.
 *
 * Wired in SyncServiceProvider::boot() via a class_exists()-guarded
 * events->listen() call (D-01 / Plan 02 provider pattern).
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
}
