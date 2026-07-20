<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
enum OpType: string
{
    // Set: a field-level write. DeleteTombstone: a logical delete — the
    // replayer applies delete-wins when tombstone HLC >= any field-SET HLC.
    // CreateRow: user-created rows via op-log. NEVER pass a free-form
    // op_type string to the replayer — add a case here first.
    case Set = 'set';

    case DeleteTombstone = 'delete_tombstone';

    case CreateRow = 'create_row';
}
