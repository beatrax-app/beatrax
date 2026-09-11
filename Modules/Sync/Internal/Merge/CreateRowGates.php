<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;

// What refuses an arriving row, asked once the ids it names are the ones this
// device uses. A create and the second half of a create both come through
// here, so neither can be judged by a rule the other does not answer to.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class CreateRowGates
{
    public function __construct(
        private RowOwnership $ownership,
        private SplitOverfillGate $splitOverfill,
        private OpLogQuarantine $quarantine,
        private UnplacedPeerCreates $unplaced,
    ) {}

    // Ordered: ownership answers whose row this is, and the sum only means
    // anything once that is settled.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function refusalFor(string $table, int|string $pk, array $payload, ArrivingBatch $batch): ?QuarantineReason
    {
        return match (true) {
            ! $this->ownershipAdmits($table, $payload, $batch->userId, $pk) => QuarantineReason::CrossUser,
            default => $this->splitOverfill->reasonToRefuse($table, $pk, $payload, $batch),
        };
    }

    // The one gate that reads the ids as the PEER minted them. Every other
    // runs after translate(), where an id already means a row here -- and a
    // reference this device could not translate is precisely the one whose
    // number is the peer's alone, which is what translation then hides.
    /**
     * @param  array<string, mixed>  $arriving
     */
    public function unplacedParentFor(string $table, array $arriving, string $deviceId, int $userId): ?QuarantineReason
    {
        foreach ($this->ownership->ownedReferences($table) as $column => $parentTable) {
            $named = $arriving[$column] ?? null;

            // A column targeting its own table is the deferral's, not this
            // gate's: translate() never touches one, the partner routinely has
            // not landed, and refusing the row loses a create over its link.
            if ($parentTable === $table || (! is_int($named) && ! is_string($named)) || $named === '') {
                continue;
            }

            if ($this->unplaced->refusedFor($parentTable, $deviceId, $named, $userId)) {
                return QuarantineReason::MissingReference;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    public function record(array $fields, QuarantineReason $reason, string $now): void
    {
        $firstField = reset($fields);

        if ($firstField !== false && $firstField !== []) {
            $this->quarantine->record($firstField[0], $reason, $now);
        }
    }

    // Both halves of the cross-user gate, in the order they must run. The ids a
    // row NAMES are minted per device, so one can land on another household
    // member's row; and a child row carries no user_id, so without the second
    // check an op could attach a condition to ANOTHER user's rule by naming it.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function ownershipAdmits(string $table, array $payload, int $userId, int|string $pk): bool
    {
        return $this->ownership->referencesBelongToUser($table, $payload, $userId, $pk)
            && $this->ownership->parentBelongsToUser($table, $payload, $userId);
    }
}
