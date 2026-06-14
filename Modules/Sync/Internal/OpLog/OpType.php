<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

/**
 * Op-log entry type enum.
 *
 * The rule: NEVER pass a free-form op_type string to the replayer —
 * always add a new case here first.
 *
 * Set            — a field-level write (the common case for LWW merge).
 * DeleteTombstone — a logical delete; the replayer applies delete-wins
 *                   semantics when the tombstone HLC is >= any field-SET
 *                   HLC for the same (table, pk).
 * CreateRow      — spike probe for Open Question 3 (user-created rows
 *                  via op-log: goals, pots, categorization rules). Exists
 *                  in the enum from Wave 1 so Wave 2 can test this path
 *                  without a contract change.
 */
enum OpType: string
{
    case Set = 'set';

    case DeleteTombstone = 'delete_tombstone';

    case CreateRow = 'create_row';
}
