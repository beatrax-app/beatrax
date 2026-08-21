<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Enums;

// Never hand SpatieAuditWriter a free-form description — add a case here first,
// or dev_mode_audit.description stops being filterable.
enum AuditEvent: string
{
    case CommandExecuted = 'command_executed';

    case QueueAction = 'queue_action';

    case SqlSelect = 'sql.select';

    // Triple-gated only via the bulk path; a single-row delete gets the
    // page-level single confirm.
    case QueuePendingDelete = 'queue.pending.delete';

    case QueueFailedForget = 'queue.failed.forget';

    case QueueFailedRetry = 'queue.failed.retry';

    case QueueBatchCancel = 'queue.batch.cancel';

    case QueueBatchDelete = 'queue.batch.delete';

    case QueueBatchRetryFailures = 'queue.batch.retry-failures';

    case QueueBulkDelete = 'queue.bulk.delete';

    case QueueBulkRetry = 'queue.bulk.retry';
}
