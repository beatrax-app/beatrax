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
use Psr\Log\LoggerInterface;

final readonly class OpLogEntryApplier
{
    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
        private OpLogValueProjector $projector,
        private OpLogQuarantine $quarantine,
        private RowOwnership $ownership,
        private SuppliedDateGate $suppliedDates,
        private SelfReferenceDeferral $selfReferences,
        private TransferPairCascade $pairCascade,
        private PeerRowAliases $aliases,
        private AlreadyPresentCreate $alreadyPresent,
        private ?LoggerInterface $logger = null,
    ) {}

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
     * @param  list<int>  $touchedTransactionIds
     */
    public function applyCreates(
        array $creates,
        array $tombstones,
        int $userId,
        string $now,
        array &$touchedTransactionIds,
    ): void {
        /** @var list<array{table: string, pk: int|string, values: array<string, mixed>}> $deferred */
        $deferred = [];

        foreach ($creates as $table => $rows) {
            foreach ($rows as $pk => $fields) {
                $selfRefs = $this->applyCreatedRow(
                    $table,
                    $pk,
                    $fields,
                    $tombstones[$table][$pk] ?? null,
                    $userId,
                    $now,
                    $touchedTransactionIds,
                );

                if ($selfRefs !== []) {
                    $deferred[] = ['table' => $table, 'pk' => $pk, 'values' => $selfRefs];
                }
            }
        }

        $this->selfReferences->apply($deferred, $userId);
    }

    // Runs one created row through the gates and writes it, handing back the
    // self-referential columns it could not carry at insert time.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     * @param  list<int>  $touchedTransactionIds
     * @return array<string, mixed>
     */
    private function applyCreatedRow(
        string $table,
        int|string $pk,
        array $fields,
        ?OpLogEntry $tomb,
        int $userId,
        string $now,
        array &$touchedTransactionIds,
    ): array {
        $payload = $this->admissiblePayload($table, $pk, $fields, $tomb, $userId, $now);

        if ($payload === null) {
            return [];
        }

        // The ids this row NAMES, rewritten to the ones this device uses for
        // the same logical rows: a peer that seeded its own reference data
        // names it by an id only that device ever had.
        $first = reset($fields);
        $deviceId = $first !== false && $first !== [] ? $first[0]->deviceId : '';
        $payload = $this->aliases->translate($table, $deviceId, $payload, $userId);

        // A self-referential FK cannot be satisfied at insert time: transfer
        // pairs point at EACH OTHER, so whichever row lands first names a
        // partner that does not exist. Stripped here, set once both exist.
        $selfRefs = $this->selfReferences->extract($table, $payload);

        if (! $this->insertCreatedRow($table, $payload, $fields, $now, $deviceId, $pk, $userId)) {
            return [];
        }

        $this->trackTransaction($table, $pk, $touchedTransactionIds);

        return $selfRefs;
    }

    // buildCreatePayload() writes these from the op itself — the pk, and the
    // owner it re-seeds even when the op carries one — so a rule naming either
    // is satisfied before a field is read. Requiring them discarded rows the
    // applier could have written, and seven covered tables named user_id.
    private const array SEEDED_BY_APPLIER = ['id', 'user_id'];

    // The row to write, or null when a gate refused it: a tombstone that
    // outranks the create, a create with fields still missing, a payload that
    // could not be built, or a row belonging to someone else.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return array<string, mixed>|null
     */
    private function admissiblePayload(
        string $table,
        int|string $pk,
        array $fields,
        ?OpLogEntry $tomb,
        int $userId,
        string $now,
    ): ?array {
        $refused = ($tomb !== null && $this->tombstoneWins($tomb, $fields))
            || ! $this->createRowComplete($table, $fields, $now);

        if ($refused) {
            return null;
        }

        $payload = $this->buildCreatePayload($table, $pk, $fields, $userId, $now);

        if ($payload !== null && ! $this->ownershipAdmits($table, $payload, $userId, $pk)) {
            $firstField = reset($fields);

            if ($firstField !== false) {
                $this->quarantine->record($firstField[0], QuarantineReason::CrossUser, $now);
            }

            return null;
        }

        return $payload;
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

    // Mirrors applyFieldMerge(): one unusable op is isolated, not allowed to
    // roll back every op replayed with it. A plain insert, NOT insertOrIgnore:
    // OR IGNORE silences a NOT NULL violation as readily as a duplicate, so a
    // create split across two frames wrote no row and reported success.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function insertCreatedRow(string $table, array $payload, array $fields, string $now, string $deviceId, int|string $pk, int $userId): bool
    {
        try {
            $this->db->connection()->table($table)->insert($payload);

            return true;
        } catch (QueryException $e) {
            // By the pk it is the idempotent re-apply. By ANOTHER unique index
            // it is a second id for one row, and the peer's id has to keep
            // meaning something or every child naming it is orphaned.
            if (CreateRowInsertFailure::classify($e) === CreateRowInsertFailure::AlreadyPresent) {
                return $this->alreadyPresent->answer($table, $payload, $fields, $now, $deviceId, $pk, $userId);
            }

            return $this->recordRefusedInsert($table, $e, $fields, $now);
        }
    }

    // The row is here, but a row is not THE row. An alias means the peer's id
    // names a local twin and the content did land, under the other id. Without
    // one, a create contradicting what is stored is two devices' rows wearing
    // a single id, and answering true is how a phone's move went missing.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    // Every refusal the database itself raised. The already-present arm is
    // answered above, where the payload that identifies the twin is in hand.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function recordRefusedInsert(string $table, QueryException $e, array $fields, string $now): bool
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

        return false;
    }

    // Delete-wins: true when the tombstone HLC is >= the highest field HLC
    // (including an exact tie). Shared by the create-shadow check and the
    // field-merge delete path so both use one total-order comparison.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function tombstoneWins(OpLogEntry $tomb, array $fields): bool
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

        return HybridLogicalClock::compare(
            $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
            $max->hlcL, $max->hlcC, $max->deviceId,
        ) >= 0;
    }

    // A CreateRow needs every required column, minus the ones
    // buildCreatePayload() seeds itself: a table naming `id` as required asked
    // for a field the backfill never emits, so every row of it was discarded
    // as incomplete on arrival rather than written.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function createRowComplete(string $table, array $fields, string $now): bool
    {
        $required = array_diff($this->rules->requiredCreateColumns($table), self::SEEDED_BY_APPLIER);
        $missing = array_diff($required, array_keys($fields));

        if ($missing === []) {
            return true;
        }

        $firstField = reset($fields);

        if ($firstField !== false && $firstField !== []) {
            $this->quarantine->record($firstField[0], QuarantineReason::IncompleteCreateRow, $now);
        }

        return false;
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

        return $payload;
    }

    // Deletions are collected here rather than run here: a tombstone for a
    // parent and one for its own child are two rows of two tables, and the
    // order the merge happens to reach them in is not an order the foreign
    // keys accept. applyDeletions() runs them children-first, once.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     * @param  array<string, array<int|string, OpLogEntry>>  $tombstones
     * @param  array<string, array<int|string, OpLogEntry>>  $pendingDeletes
     * @param  list<int>  $touchedTransactionIds
     */
    public function applyFieldMerges(
        array $candidatesByField,
        array $tombstones,
        int $userId,
        string $now,
        array &$pendingDeletes,
        array &$touchedTransactionIds,
    ): void {
        foreach ($candidatesByField as $table => $rows) {
            foreach ($rows as $pk => $fields) {
                $tomb = $tombstones[$table][$pk] ?? null;

                if ($tomb !== null && $this->tombstoneWins($tomb, $fields)) {
                    $pendingDeletes[$table][$pk] = $tomb;

                    continue;
                }

                foreach ($fields as $field => $fieldEntries) {
                    $this->applyFieldMerge($table, $pk, $field, $fieldEntries, $userId, $now);
                }

                $this->trackTransaction($table, $pk, $touchedTransactionIds);
            }
        }
    }

    // Encode AND write inside one try: computing the value but running
    // ->update() outside the try let a non-scalar (OR-Set) throw during
    // binding and roll back the ENTIRE merge transaction — a single bad op is
    // quarantined instead.
    /**
     * @param  list<OpLogEntry>  $fieldEntries
     */
    private function applyFieldMerge(string $table, int|string $pk, string $field, array $fieldEntries, int $userId, string $now): void
    {
        try {
            $columnValue = $this->projector->encodeColumnValue($this->projector->resolveStrategy($table, $field)->resolve($fieldEntries));

            // A Set rewrites the same column a create gated, so the day is
            // read on both paths or on neither.
            if ($this->suppliedDates->refuses($table, $field, $columnValue)) {
                $this->quarantine->record($fieldEntries[0], QuarantineReason::ImpossibleDate, $now);

                return;
            }

            $columnValue = $this->projector->reencryptForProjection($table, $field, $columnValue, $userId);

            // A Set names ids the same way a create does, and addresses a row
            // by one. Both are rewritten before ownership reads them, so the
            // check runs against the id this device will actually write.
            $setDevice = $fieldEntries[0]->deviceId;
            $columnValue = $this->aliases->translate($table, $setDevice, [$field => $columnValue], $userId)[$field] ?? $columnValue;
            $pk = $this->aliases->resolvePk($table, $setDevice, $pk, $userId);

            // The create path gates the ids a row NAMES, but a Set rewrites
            // that same column afterwards: create a transaction against your
            // own account, then Set account_id to another member's, and the
            // row scopes to you while reading their balance.
            if (! $this->ownership->referencesBelongToUser($table, [$field => $columnValue], $userId, $pk)) {
                $this->quarantine->record($fieldEntries[0], QuarantineReason::CrossUser, $now);

                return;
            }

            $query = $this->db->connection()->table($table);

            // A self-scoped row is found by the session's own id, never by the
            // wire pk: two devices mint different autoincrements for the same
            // reader, so naming the origin's would update nothing.
            if (! $this->ownership->isSelfScoped($table)) {
                $query->where('id', $pk);
            }

            $this->ownership->scopeToUser($query, $table, $userId)
                ->update([$field => $columnValue]);
        } catch (\Throwable) {
            $this->quarantine->record($fieldEntries[0], QuarantineReason::StrategyError, $now);
        }
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

                if ($create !== null && ! $this->tombstoneWins($tomb, $create)) {
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
     * @param  list<int>  $tombstonedTransactionIds
     */
    public function applyDeletions(
        array $pendingDeletes,
        int $userId,
        string $now,
        array &$pairCascades,
        array &$tombstonedTransactionIds,
    ): void {
        /** @var list<array{table: string, pk: int|string, tomb: OpLogEntry}> $refused */
        $refused = [];

        foreach ($pendingDeletes as $table => $pks) {
            foreach ($pks as $pk => $tomb) {
                // Delete-wins applies to the LOGICAL row, so a peer deleting
                // the id it minted deletes the twin this device minted.
                $pk = $this->aliases->resolvePk($table, $tomb->deviceId, $pk, $userId);

                $this->pairCascade->collect($table, $pk, $tomb, $userId, $pairCascades);

                if ($this->deleteRow($table, $pk, $userId)) {
                    $this->trackTransaction($table, $pk, $tombstonedTransactionIds);

                    continue;
                }

                $refused[] = ['table' => $table, 'pk' => $pk, 'tomb' => $tomb];
            }
        }

        foreach ($refused as $blocked) {
            if ($this->deleteRow($blocked['table'], $blocked['pk'], $userId)) {
                $this->trackTransaction($blocked['table'], $blocked['pk'], $tombstonedTransactionIds);

                continue;
            }

            $this->recordBlockedDelete($blocked['table'], $blocked['pk'], $blocked['tomb'], $now);
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

    // FTS5 freshness tracking is confined to the base `transactions` table
    // with an integer pk; other tables never feed the search index.
    /**
     * @param  list<int>  $ids
     */
    private function trackTransaction(string $table, int|string $pk, array &$ids): void
    {
        if ($table === 'transactions' && is_int($pk)) {
            $ids[] = $pk;
        }
    }

    // FTS5 freshness runs OUTSIDE the merge transaction (shadow-table writes
    // cannot join a transaction that also touches the base table). A FTS
    // hiccup never breaks merge determinism — each call is try/catch guarded
    // and routed to quarantine on failure.
}
