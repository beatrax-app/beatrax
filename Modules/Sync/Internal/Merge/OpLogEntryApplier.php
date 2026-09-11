<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Public\Services\DependentRowCascade;
use Psr\Log\LoggerInterface;

final readonly class OpLogEntryApplier
{
    private CreateRowGates $gates;

    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
        private OpLogValueProjector $projector,
        private OpLogQuarantine $quarantine,
        private RowOwnership $ownership,
        private SuppliedDateGate $suppliedDates,
        private SelfReferenceDeferral $selfReferences,
        private SplitCreateTail $splitTail,
        private TransferPairCascade $pairCascade,
        private PeerRowAliases $aliases,
        private AlreadyPresentCreate $alreadyPresent,
        private SuppliedCreationTime $creationTime,
        private SplitOverfillGate $splitOverfill,
        private DependentRowCascade $cascade,
        private ?LoggerInterface $logger = null,
    ) {
        // Built here, not injected: these are this class's own gates split out
        // of it, and a parameter added to the middle of the list above becomes
        // somebody else's argument at the positional call sites.
        $this->gates = new CreateRowGates($ownership, $splitOverfill, $quarantine, new UnplacedPeerCreates($db, $aliases));
    }

    /**
     * @param  list<OpLogEntry>  $verified
     * @return list<OpLogEntry>
     */
    public function sortByHlc(array $verified): array
    {
        usort(
            $verified,
            fn (OpLogEntry $a, OpLogEntry $b): int => HybridLogicalClock::compare(
                $a->hlcL, $a->hlcC, $a->deviceId,
                $b->hlcL, $b->hlcC, $b->deviceId,
            ),
        );

        return $verified;
    }

    // Single pass over the HLC-sorted entries into three maps: tombstones
    // [table][pk] => winning DELETE_TOMBSTONE; creates[table][pk][field] =>
    // list; candidatesByField[table][pk][field] => HLC-sorted SET list.
    /**
     * @param  list<OpLogEntry>  $sorted
     * @return array{
     *     0: array<string, array<int|string, array<string, list<OpLogEntry>>>>,
     *     1: array<string, array<int|string, OpLogEntry>>,
     *     2: array<string, array<int|string, array<string, list<OpLogEntry>>>>
     * }
     */
    public function partitionByOpType(array $sorted): array
    {
        /** @var array<string, array<int|string, array<string, list<OpLogEntry>>>> $candidatesByField */
        $candidatesByField = [];

        /** @var array<string, array<int|string, OpLogEntry>> $tombstones */
        $tombstones = [];

        /** @var array<string, array<int|string, array<string, list<OpLogEntry>>>> $creates */
        $creates = [];

        foreach ($sorted as $entry) {
            $pk = $entry->pk;

            if ($entry->opType === OpType::DeleteTombstone) {
                $tombstones[$entry->table][$pk] = $entry;
            } elseif ($entry->opType === OpType::CreateRow) {
                $creates[$entry->table][$pk][$entry->field][] = $entry;
            } else {
                $candidatesByField[$entry->table][$pk][$entry->field][] = $entry;
            }
        }

        return [$candidatesByField, $tombstones, $creates];
    }

    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $creates
     * @param  array<string, array<int|string, OpLogEntry>>  $tombstones
     */
    public function applyCreates(
        array $creates,
        array $tombstones,
        ArrivingBatch $batch,
        ReplayedRows $applied,
    ): void {
        /** @var list<array{table: string, pk: int|string, deviceId: string, values: array<string, mixed>}> $deferred */
        $deferred = [];

        foreach ($creates as $table => $rows) {
            foreach ($rows as $pk => $fields) {
                // Keyed by the id the row is HERE under, which a re-homed
                // create makes different from the one the op names: written
                // back under the peer's, the deferred link landed on the
                // unrelated local row already sitting at it.
                foreach ($this->applyCreatedRow($table, $pk, $fields, $tombstones[$table][$pk] ?? null, $batch, $applied) as $link) {
                    $deferred[] = $link;
                }
            }
        }

        $this->selfReferences->apply($deferred, $batch->userId);
    }

    // Builds, translates, THEN judges. Every gate here reads ids, and until
    // translate() has run they are the peer's: a legitimate row was refused as
    // another reader's, and a leg that overfills was admitted because the
    // transaction it named could not be found under the id it arrived with.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return list<array{table: string, pk: int|string, deviceId: string, values: array<string, mixed>}>
     */
    private function applyCreatedRow(
        string $table,
        int|string $pk,
        array $fields,
        ?OpLogEntry $tomb,
        ArrivingBatch $batch,
        ReplayedRows $applied,
    ): array {
        $first = reset($fields);
        $deviceId = $first !== false && $first !== [] ? $first[0]->deviceId : '';

        $admitted = $this->admissibleCreate($table, $pk, $fields, $tomb, $batch, $deviceId);

        if ($admitted === null) {
            return [];
        }

        ['payload' => $payload, 'here' => $here] = $admitted;

        // A self-referential FK cannot be satisfied at insert time: transfer
        // pairs point at EACH OTHER, so whichever row lands first names a
        // partner that does not exist. Stripped here, set once both exist.
        $selfRefs = $this->selfReferences->extract($table, $payload);

        $local = $this->insertCreatedRow($table, $payload, $fields, $batch->now, $deviceId, $here, $batch->userId);

        if ($local === null) {
            return [];
        }

        $applied->rowCreated($table, $local, $batch->userId);

        // The device is carried, not the resolution: the partner an extracted
        // link names has not landed yet, so there is no alias to read now. The
        // deferral asks the map for it on every round instead.
        return $selfRefs === [] ? [] : [['table' => $table, 'pk' => $local, 'deviceId' => $deviceId, 'values' => $selfRefs]];
    }

    // The row to insert, or null when there is nothing left to insert: a
    // tombstone outranks it, no payload could be built, a gate refused it, or
    // it was the second half of a create and has been filled in as one.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return array{payload: array<string, mixed>, here: int|string}|null
     */
    private function admissibleCreate(
        string $table,
        int|string $pk,
        array $fields,
        ?OpLogEntry $tomb,
        ArrivingBatch $batch,
        string $deviceId,
    ): ?array {
        $outranked = $tomb !== null && $this->tombstoneWins($table, $tomb, $fields);
        $payload = $outranked ? null : $this->buildCreatePayload($table, $pk, $fields, $batch->userId, $batch->now);

        if ($payload === null) {
            return null;
        }

        // Asked here because this is the last point at which the ids are the
        // peer's. A parent whose own create was refused is here under nobody's
        // id, so the number names whatever local row happens to wear it.
        $unplaced = $this->gates->unplacedParentFor($table, $payload, $deviceId, $batch->userId);

        // The ids this row NAMES, rewritten to the ones this device uses for
        // the same logical rows: a peer that seeded its own reference data
        // names it by an id only that device ever had.
        $payload = $this->aliases->translate($table, $deviceId, $payload, $batch->userId);

        // And the id the row itself is here under. A create re-homed on an
        // earlier frame is already here under another one, and re-sent once
        // the row that collided is gone it inserted a SECOND row for the
        // same logical one rather than colliding with its own.
        $here = $this->aliases->resolvePk($table, $deviceId, $pk, $batch->userId);
        $payload['id'] = $here;

        // The tail branch used to fill() from inside this question and return
        // false, so the caller bailed before the gates -- and before
        // translate(), which never ran on what it had already written.
        $required = array_diff($this->rules->requiredCreateColumns($table), self::SEEDED_BY_APPLIER);
        $whole = array_diff($required, array_keys($fields)) === [];

        // A half create is judged on the row the fill would leave, inside the
        // tail; the gates below read a payload it does not have. An unplaced
        // parent is the exception and refuses either, because the id is wrong
        // whichever of the two would write it.
        $reason = $unplaced ?? ($whole ? $this->gates->refusalFor($table, $here, $payload, $batch) : null);

        if ($reason !== null) {
            $this->gates->record($fields, $reason, $batch->now);
        } elseif (! $whole) {
            $this->applyCreatedTail($table, $here, $payload, $fields, $batch);
        }

        return $reason === null && $whole ? ['payload' => $payload, 'here' => $here] : null;
    }

    // The fourth way a row arrives, and the one that used to write with no gate
    // between it and the table. Judged on the row the write would LEAVE, not on
    // the payload offered: the tail skips a column already holding a value, and
    // refusing the whole payload loses a tail over a column it would not touch.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function applyCreatedTail(string $table, int|string $pk, array $payload, array $fields, ArrivingBatch $batch): void
    {
        if (! $this->splitTail->rowIsHere($table, $pk, $batch->userId)) {
            $this->gates->record($fields, QuarantineReason::IncompleteCreateRow, $batch->now);

            return;
        }

        $plan = $this->splitTail->planFill($table, $pk, $payload, $batch->userId, SuppliedCreationTime::seededValueFor($fields));

        if ($plan === null) {
            return;
        }

        $reason = $this->gates->refusalFor($table, $pk, $plan['after'], $batch);

        if ($reason !== null) {
            $this->gates->record($fields, $reason, $batch->now);

            return;
        }

        $this->splitTail->write($table, $pk, $plan['values'], $batch->userId);
    }

    // buildCreatePayload() writes these from the op itself -- the pk, and the
    // owner it re-seeds even when the op carries one -- so a rule naming either
    // is satisfied before a field is read. Requiring them discarded rows the
    // applier could have written, and seven covered tables named user_id.
    private const array SEEDED_BY_APPLIER = ['id', 'user_id'];

    // Mirrors applyFieldMerge(): one unusable op is isolated, not allowed to
    // roll back every op replayed with it. A plain insert, NOT insertOrIgnore:
    // OR IGNORE silences a NOT NULL violation as readily as a duplicate, so a
    // create split across two frames wrote no row and reported success.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return int|string|null The id the row is here under, or null when it was refused and recorded.
     */
    private function insertCreatedRow(string $table, array $payload, array $fields, string $now, string $deviceId, int|string $pk, int $userId): int|string|null
    {
        try {
            $this->db->connection()->table($table)->insert($payload);

            return $pk;
        } catch (QueryException $e) {
            // By the pk it is the idempotent re-apply. By ANOTHER unique index
            // it is a second id for one row, and the peer's id has to keep
            // meaning something or every child naming it is orphaned.
            if (CreateRowInsertFailure::classify($e) === CreateRowInsertFailure::AlreadyPresent) {
                return $this->alreadyPresent->answer($table, $payload, $fields, $now, $deviceId, $pk, $userId);
            }

            $this->recordRefusedInsert($table, $e, $fields, $now);

            return null;
        }
    }

    // Every refusal the database itself raised. The already-present arm is
    // answered above, where the payload that identifies the twin is in hand.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function recordRefusedInsert(string $table, QueryException $e, array $fields, string $now): void
    {
        $failure = CreateRowInsertFailure::classify($e);

        $firstField = reset($fields);

        if ($firstField !== false && $firstField !== []) {
            $this->quarantine->record($firstField[0], $failure->quarantineReason(), $now);
        }

        // Not 'reason': describe() returns its own, and spread last it wins —
        // so the line reported the exception class, and every refusal read as
        // QueryException/23000. SQLite answers NOT NULL, FOREIGN KEY and UNIQUE
        // all with 23000, which is exactly what the classification separates.
        $this->logger?->warning('OpLogEntryApplier: the database refused a replayed CreateRow.', [
            'table' => $table,
            'quarantine_reason' => $failure->quarantineReason()->value,
            ...SafeExceptionContext::describe($e),
        ]);
    }

    // A strictly later tombstone always wins and an earlier one never does; the
    // table's `_delete_wins` decides the exact tie alone. `accounts` sets it
    // false, so an edit made at the same instant keeps a row that carries a
    // ledger. Both delete paths read this, for one order and one rule.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function tombstoneWins(string $table, OpLogEntry $tomb, array $fields): bool
    {
        $max = null;

        foreach ($fields as $fieldEntries) {
            foreach ($fieldEntries as $fieldEntry) {
                if ($max === null || HybridLogicalClock::compare(
                    $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                    $max->hlcL, $max->hlcC, $max->deviceId,
                ) > 0) {
                    $max = $fieldEntry;
                }
            }
        }

        if ($max === null) {
            return false;
        }

        $order = HybridLogicalClock::compare(
            $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
            $max->hlcL, $max->hlcC, $max->deviceId,
        );

        return $order > 0 || ($order === 0 && $this->rules->deleteWins($table));
    }

    // A CreateRow op may legitimately carry a 'user_id' field, and the resolve
    // loop would let it overwrite the seeded authoritative one. insertOrIgnore
    // has no WHERE clause, so the forced re-seed below is what stops a device
    // supplying somebody else's id from planting a row in their namespace.
    /**
     * @param  int|string  $pk  The op-log primary key this row must be created under.
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return array<string, mixed>|null
     */
    private function buildCreatePayload(string $table, int|string $pk, array $fields, int $userId, string $now): ?array
    {
        // Child tables (rule_conditions, rule_actions) are scoped through
        // their parent and carry no user_id column at all; seeding one made
        // every insert fail with "table X has no column named user_id".
        $scoped = $this->ownership->hasUserIdColumn($table);

        // The op's own pk, not a fresh autoincrement. Without it every device
        // invented its own id for the same logical row: children referenced
        // parents that did not exist (FOREIGN KEY constraint failed), and a
        // replayed create duplicated instead of colliding with itself.
        $payload = $scoped ? ['id' => $pk, 'user_id' => $userId] : ['id' => $pk];

        foreach ($fields as $field => $fieldEntries) {
            try {
                $resolved = $this->projector->encodeColumnValue($this->projector->resolveStrategy($table, $field)->resolve($fieldEntries));

                // Read before the re-encryption, which is the last point the
                // value is still the day the peer wrote.
                if ($this->suppliedDates->refuses($table, $field, $resolved)) {
                    $this->quarantine->record($fieldEntries[0], QuarantineReason::ImpossibleDate, $now);

                    return null;
                }

                $resolved = $this->suppliedDates->normalise($table, $field, $resolved);

                $payload[$field] = $this->projector->reencryptForProjection($table, $field, $resolved, $userId);
            } catch (\Throwable) {
                $this->quarantine->record($fieldEntries[0], QuarantineReason::StrategyError, $now);

                return null;
            }
        }

        // A wire-supplied user_id is IGNORED, not compared: it is the origin
        // device's autoincrement, so rejecting a mismatch quarantined every
        // row a paired peer sent. The overwrite below is the stronger guard —
        // the scope comes from the session, never the wire.
        if ($scoped) {
            $payload['user_id'] = $userId;
        } else {
            unset($payload['user_id']);
        }

        // The same overwrite for the pk, and for the same reason. A create
        // that names `id` is ordinary -- a derived id repeats itself in the
        // payload -- but the row is the one the op addresses, so a field that
        // disagreed would land it where nothing else recorded it.
        $payload['id'] = $pk;

        return $this->creationTime->seed($table, $payload, $fields);
    }

    // Deletions are collected here rather than run here: a tombstone for a
    // parent and one for its own child are two rows of two tables, and the
    // order the merge happens to reach them in is not an order the foreign
    // keys accept. applyDeletions() runs them children-first, once.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     * @param  array<string, array<int|string, OpLogEntry>>  $tombstones
     * @param  array<string, array<int|string, OpLogEntry>>  $pendingDeletes
     */
    public function applyFieldMerges(
        array $candidatesByField,
        array $tombstones,
        ArrivingBatch $batch,
        array &$pendingDeletes,
        ReplayedRows $applied,
    ): void {
        /** @var list<array{table: string, pk: int|string, deviceId: string, values: array<string, mixed>}> $deferred */
        $deferred = [];

        $userId = $batch->userId;

        foreach ($candidatesByField as $table => $rows) {
            foreach ($rows as $pk => $fields) {
                $tomb = $tombstones[$table][$pk] ?? null;

                if ($tomb !== null && $this->tombstoneWins($table, $tomb, $fields)) {
                    $pendingDeletes[$table][$pk] = $tomb;

                    continue;
                }

                foreach ($this->aliases->splitFieldsByLocalRow($table, $pk, $fields, $userId) as $row) {
                    foreach ($row['fields'] as $field => $fieldEntries) {
                        $this->applyFieldMerge($table, $row['pk'], $field, $fieldEntries, $batch, $deferred);
                    }

                    $applied->rowUpdated($table, $row['pk'], $userId);
                }
            }
        }

        // Handed over in one call rather than per field: apply() walks
        // everything still outstanding each time it is asked, and a batch of
        // pair links would otherwise re-walk the carry once per link.
        $this->selfReferences->apply($deferred, $userId);
    }

    // Encode AND write inside one try: computing the value but running
    // ->update() outside the try let a non-scalar (OR-Set) throw during
    // binding and roll back the ENTIRE merge transaction — a single bad op is
    // quarantined instead.
    /**
     * @param  list<OpLogEntry>  $fieldEntries
     * @param  list<array{table: string, pk: int|string, deviceId: string, values: array<string, mixed>}>  $deferred
     */
    private function applyFieldMerge(string $table, int|string $pk, string $field, array $fieldEntries, ArrivingBatch $batch, array &$deferred): void
    {
        try {
            $columnValue = $this->projector->encodeColumnValue($this->projector->resolveStrategy($table, $field)->resolve($fieldEntries));

            // A Set rewrites the same column a create gated, so the day is
            // read on both paths or on neither.
            if ($this->suppliedDates->refuses($table, $field, $columnValue)) {
                $this->quarantine->record($fieldEntries[0], QuarantineReason::ImpossibleDate, $batch->now);

                return;
            }

            $columnValue = $this->suppliedDates->normalise($table, $field, $columnValue);

            $columnValue = $this->projector->reencryptForProjection($table, $field, $columnValue, $batch->userId);

            // A Set names ids the same way a create does. The id it addresses
            // was resolved by the caller, which groups a field's entries by
            // the row each device's copy belongs to; this rewrites the ids the
            // value itself names, before ownership reads them.
            $setDevice = $fieldEntries[0]->deviceId;
            $columnValue = $this->aliases->translate($table, $setDevice, [$field => $columnValue], $batch->userId)[$field] ?? $columnValue;

            // Both gates gate a create, and a Set rewrites the same column
            // afterwards: Set account_id to another member's and the row scopes
            // to you while reading their balance; raise a leg and the legs stop
            // adding up to the charge. Ownership answers first, as on create.
            $refusal = $this->ownership->referencesBelongToUser($table, [$field => $columnValue], $batch->userId, $pk)
                ? $this->splitOverfill->reasonToRefuseSet($table, $field, $pk, $columnValue, $batch)
                : QuarantineReason::CrossUser;

            if ($refusal !== null) {
                $this->quarantine->record($fieldEntries[0], $refusal, $batch->now);

                return;
            }

            // A transfer pair names its partner, and a Set carrying that link
            // routinely lands before the partner does: written here the foreign
            // key refuses it and the catch below records a strategy error
            // nothing retries. The deferral writes it when the partner arrives.
            if ($columnValue !== null && $this->selfReferences->isSelfReference($table, $field)) {
                $deferred[] = ['table' => $table, 'pk' => $pk, 'deviceId' => $setDevice, 'values' => [$field => $columnValue]];

                return;
            }

            $query = $this->db->connection()->table($table);

            // A self-scoped row is found by the session's own id, never by the
            // wire pk: two devices mint different autoincrements for the same
            // reader, so naming the origin's would update nothing.
            if (! $this->ownership->isSelfScoped($table)) {
                $query->where('id', $pk);
            }

            $this->ownership->scopeToUser($query, $table, $batch->userId)
                ->update([$field => $columnValue]);
        } catch (\Throwable) {
            $this->quarantine->record($fieldEntries[0], QuarantineReason::StrategyError, $batch->now);
        }
    }

    // Every leg amount this batch will write, under the id THIS device knows
    // the leg by. A rebalance announces its whole leg set, so a leg judged
    // against the stored siblings alone is judged on a set that is halfway
    // applied, and the order the legs arrive in decides which one is refused.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     * @return array<int|string, int>
     */
    private function splitAmountsArriving(array $candidatesByField, int $userId): array
    {
        $arriving = [];

        foreach ($candidatesByField[SplitOverfillGate::TABLE] ?? [] as $pk => $fields) {
            $entries = $fields[SplitOverfillGate::AMOUNT] ?? [];

            if ($entries === []) {
                continue;
            }

            try {
                $value = $this->projector->encodeColumnValue(
                    $this->projector->resolveStrategy(SplitOverfillGate::TABLE, SplitOverfillGate::AMOUNT)->resolve($entries),
                );
            } catch (\Throwable) {
                // applyFieldMerge() quarantines this same op as a strategy
                // error, so the value it could not resolve never lands and
                // must not be counted as though it will.
                continue;
            }

            if (is_numeric($value)) {
                $local = $this->aliases->resolvePk(SplitOverfillGate::TABLE, $entries[0]->deviceId, $pk, $userId);
                $arriving[$local] = (int) $value;
            }
        }

        return $arriving;
    }

    // One batch for the whole replay. applyCreates() ran with none at all, so
    // a leg created by a rebalance was judged against its siblings' STORED
    // amounts while the ops lowering them sat in the same frame, unapplied.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     */
    public function arrivingBatch(array $candidatesByField, int $userId, string $now): ArrivingBatch
    {
        return new ArrivingBatch($userId, $now, $this->splitAmountsArriving($candidatesByField, $userId));
    }

    // Tombstones for (table, pk) pairs that had NO field SET entries — pairs
    // that did carry a SET are already collected in applyFieldMerges.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     * @param  array<string, array<int|string, OpLogEntry>>  $tombstones
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $creates
     * @param  array<string, array<int|string, OpLogEntry>>  $pendingDeletes
     */
    public function collectBareTombstones(
        array $candidatesByField,
        array $tombstones,
        array $creates,
        array &$pendingDeletes,
    ): void {
        foreach ($tombstones as $table => $pks) {
            foreach ($pks as $pk => $tomb) {
                if (isset($candidatesByField[$table][$pk])) {
                    continue;
                }

                // A create that already beat this tombstone in applyCreates()
                // must not then be deleted by it. Rows used to arrive under a
                // fresh id, so delete-by-pk never matched the row just
                // created; preserving the op's pk makes it match.
                $create = $creates[$table][$pk] ?? null;

                if ($create !== null && ! $this->tombstoneWins($table, $tomb, $create)) {
                    continue;
                }

                $pendingDeletes[$table][$pk] = $tomb;
            }
        }
    }

    // One ordered pass over everything the merge decided to delete. The caller
    // supplies the table order; a row still refused after the whole pass is
    // retried once, because a cycle the topological order had to break can
    // leave a child behind that the first attempt had not reached yet.
    /**
     * @param  array<string, array<int|string, OpLogEntry>>  $pendingDeletes  Children-first table order.
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     */
    public function applyDeletions(
        array $pendingDeletes,
        int $userId,
        string $now,
        array &$pairCascades,
        ReplayedRows $applied,
    ): void {
        /** @var list<array{table: string, pk: int|string, tomb: OpLogEntry, documents: list<int>}> $refused */
        $refused = [];

        foreach ($pendingDeletes as $table => $pks) {
            foreach ($pks as $pk => $tomb) {
                // deleteRow() answers true for a self-scoped table without
                // removing anything, and every step below it is a no-op on
                // one, so skipping keeps the announcement to rows that went.
                if ($this->ownership->isSelfScoped($table)) {
                    continue;
                }

                // Delete-wins applies to the LOGICAL row, so a peer deleting
                // the id it minted deletes the twin this device minted.
                $pk = $this->aliases->resolvePk($table, $tomb->deviceId, $pk, $userId);

                $this->pairCascade->collect($table, $pk, $tomb, $userId, $pairCascades);

                // Asked while the row is still here: a tax tag names its
                // transaction in a column, and the delete below is the last
                // moment anything can read it.
                $composed = $applied->documentsOf($table, $pk, $userId);

                if ($this->deleteRow($table, $pk, $userId)) {
                    $applied->rowDeleted($table, $pk, $composed);

                    continue;
                }

                $refused[] = ['table' => $table, 'pk' => $pk, 'tomb' => $tomb, 'documents' => $composed];
            }
        }

        foreach ($refused as $blocked) {
            $this->clearDeviceLocalChildren($blocked['table'], $blocked['pk'], $userId);

            if ($this->deleteRow($blocked['table'], $blocked['pk'], $userId)) {
                $applied->rowDeleted($blocked['table'], $blocked['pk'], $blocked['documents']);

                continue;
            }

            $this->recordBlockedDelete($blocked['table'], $blocked['pk'], $blocked['tomb'], $now);
        }
    }

    // The local delete path announces every child that travels, and that
    // announcement is the compensating op. It cannot name the rows the
    // RECEIVER derived for itself — a nightly projection behind a synced
    // scenario — so the key refused a tombstone no later op could unblock.
    private function clearDeviceLocalChildren(string $table, int|string $pk, int $userId): void
    {
        try {
            $this->cascade->clearDeviceLocalChildren($table, $pk, $userId);
        } catch (QueryException $e) {
            $this->logger?->warning('OpLogEntryApplier: a device-local child of a deleted row could not be cleared.', [
                'table' => $table,
                'pk' => $pk,
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }

    // A row this device holds that no op deletes still references the one the
    // tombstone names, so the two devices now disagree about it. Swallowed,
    // that disagreement had nothing anywhere reporting it.
    private function recordBlockedDelete(string $table, int|string $pk, OpLogEntry $tomb, string $now): void
    {
        $this->quarantine->record($tomb, QuarantineReason::DeleteBlockedByReference, $now);

        $this->logger?->warning('OpLogEntryApplier: the database refused a replayed tombstone.', [
            'table' => $table,
            'pk' => $pk,
            'reason' => QuarantineReason::DeleteBlockedByReference->value,
        ]);
    }

    // False ONLY when the database refused the delete under a foreign key. A
    // self-scoped table is a deliberate no-op and counts as applied.
    private function deleteRow(string $table, int|string $pk, int $userId): bool
    {
        // The account row itself. A peer may edit the reader's settings; a
        // tombstone that removed the reader is not an op this side applies.
        if ($this->ownership->isSelfScoped($table)) {
            return true;
        }

        try {
            // Unscoped child tables have no user_id column at all, so a literal
            // where('user_id') raised "no such column" and aborted the replay.
            $this->ownership->scopeToUser(
                $this->db->connection()->table($table)->where('id', $pk),
                $table,
                $userId,
            )->delete();

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    // FTS5 freshness runs OUTSIDE the merge transaction (shadow-table writes
    // cannot join a transaction that also touches the base table). A FTS
    // hiccup never breaks merge determinism — each call is try/catch guarded
    // and routed to quarantine on failure.
}
