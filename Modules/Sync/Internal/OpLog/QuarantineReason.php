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

    // The registry still holds this author's key, but nothing confirms the
    // device any more: removed here, or the peer saying it removed this one.
    // Kept apart from a missing key so the audit row does not name a cause the
    // reader can disprove by opening the device list and seeing the row.
    case UnconfirmedDevice = 'unconfirmed_device';

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

    // Two devices that were apart both took the same autoincrement, so one id
    // names two different rows. Discarded in silence this read as the ordinary
    // idempotent replay, and a move made on a phone was simply never there.
    case PrimaryKeyCollision = 'primary_key_collision';

    // Writing this split leg would carry a transaction's legs past the
    // transaction. The writer requires them to add up exactly; a device that
    // split the same transaction while apart sends a whole second set.
    case SplitWouldOverfillTransaction = 'split_would_overfill_transaction';

    // The gate above could not read one of the two amounts it compares. Folded
    // into a number it read as legs that fit, which admitted the money the gate
    // exists to stop; recoverable because the read is the only thing that
    // failed and a later pass takes it again.
    case SplitSumUnreadable = 'split_sum_unreadable';

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

    // The two refusals that happen while INSERTING a row, as opposed to while
    // merging a field into one. Only these are spent by the row turning up:
    // a field op held for an unreadable value is still held when the row it
    // belongs to is sitting right there.
    /**
     * @return list<string>
     */
    public static function createRefusals(): array
    {
        return [self::IncompleteCreateRow->value, self::MissingReference->value];
    }

    // The refusal that happens while DELETING a row. Spent by the row being
    // gone, which is the mirror of createRefusals() above, and recoverable for
    // the same reason MissingReference is: what blocked it is a row the log
    // may still carry a tombstone for.
    /**
     * @return list<string>
     */
    public static function deleteRefusals(): array
    {
        return [self::DeleteBlockedByReference->value];
    }

    // Every verdict a later state can undo. MissingReference is not a verdict
    // on the entry the way a forged signature is: the parent had not landed
    // HERE yet and routinely lands afterwards — two charges went missing from a
    // paired phone whose op log still held every entry needed to place them.
    /**
     * @return list<string>
     */
    public static function recoverable(): array
    {
        return [
            ...self::keyRecoverable(),
            ...self::deleteRefusals(),
            self::MissingReference->value,
            self::SplitSumUnreadable->value,
        ];
    }
}
