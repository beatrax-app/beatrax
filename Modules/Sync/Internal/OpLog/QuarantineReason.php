<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// Why a replayed op was routed to op_log_quarantine instead of applied. The
// backing values are the durable strings written to the `reason` column, so
// they are part of the on-disk contract and must not change.
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

    // A tombstone the database refused because a row still references the one
    // it names under an ON DELETE NO ACTION foreign key. Swallowed into an
    // empty catch, it left the two devices disagreeing about a row with
    // nothing anywhere saying so.
    case DeleteBlockedByReference = 'delete_blocked_by_reference';

    // A day the calendar does not have, supplied by a peer for a DATE column.
    // The applier used to write it through and let the model cast refuse it on
    // the way back out, which left the row holding it. Refusing the op keeps
    // the column answerable instead.
    case ImpossibleDate = 'impossible_date';

    // The two a key arriving later can undo. Kept apart from recoverable()
    // below because a screen reports these as "waiting for a key", and a row
    // held for any other reason must never be given that cause.
    /**
     * @return list<string>
     */
    public static function keyRecoverable(): array
    {
        return [self::GdkDecryptFailed->value, self::StrategyError->value];
    }

    // Every verdict a later state can undo, which is the set worth replaying.
    // MissingReference is not a verdict on the entry the way a forged signature
    // is: it says only that the parent had not landed HERE yet, and the parent
    // routinely arrives afterwards — a category captured by the backfill can be
    // newer than a child logged live, so the child is refused and, without
    // this, never looked at again. Two charges went missing from a paired phone
    // that way, with its op log still holding every entry needed to place them.
    /**
     * @return list<string>
     */
    public static function recoverable(): array
    {
        return [...self::keyRecoverable(), self::MissingReference->value];
    }
}
