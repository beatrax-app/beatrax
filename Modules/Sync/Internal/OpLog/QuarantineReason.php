<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// Why a replayed op was routed to op_log_quarantine instead of applied. The
// backing values are the durable strings written to the `reason` column, so
// they are part of the on-disk contract and must not change.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
enum QuarantineReason: string
{
    case CrossUser = 'cross_user';

    case UnknownTable = 'unknown_table';

    // The entry named a field that is not a real column of its (registered)
    // table. Ed25519-gated and identifier-quoted, so never injection — but
    // caught here so a malformed/unknown column quarantines early instead of
    // failing at the DB write.
    case UnknownColumn = 'unknown_column';

    case MissingDeviceKey = 'missing_device_key';

    case ForgedSignature = 'forged_signature';

    case StrategyError = 'strategy_error';

    case IncompleteCreateRow = 'incomplete_create_row';

    case GdkDecryptFailed = 'gdk_decrypt_failed';

    // The row named a parent the database does not have. Isolated to the one
    // row: an uncaught insert failure aborted the whole replay transaction,
    // so one unsatisfiable reference discarded every op beside it and the
    // poll driving the UI answered 500 instead of advancing.
    case MissingReference = 'missing_reference';
}
