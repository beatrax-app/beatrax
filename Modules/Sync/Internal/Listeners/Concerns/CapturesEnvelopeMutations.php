<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners\Concerns;

use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
trait CapturesEnvelopeMutations
{
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
}
