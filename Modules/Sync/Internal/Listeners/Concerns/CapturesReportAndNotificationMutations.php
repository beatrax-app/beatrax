<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners\Concerns;

use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\NotificationMutated;
use Modules\Sync\Public\Events\SavedReportMutated;

trait CapturesReportAndNotificationMutations
{
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
