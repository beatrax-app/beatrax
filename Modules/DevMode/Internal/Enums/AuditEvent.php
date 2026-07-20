<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Enums;

// Canonical taxonomy for every event written into dev_mode_audit by
// SpatieAuditWriter — never pass a free-form description to the writer;
// always add a new case here first, so `description` stays filterable.
enum AuditEvent: string
{
    case CommandExecuted = 'command_executed';

    case CommandCancelled = 'command_cancelled';

    case QueueAction = 'queue_action';

    case SqlSelect = 'sql.select';

    // Triple-gate required only when invoked through the bulk path
    // (QueueBulkDelete); single-row deletes route through the
    // page-level single-confirm instead.
    case QueuePendingDelete = 'queue.pending.delete';

    case QueueFailedForget = 'queue.failed.forget';

    // Single failed-job re-dispatched (payload re-pushed) AND the
    // failed_jobs row removed.
    case QueueFailedRetry = 'queue.failed.retry';

    case QueueBatchCancel = 'queue.batch.cancel';

    case QueueBatchDelete = 'queue.batch.delete';

    // Mirrors bulk QueueFailedRetry, constrained to the batch's
    // failed_job_ids list.
    case QueueBatchRetryFailures = 'queue.batch.retry-failures';

    // QueueBulkDelete is the triple-gate destructive path;
    // QueueBulkRetry is the single-confirm non-destructive path.
    case QueueBulkDelete = 'queue.bulk.delete';

    case QueueBulkRetry = 'queue.bulk.retry';
}
