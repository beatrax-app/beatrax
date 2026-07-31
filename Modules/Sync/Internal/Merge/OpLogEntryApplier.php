<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogEntryApplier
{
    private const string SYSTEM_FTS_DEVICE_ID = 'system-fts';

    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
        private OpLogValueProjector $projector,
        private OpLogQuarantine $quarantine,
        private ?SearchIndexWriterContract $searchWriter = null,
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
        foreach ($creates as $table => $rows) {
            foreach ($rows as $pk => $fields) {
                $tomb = $tombstones[$table][$pk] ?? null;

                if ($tomb !== null && $this->tombstoneWins($tomb, $fields)) {
                    continue;
                }

                if (! $this->createRowComplete($table, $fields, $now)) {
                    continue;
                }

                $payload = $this->buildCreatePayload($table, $fields, $userId, $now);

                if ($payload === null) {
                    continue;
                }

                $this->db->connection()->table($table)->insertOrIgnore($payload);
                $this->trackTransaction($table, $pk, $touchedTransactionIds);
            }
        }
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

    // A CreateRow needs every required column present; an incomplete set is
    // quarantined (synthesized from the first field's first entry) rather than
    // written as a partial row.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function createRowComplete(string $table, array $fields, string $now): bool
    {
        $missing = array_diff($this->rules->requiredCreateColumns($table), array_keys($fields));

        if ($missing === []) {
            return true;
        }

        $firstField = reset($fields);

        if ($firstField !== false && $firstField !== []) {
            $this->quarantine->record($firstField[0], QuarantineReason::IncompleteCreateRow, $now);
        }

        return false;
    }

    // A CreateRow op may legitimately carry a 'user_id' field, but the resolve
    // loop lets it overwrite the seeded authoritative user_id — insertOrIgnore
    // has no WHERE clause, so a device of user A supplying user_id = B must be
    // caught here and the whole row quarantined.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return array<string, mixed>|null
     */
    private function buildCreatePayload(string $table, array $fields, int $userId, string $now): ?array
    {
        $payload = ['user_id' => $userId];

        foreach ($fields as $field => $fieldEntries) {
            try {
                $resolved = $this->projector->encodeColumnValue($this->projector->resolveStrategy($table, $field)->resolve($fieldEntries));
                $payload[$field] = $this->projector->reencryptForProjection($table, $field, $resolved, $userId);
            } catch (\Throwable) {
                $this->quarantine->record($fieldEntries[0], QuarantineReason::StrategyError, $now);

                return null;
            }
        }

        if (isset($fields['user_id'])) {
            $suppliedUserIdValue = $payload['user_id'] ?? null;
            $suppliedUserId = is_numeric($suppliedUserIdValue) ? (int) $suppliedUserIdValue : null;

            if ($suppliedUserId !== $userId) {
                $this->quarantine->record($fields['user_id'][0], QuarantineReason::CrossUser, $now);

                return null;
            }
        }

        // Force the authoritative user_id even when the op-supplied value
        // matched — never trust a wire-derived value for the scope column.
        $payload['user_id'] = $userId;

        return $payload;
    }

    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     * @param  array<string, array<int|string, OpLogEntry>>  $tombstones
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     * @param  list<int>  $touchedTransactionIds
     * @param  list<int>  $tombstonedTransactionIds
     */
    public function applyFieldMerges(
        array $candidatesByField,
        array $tombstones,
        int $userId,
        string $now,
        array &$pairCascades,
        array &$touchedTransactionIds,
        array &$tombstonedTransactionIds,
    ): void {
        foreach ($candidatesByField as $table => $rows) {
            foreach ($rows as $pk => $fields) {
                $tomb = $tombstones[$table][$pk] ?? null;

                if ($tomb !== null && $this->tombstoneWins($tomb, $fields)) {
                    $this->collectPairCascade($table, $pk, $tomb, $userId, $pairCascades);
                    $this->deleteRow($table, $pk, $userId);
                    $this->trackTransaction($table, $pk, $tombstonedTransactionIds);

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
            $columnValue = $this->projector->reencryptForProjection($table, $field, $columnValue, $userId);

            $this->db->connection()
                ->table($table)
                ->where('id', $pk)
                ->where('user_id', $userId)
                ->update([$field => $columnValue]);
        } catch (\Throwable) {
            $this->quarantine->record($fieldEntries[0], QuarantineReason::StrategyError, $now);
        }
    }

    // Tombstones for (table, pk) pairs that had NO field SET entries — pairs
    // that did carry a SET are already deleted in applyFieldMerges.
    /**
     * @param  array<string, array<int|string, array<string, list<OpLogEntry>>>>  $candidatesByField
     * @param  array<string, array<int|string, OpLogEntry>>  $tombstones
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     * @param  list<int>  $tombstonedTransactionIds
     */
    public function applyBareTombstones(
        array $candidatesByField,
        array $tombstones,
        int $userId,
        array &$pairCascades,
        array &$tombstonedTransactionIds,
    ): void {
        foreach ($tombstones as $table => $pks) {
            foreach ($pks as $pk => $tomb) {
                if (isset($candidatesByField[$table][$pk])) {
                    continue;
                }

                $this->collectPairCascade($table, $pk, $tomb, $userId, $pairCascades);
                $this->deleteRow($table, $pk, $userId);
                $this->trackTransaction($table, $pk, $tombstonedTransactionIds);
            }
        }
    }

    // Before deleting a transfer transaction, capture its surviving pair
    // partner: ON DELETE SET NULL on pair_transaction_id leaves the partner
    // with a NULL link, reclassified AFTER commit. Non-transfer or unpaired
    // rows collect nothing.
    /**
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     */
    private function collectPairCascade(string $table, int|string $pk, OpLogEntry $tomb, int $userId, array &$pairCascades): void
    {
        if ($table !== 'transactions') {
            return;
        }

        $txRow = $this->db->connection()
            ->table('transactions')
            ->where('id', $pk)
            ->where('user_id', $userId)
            ->first();

        if ($txRow === null) {
            return;
        }

        $txType = is_string($txRow->type ?? null) ? $txRow->type : null;
        $pairId = is_numeric($txRow->pair_transaction_id ?? null)
            ? (int) $txRow->pair_transaction_id
            : null;

        if ($pairId !== null && in_array($txType, TransactionType::transferValues(), true)) {
            $pairCascades[] = [
                'partnerId' => $pairId,
                'deletedType' => $txType,
                'tombHlcL' => $tomb->hlcL,
                'tombHlcC' => $tomb->hlcC,
            ];
        }
    }

    private function deleteRow(string $table, int|string $pk, int $userId): void
    {
        $this->db->connection()
            ->table($table)
            ->where('id', $pk)
            ->where('user_id', $userId)
            ->delete();
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

    // After the merge transaction, orphaned transfer partners (pair link now
    // NULL) are reclassified, and each compensating op is persisted with a
    // monotonic HLC so a later rebuild reproduces the reclassification.
    /**
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     * @param  list<int>  $touchedTransactionIds
     */
    public function applyPairCascades(array $pairCascades, int $userId, string $now, array &$touchedTransactionIds): void
    {
        foreach ($pairCascades as $cascade) {
            $newType = match ($cascade['deletedType']) {
                'transfer_out' => 'income',
                'transfer_in' => 'expense',
                default => null,
            };

            if ($newType === null) {
                continue;
            }

            $partnerId = $cascade['partnerId'];

            $partnerRow = $this->db->connection()
                ->table('transactions')
                ->where('id', $partnerId)
                ->where('user_id', $userId)
                ->first();

            if ($partnerRow === null || $partnerRow->pair_transaction_id !== null) {
                continue;
            }

            $this->db->connection()
                ->table('transactions')
                ->where('id', $partnerId)
                ->where('user_id', $userId)
                ->update(['type' => $newType]);

            $this->persistCascadeOp($partnerId, $newType, $cascade, $userId, $now);
            $touchedTransactionIds[] = $partnerId;
        }
    }

    // The compensating op is stored with a REAL monotonic HLC (tombstone HLC,
    // counter + 1) so it sorts deterministically AFTER the tombstone — an HLC
    // of [0,0] would sort FIRST and a rebuild would revert the
    // reclassification.
    /**
     * @param  array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}  $cascade
     */
    private function persistCascadeOp(int $partnerId, string $newType, array $cascade, int $userId, string $now): void
    {
        $this->db->connection()->table('op_log_entries')->updateOrInsert(
            [
                'user_id' => $userId,
                'device_id' => OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID,
                'table_name' => 'transactions',
                'pk' => (string) $partnerId,
                'field' => 'type',
                'hlc_l' => $cascade['tombHlcL'],
                'hlc_c' => $cascade['tombHlcC'] + 1,
            ],
            [
                'op_type' => OpType::Set->value,
                'value' => json_encode($newType, JSON_THROW_ON_ERROR),
                'signature' => '',
                'recorded_at' => $now,
            ],
        );
    }

    // FTS5 freshness runs OUTSIDE the merge transaction (shadow-table writes
    // cannot join a transaction that also touches the base table). A FTS
    // hiccup never breaks merge determinism — each call is try/catch guarded
    // and routed to quarantine on failure.
    /**
     * @param  list<int>  $touchedTransactionIds
     * @param  list<int>  $tombstonedTransactionIds
     */
    public function refreshSearchIndex(
        array $touchedTransactionIds,
        array $tombstonedTransactionIds,
        int $userId,
        string $now,
    ): void {
        if ($this->searchWriter === null) {
            return;
        }

        foreach ($touchedTransactionIds as $txId) {
            try {
                $this->searchWriter->upsertForTransaction($txId, $userId);
            } catch (\Throwable) {
                $this->quarantineSearchError($txId, 'upsert', $userId, $now);
            }
        }

        foreach ($tombstonedTransactionIds as $txId) {
            try {
                $this->searchWriter->deleteForTransaction($txId, $userId);
            } catch (\Throwable) {
                $this->quarantineSearchError($txId, 'delete', $userId, $now);
            }
        }
    }

    /**
     * @param  string  $operation  'upsert'|'delete'
     */
    private function quarantineSearchError(int $transactionId, string $operation, int $userId, string $now): void
    {
        try {
            $this->db->connection()->table('op_log_quarantine')->insert([
                'user_id' => $userId,
                'table_name' => 'transactions',
                'pk' => (string) $transactionId,
                'device_id' => self::SYSTEM_FTS_DEVICE_ID,
                'reason' => QuarantineReason::StrategyError->value,
                'hlc_l' => 0,
                'hlc_c' => 0,
                'raw_value' => json_encode(['fts_operation' => $operation], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Never propagate — an FTS-freshness quarantine failure must
            // not break replay.
        }
    }
}
