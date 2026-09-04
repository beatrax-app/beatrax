<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners\Concerns;

use Modules\Sync\Internal\OpLog\OpCaptureSink;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

trait CapturesEnvelopeMutations
{
    private function handleEnvelopeAssignmentEdit(EnvelopeAssignmentMutated $event, OpCaptureSink $writer): void
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

    private function handleEnvelopeAssignmentDelete(EnvelopeAssignmentMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeDelete(
            table: 'envelope_assignments',
            pk: $event->assignmentId,
        );
    }

    private function handleEnvelopeAssignmentCreate(EnvelopeAssignmentMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeCreateRow(
            table: 'envelope_assignments',
            pk: $event->assignmentId,
            fields: $event->dirtyFields,
        );
    }

    private function handleEnvelopeMoveEdit(EnvelopeMoveMutated $event, OpCaptureSink $writer): void
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

    private function handleEnvelopeMoveDelete(EnvelopeMoveMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeDelete(
            table: 'envelope_moves',
            pk: $event->moveId,
        );
    }

    private function handleEnvelopeMoveCreate(EnvelopeMoveMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeCreateRow(
            table: 'envelope_moves',
            pk: $event->moveId,
            fields: $event->dirtyFields,
        );
    }

    private function handleEnvelopeSettingEdit(EnvelopeSettingMutated $event, OpCaptureSink $writer): void
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

    private function handleEnvelopeSettingDelete(EnvelopeSettingMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeDelete(
            table: 'envelope_settings',
            pk: $event->settingId,
        );
    }

    private function handleEnvelopeSettingCreate(EnvelopeSettingMutated $event, OpCaptureSink $writer): void
    {
        $writer->writeCreateRow(
            table: 'envelope_settings',
            pk: $event->settingId,
            fields: $event->dirtyFields,
        );
    }
}
