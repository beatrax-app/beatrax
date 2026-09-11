<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\QueryException;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Psr\Log\LoggerInterface;
use Throwable;

// A refusal is two writes and neither substitutes for the other: a durable hold
// saying an op was turned away, and a line saying why. The hold is an index with
// no column for a cause; the line carries the cause and is swept. Written by
// hand at three sites, the two had already drifted apart.
/**
 * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md#a-reason-code-is-not-a-cause
 */
final readonly class RefusedOpRecord
{
    public function __construct(
        private OpLogQuarantine $quarantine,
        private ?LoggerInterface $logger = null,
    ) {}

    // An op the merge could not turn into a column value. The coordinate names
    // the field, which the hold has no column for, and `strategy_error` covers
    // the strategy, the encode and the re-seal alike.
    public function strategyError(OpLogEntry $entry, string $table, string $field, int|string $pk, string $now, Throwable $e): void
    {
        $this->quarantine->record($entry, QuarantineReason::StrategyError, $now);

        // describe() is a strip by design: an exception message can quote the
        // cell it choked on, and `transactions.note` is sealed at rest. The
        // class names the failure and can name nothing out of a row.
        $this->line('an op could not be merged into the column it names.', $table, $pk, QuarantineReason::StrategyError, [
            'field' => $field,
            'device_id' => $entry->deviceId,
            ...SafeExceptionContext::describe($e),
        ]);
    }

    // Every refusal the database itself raised on a create. The already-present
    // arm is answered by AlreadyPresentCreate, where the payload that identifies
    // the twin is in hand.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    public function databaseRefusedInsert(string $table, int|string $pk, QueryException $e, array $fields, string $now): void
    {
        $failure = CreateRowInsertFailure::classify($e);

        $firstField = reset($fields);

        if ($firstField !== false && $firstField !== []) {
            $this->quarantine->record($firstField[0], $failure->quarantineReason(), $now);
        }

        // SQLite answers NOT NULL, FOREIGN KEY and UNIQUE all with 23000, which
        // is what the classification separates -- so the line names the
        // classified reason, never describe()'s own exception class.
        $this->line('the database refused a replayed CreateRow.', $table, $pk, $failure->quarantineReason(), SafeExceptionContext::describe($e));
    }

    // A tombstone the database refused because a row still references the one it
    // names. Swallowed into an empty catch, it left two devices disagreeing
    // about a row with nothing anywhere saying so.
    public function blockedDelete(string $table, int|string $pk, OpLogEntry $tomb, string $now): void
    {
        $this->quarantine->record($tomb, QuarantineReason::DeleteBlockedByReference, $now);

        $this->line('the database refused a replayed tombstone.', $table, $pk, QuarantineReason::DeleteBlockedByReference, [
            'device_id' => $tomb->deviceId,
        ]);
    }

    // One spelling of the coordinate, and one of the reason key. describe()
    // returns a `reason` of its own and spread last it wins, which is why the
    // classified reason is named `quarantine_reason` on every line here.
    /**
     * @param  array<string, mixed>  $context
     */
    private function line(string $what, string $table, int|string $pk, QuarantineReason $reason, array $context): void
    {
        $this->logger?->warning('OpLogEntryApplier: '.$what, [
            'table' => $table,
            'pk' => (string) $pk,
            'quarantine_reason' => $reason->value,
            ...$context,
        ]);
    }
}
