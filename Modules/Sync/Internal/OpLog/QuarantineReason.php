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

    case MissingDeviceKey = 'missing_device_key';

    case ForgedSignature = 'forged_signature';

    case StrategyError = 'strategy_error';

    case IncompleteCreateRow = 'incomplete_create_row';

    case GdkDecryptFailed = 'gdk_decrypt_failed';
}
