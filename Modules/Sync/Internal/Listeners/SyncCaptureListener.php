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
 * @link ../../../../.docs/features/sync/architecture.md
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
            // Lazy resolution — OpLogWriter needs runtime device credentials
            // that only exist once the device identity is configured. Until
            // then (or in test contexts without Sync setup), this throws
            // BindingResolutionException which the catch block swallows.
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
            $this->log->error('SyncCaptureListener: capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'transactionId' => $event->transactionId,
                'userId' => $event->userId,
            ]);
        }
    }

    // Routes TransactionSplitMutated events to the OpLogWriter with table:
    // 'transaction_splits'. Mirrors handle() exactly. The leg's STABLE
    // splitId (transaction_splits.id) is the pk — SaveTransactionSplit's
    // PK-preserving edit diff never regenerates it.
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
        // Writes the CreateRow snapshot directly — a previous
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

    // Routes EnvelopeAssignmentMutated events to the OpLogWriter with table:
    // 'envelope_assignments'. Mirrors handleSplit() exactly. `assignmentId`
    // is the stable envelope_assignments.id pk, set once via updateOrCreate()
    // and never regenerated by an edit.
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
            $this->log->error('SyncCaptureListener: envelope assignment capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'assignmentId' => $event->assignmentId,
                'userId' => $event->userId,
            ]);
        }
    }

    // Routes EnvelopeMoveMutated events to the OpLogWriter with table:
    // 'envelope_moves'. Moves are append-only — only 'create' and 'delete'
    // mutation types are expected (undo hard-deletes both paired rows).
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
            $this->log->error('SyncCaptureListener: envelope move capture failed', [
                'exception' => $e->getMessage(),
                'mutationType' => $event->mutationType,
                'moveId' => $event->moveId,
                'userId' => $event->userId,
            ]);
        }
    }

    // Routes EnvelopeSettingMutated events to the OpLogWriter with table:
    // 'envelope_settings'. `settingId` is the stable envelope_settings.id
    // pk, set once via updateOrCreate() and never regenerated by an edit.
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

    // Routes SavedReportMutated events to the OpLogWriter with table:
    // 'saved_reports'. `reportId` is the stable saved_reports.id pk, set
    // once via create() and never regenerated by an edit.
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

    // Routes NotificationMutated events to the OpLogWriter with table:
    // 'notifications'. `notificationId` is a deterministic sha256 STRING pk
    // (OpLogEntry::$pk is already typed int|string). Only 'create' and
    // 'edit' are routed — notifications has no delete mutation path here.
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

    // Unlike every other registered table, notifications.id is NOT an
    // autoincrement surrogate — it is a deterministic sha256 digest, so it
    // MUST be carried as an explicit field or a fresh device's
    // insertOrIgnore would silently drop the row (see MergeRulesRegistry).
    private function handleNotificationCreate(NotificationMutated $event, OpLogWriter $writer): void
    {
        $writer->writeCreateRow(
            table: 'notifications',
            pk: $event->notificationId,
            fields: ['id' => $event->notificationId, ...$event->dirtyFields],
        );
    }

    // Routes NotificationPreferenceMutated events to the OpLogWriter with
    // table: 'notification_preferences'. `preferenceId` is a LOCAL
    // autoincrement id surrogate — unlike notifications, this table is never
    // independently generated on two devices from the same logical fact.
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
