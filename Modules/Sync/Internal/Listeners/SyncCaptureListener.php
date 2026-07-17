<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Events\SavedReportMutated;
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

    /**
     * Routes EnvelopeAssignmentMutated events to the OpLogWriter with
     * table: 'envelope_assignments' (13.2-05 / Req 11).
     *
     * Mirrors handleSplit() exactly: same never-throw try/catch wrapper
     * (D-07), same lazy Container::make(OpLogWriter::class) resolution.
     * `assignmentId` is the stable envelope_assignments.id pk, set once via
     * updateOrCreate() and never regenerated by an edit.
     */
    public function handleEnvelopeAssignment(EnvelopeAssignmentMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleEnvelopeAssignmentEdit($event, $writer),
                'delete' => $this->handleEnvelopeAssignmentDelete($event, $writer),
                'create' => $this->handleEnvelopeAssignmentCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'assignmentId' => $event->assignmentId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: envelope assignment capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'assignmentId' => $event->assignmentId,
                'userId' => $event->userId,
            ]);
        }
    }

    /**
     * Routes EnvelopeMoveMutated events to the OpLogWriter with
     * table: 'envelope_moves' (13.2-05 / Req 11). Moves are append-only —
     * only 'create' and 'delete' mutation types are expected (undo hard-
     * deletes both paired rows rather than editing them, D-07).
     */
    public function handleEnvelopeMove(EnvelopeMoveMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleEnvelopeMoveEdit($event, $writer),
                'delete' => $this->handleEnvelopeMoveDelete($event, $writer),
                'create' => $this->handleEnvelopeMoveCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'moveId' => $event->moveId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: envelope move capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'moveId' => $event->moveId,
                'userId' => $event->userId,
            ]);
        }
    }

    /**
     * Routes EnvelopeSettingMutated events to the OpLogWriter with
     * table: 'envelope_settings' (13.2-05 / Req 11). `settingId` is the
     * stable envelope_settings.id pk, set once via updateOrCreate() and
     * never regenerated by an edit.
     */
    public function handleEnvelopeSetting(EnvelopeSettingMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleEnvelopeSettingEdit($event, $writer),
                'delete' => $this->handleEnvelopeSettingDelete($event, $writer),
                'create' => $this->handleEnvelopeSettingCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'settingId' => $event->settingId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: envelope setting capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'settingId' => $event->settingId,
                'userId' => $event->userId,
            ]);
        }
    }

    private function handleEnvelopeAssignmentEdit(EnvelopeAssignmentMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'envelope_assignments',
                pk: $event->assignmentId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleEnvelopeAssignmentDelete(EnvelopeAssignmentMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'envelope_assignments',
            pk: $event->assignmentId,
        );
    }

    private function handleEnvelopeAssignmentCreate(EnvelopeAssignmentMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'envelope_assignments',
            pk: $event->assignmentId,
            fields: $event->dirtyFields,
        );
    }

    private function handleEnvelopeMoveEdit(EnvelopeMoveMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'envelope_moves',
                pk: $event->moveId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleEnvelopeMoveDelete(EnvelopeMoveMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'envelope_moves',
            pk: $event->moveId,
        );
    }

    private function handleEnvelopeMoveCreate(EnvelopeMoveMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'envelope_moves',
            pk: $event->moveId,
            fields: $event->dirtyFields,
        );
    }

    private function handleEnvelopeSettingEdit(EnvelopeSettingMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'envelope_settings',
                pk: $event->settingId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleEnvelopeSettingDelete(EnvelopeSettingMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'envelope_settings',
            pk: $event->settingId,
        );
    }

    private function handleEnvelopeSettingCreate(EnvelopeSettingMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'envelope_settings',
            pk: $event->settingId,
            fields: $event->dirtyFields,
        );
    }

    /**
     * Routes SavedReportMutated events to the OpLogWriter with
     * table: 'saved_reports' (999.6-01 / Req 9/10). `reportId` is the
     * stable saved_reports.id pk, set once via create() and never
     * regenerated by an edit.
     */
    public function handleSavedReport(SavedReportMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleSavedReportEdit($event, $writer),
                'delete' => $this->handleSavedReportDelete($event, $writer),
                'create' => $this->handleSavedReportCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'reportId' => $event->reportId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: saved report capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'reportId' => $event->reportId,
                'userId' => $event->userId,
            ]);
        }
    }

    private function handleSavedReportEdit(SavedReportMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'saved_reports',
                pk: $event->reportId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleSavedReportDelete(SavedReportMutated $event, OpLogWriter $writer): void
    {
        $writer->writeDelete(
            table: 'saved_reports',
            pk: $event->reportId,
        );
    }

    private function handleSavedReportCreate(SavedReportMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'saved_reports',
            pk: $event->reportId,
            fields: $event->dirtyFields,
        );
    }

    /**
     * Routes NotificationMutated events to the OpLogWriter with
     * table: 'notifications' (18-04 / Req 11/12). `$event->notificationId`
     * is the D-05 deterministic sha256 STRING pk — flows straight through
     * `OpLogWriter::writeSet(pk:)`/`writeCreateRow(pk:)` unchanged since
     * `OpLogEntry::$pk` is already typed `int|string`.
     *
     * Only 'create' and 'edit' are routed — `notifications` has no delete
     * mutation path in this phase. An unrecognized mutationType (including
     * an accidental 'delete') hits the logged default arm rather than
     * throwing, matching every other handler's future-proofing.
     */
    public function handleNotificationMutated(NotificationMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleNotificationEdit($event, $writer),
                'create' => $this->handleNotificationCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'notificationId' => $event->notificationId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: notification capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'notificationId' => $event->notificationId,
                'userId' => $event->userId,
            ]);
        }
    }

    private function handleNotificationEdit(NotificationMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'notifications',
                pk: $event->notificationId,
                field: $field,
                value: $value,
            );
        }
    }

    /**
     * Unlike every other registered table, `notifications.id` is NOT an
     * autoincrement surrogate — it is the D-05 deterministic sha256 digest
     * computed by domain code before insert. `OpLogReplayer`'s CreateRow
     * assembly writes resolved fields straight into the INSERT payload but
     * never adds the pk column itself (every other table relies on the DB's
     * own autoincrement to fill it in), so `id` MUST be carried as an
     * explicit field here or a fresh device's `insertOrIgnore` would
     * silently drop the row on the `id` NOT NULL constraint. Mirrors the
     * existing `user_id` field-carrying precedent documented in
     * `OpLogReplayer`'s CREATE_ROW path (the "SEC finding" comment) —
     * `id` is registered in `MergeRulesRegistry`'s `notifications
     * ._create_required` for the same reason `user_id` is registered on
     * `envelope_assignments`/`envelope_settings`.
     */
    private function handleNotificationCreate(NotificationMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'notifications',
            pk: $event->notificationId,
            fields: ['id' => $event->notificationId, ...$event->dirtyFields],
        );
    }

    /**
     * Routes NotificationPreferenceMutated events to the OpLogWriter with
     * table: 'notification_preferences' (18-04 / D-34). `preferenceId` is
     * the LOCAL autoincrement `notification_preferences.id` surrogate —
     * unlike `notifications`, this table is never independently generated
     * on two devices from the same logical fact (D-05's convergence
     * argument does not apply), so an int pk is correct here.
     */
    public function handleNotificationPreferenceMutated(NotificationPreferenceMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleNotificationPreferenceEdit($event, $writer),
                'create' => $this->handleNotificationPreferenceCreate($event, $writer),
                default => $this->log->warning('SyncCaptureListener: unknown mutationType', [
                    'mutationType' => $event->mutationType,
                    'preferenceId' => $event->preferenceId,
                ]),
            };
        } catch (\Throwable $e) {
            // Swallow — a capture failure must NEVER break the originating save (D-07).
            $this->log->error('SyncCaptureListener: notification preference capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'preferenceId' => $event->preferenceId,
                'userId' => $event->userId,
            ]);
        }
    }

    private function handleNotificationPreferenceEdit(NotificationPreferenceMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            $writer->writeSet(
                table: 'notification_preferences',
                pk: $event->preferenceId,
                field: $field,
                value: $value,
            );
        }
    }

    private function handleNotificationPreferenceCreate(NotificationPreferenceMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'notification_preferences',
            pk: $event->preferenceId,
            fields: $event->dirtyFields,
        );
    }
}
