<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Listeners;

use Modules\Core\Public\Support\SafeExceptionContext;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Internal\Listeners\Concerns\CapturesEnvelopeMutations;
use Modules\Sync\Internal\Listeners\Concerns\CapturesGoalMutations;
use Modules\Sync\Internal\Listeners\Concerns\CapturesReportAndNotificationMutations;
use Modules\Sync\Internal\Listeners\Concerns\CapturesTransactionMutations;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;
use Modules\Sync\Public\Events\GoalContributionMutated;
use Modules\Sync\Public\Events\GoalMutated;
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
    use CapturesEnvelopeMutations;
    use CapturesGoalMutations;
    use CapturesReportAndNotificationMutations;
    use CapturesTransactionMutations;

    private const UNKNOWN_MUTATION_TYPE = 'SyncCaptureListener: unknown mutationType';

    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $log,
    ) {}

    // Sync off, an app-lock engaged, and a mutation raised outside a request
    // are ordinary states, not faults: the writer cannot be built and the
    // binding says so by throwing. At error level that was a line per
    // mutation — 120k in one log — burying the failures this level is for.
    /**
     * @param  array<string, scalar|null>  $context
     */
    private function report(string $message, \Throwable $e, array $context): void
    {
        $context = [...$context, ...SafeExceptionContext::describe($e)];

        if ($e instanceof BindingResolutionException) {
            $this->log->debug($message.' — no writer available; skipped.', $context);

            return;
        }

        $this->log->error($message, $context);
    }

    public function handle(TransactionMutated $event): void
    {
        try {
            // Lazy resolution — OpLogWriter needs runtime device credentials
            // that only exist once the device identity is configured. Until
            // then (or in test contexts without Sync setup), this throws
            // BindingResolutionException, which report() treats as a skip.
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'edit' => $this->handleEdit($event, $writer),
                'delete' => $this->handleDelete($event, $writer),
                'create' => $this->handleCreate($event, $writer),
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'transactionId' => $event->transactionId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: capture failed', $e, [
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'splitId' => $event->splitId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: split capture failed', $e, [
                'mutationType' => $event->mutationType,
                'splitId' => $event->splitId,
                'transactionId' => $event->transactionId,
                'userId' => $event->userId,
            ]);
        }
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'assignmentId' => $event->assignmentId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: envelope assignment capture failed', $e, [
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'moveId' => $event->moveId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: envelope move capture failed', $e, [
                'mutationType' => $event->mutationType,
                'moveId' => $event->moveId,
                'userId' => $event->userId,
            ]);
        }
    }

    // Routes GoalContributionMutated events to the OpLogWriter with table:
    // 'goal_contributions'. Attributions are append-only, so 'edit' is not a
    // state this table can be in and falls through to the unknown-type log.
    public function handleGoalContribution(GoalContributionMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'delete' => $this->handleGoalContributionDelete($event, $writer),
                'create' => $this->handleGoalContributionCreate($event, $writer),
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'contributionId' => $event->contributionId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: goal contribution capture failed', $e, [
                'mutationType' => $event->mutationType,
                'contributionId' => $event->contributionId,
                'userId' => $event->userId,
            ]);
        }
    }

    // The table travels on the event, so a writer for a table with no
    // bespoke handler can still be captured without another event class per
    // table. The dispatch is still by hand at the write site — nothing here
    // discovers writes on its own.
    public function handleEntity(EntityMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'create' => $writer->writeCreateRow($event->table, $event->pk, $event->dirtyFields),
                'edit' => $this->writeEntityEdit($event, $writer),
                'delete' => $writer->writeDelete($event->table, $event->pk),
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'table' => $event->table,
                    'pk' => $event->pk,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: entity capture failed', $e, [
                'mutationType' => $event->mutationType,
                'table' => $event->table,
                'pk' => $event->pk,
                'userId' => $event->userId,
            ]);
        }
    }

    // One op per touched column, so two devices editing different fields of
    // the same row both keep their change. A field named in incrementFields
    // carries this device's delta and is resolved against what this device has
    // already published, because a g_counter op must carry a per-device total.
    private function writeEntityEdit(EntityMutated $event, OpLogWriter $writer): void
    {
        foreach ($event->dirtyFields as $field => $value) {
            if (in_array($field, $event->incrementFields, true) && is_int($value)) {
                $writer->writeIncrement($event->table, $event->pk, $field, $value);

                continue;
            }

            $writer->writeSet($event->table, $event->pk, $field, $value);
        }
    }

    // Routes GoalMutated events to the OpLogWriter with table: 'goals'.
    // Distinct from handleGoalContribution above: that captures which
    // transactions fund a goal, this captures the goal itself.
    public function handleGoal(GoalMutated $event): void
    {
        try {
            $writer = $this->container->make(OpLogWriter::class);

            match ($event->mutationType) {
                'create' => $this->handleGoalCreate($event, $writer),
                'edit' => $this->handleGoalEdit($event, $writer),
                'delete' => $this->handleGoalDelete($event, $writer),
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'goalId' => $event->goalId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: goal capture failed', $e, [
                'mutationType' => $event->mutationType,
                'goalId' => $event->goalId,
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'settingId' => $event->settingId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: envelope setting capture failed', $e, [
                'mutationType' => $event->mutationType,
                'settingId' => $event->settingId,
                'userId' => $event->userId,
            ]);
        }
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'reportId' => $event->reportId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: saved report capture failed', $e, [
                'mutationType' => $event->mutationType,
                'reportId' => $event->reportId,
                'userId' => $event->userId,
            ]);
        }
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'notificationId' => $event->notificationId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: notification capture failed', $e, [
                'mutationType' => $event->mutationType,
                'notificationId' => $event->notificationId,
                'userId' => $event->userId,
            ]);
        }
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
                default => $this->log->warning(self::UNKNOWN_MUTATION_TYPE, [
                    'mutationType' => $event->mutationType,
                    'preferenceId' => $event->preferenceId,
                ]),
            };
        } catch (\Throwable $e) {
            $this->report('SyncCaptureListener: notification preference capture failed', $e, [
                'mutationType' => $event->mutationType,
                'preferenceId' => $event->preferenceId,
                'userId' => $event->userId,
            ]);
        }
    }
}
