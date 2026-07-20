<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Clock\HybridLogicalClock;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogReplayer
{
    /**
     * @param  DatabaseManager  $db  Raw DB access (bypasses Eloquent model events).
     * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
     */
    public function __construct(
        private DatabaseManager $db,
        private array $deviceKeys = [],
    ) {}

    // Sorts a merged set of OpLogEntry objects from all devices by HLC total
    // order, then applies the winning value per (table, pk, field) to the
    // real SQLite schema within a single DB transaction. Every write
    // includes WHERE user_id = $userId (see @link for the full algorithm).
    /**
     * @param  list<OpLogEntry>  $entries  Entries from all devices (any order).
     * @param  int  $userId  Scope all DB writes to this user.
     */
    public function replay(array $entries, int $userId): void
    {
        $signer = new DeviceKeySigner;
        $deviceKeys = $this->deviceKeys;

        // Filter to the scoped userId (defense in depth) and verify each
        // entry's Ed25519 signature. Entries with no device key on record,
        // or a failing signature, are skipped.
        $verified = [];

        foreach ($entries as $entry) {
            if ($entry->userId !== $userId) {
                continue;
            }

            $pubKeyHex = $deviceKeys[$entry->deviceId] ?? null;

            if ($pubKeyHex === null) {
                continue;
            }

            $pubKeyBin = sodium_hex2bin($pubKeyHex);

            if (! $signer->verify($entry->signingPayload(), $entry->signature, $pubKeyBin)) {
                continue;
            }

            $verified[] = $entry;
        }

        // Sort by HLC total order [l, c, device_id]. Walking sorted order
        // means a later index always has a higher or equal HLC, so the
        // last assignment to $resolved[table][pk][field] is the winner.
        $sorted = $verified;
        usort(
            $sorted,
            fn (OpLogEntry $a, OpLogEntry $b): int => HybridLogicalClock::compare(
                $a->hlcL, $a->hlcC, $a->deviceId,
                $b->hlcL, $b->hlcC, $b->deviceId,
            ),
        );

        // Single pass — build resolved-value map + tombstone map. Keyed as
        // resolved[table][pk][field] => winning SET entry;
        // tombstones[table][pk] => winning DELETE_TOMBSTONE entry;
        // creates[table][pk][field] => CREATE_ROW field entry.

        /** @var array<string, array<int|string, array<string, OpLogEntry>>> $resolved */
        $resolved = [];

        /** @var array<string, array<int|string, OpLogEntry>> $tombstones */
        $tombstones = [];

        /** @var array<string, array<int|string, array<string, OpLogEntry>>> $creates */
        $creates = [];

        foreach ($sorted as $entry) {
            $pk = $entry->pk;

            if ($entry->opType === OpType::DeleteTombstone) {
                $tombstones[$entry->table][$pk] = $entry;
            } elseif ($entry->opType === OpType::CreateRow) {
                $creates[$entry->table][$pk][$entry->field] = $entry;
            } else {
                $resolved[$entry->table][$pk][$entry->field] = $entry;
            }
        }

        $this->db->connection()->transaction(
            function () use ($resolved, $tombstones, $creates, $userId): void {
                // CREATE_ROW path: assemble all field ops for each (table, pk)
                // into a single insertOrIgnore.
                foreach ($creates as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
                            $maxCreate = null;

                            foreach ($fields as $fieldEntry) {
                                if ($maxCreate === null || HybridLogicalClock::compare(
                                    $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                                    $maxCreate->hlcL, $maxCreate->hlcC, $maxCreate->deviceId,
                                ) > 0) {
                                    $maxCreate = $fieldEntry;
                                }
                            }

                            if ($maxCreate !== null && HybridLogicalClock::compare(
                                $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
                                $maxCreate->hlcL, $maxCreate->hlcC, $maxCreate->deviceId,
                            ) >= 0) {
                                continue;
                            }
                        }

                        $payload = ['user_id' => $userId];

                        foreach ($fields as $field => $entry) {
                            $payload[$field] = $entry->value !== null
                                ? $this->decodeValue($entry->value)
                                : null;
                        }

                        $this->db->connection()
                            ->table($table)
                            ->insertOrIgnore($payload);
                    }
                }

                foreach ($resolved as $table => $rows) {
                    foreach ($rows as $pk => $fields) {
                        $tomb = $tombstones[$table][$pk] ?? null;

                        if ($tomb !== null) {
                            $maxFieldEntry = null;

                            foreach ($fields as $fieldEntry) {
                                if ($maxFieldEntry === null || HybridLogicalClock::compare(
                                    $fieldEntry->hlcL, $fieldEntry->hlcC, $fieldEntry->deviceId,
                                    $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                                ) > 0) {
                                    $maxFieldEntry = $fieldEntry;
                                }
                            }

                            // Delete-wins: tombstone HLC >= max field HLC
                            // (including equal tie) — resurrecting a deleted
                            // row is worse UX than losing a concurrent edit
                            // when HLC ordering is ambiguous.
                            if ($maxFieldEntry !== null && HybridLogicalClock::compare(
                                $tomb->hlcL, $tomb->hlcC, $tomb->deviceId,
                                $maxFieldEntry->hlcL, $maxFieldEntry->hlcC, $maxFieldEntry->deviceId,
                            ) >= 0) {
                                $this->db->connection()
                                    ->table($table)
                                    ->where('id', $pk)
                                    ->where('user_id', $userId)
                                    ->delete();

                                continue;
                            }
                        }

                        foreach ($fields as $field => $entry) {
                            $decodedValue = $entry->value !== null
                                ? $this->decodeValue($entry->value)
                                : null;

                            $this->db->connection()
                                ->table($table)
                                ->where('id', $pk)
                                ->where('user_id', $userId)
                                ->update([$field => $decodedValue]);
                        }
                    }
                }

                // Tombstones for (table, pk) pairs with no SET entries in
                // this batch (already-handled pairs are skipped).
                foreach ($tombstones as $table => $pks) {
                    foreach ($pks as $pk => $tomb) {
                        if (isset($resolved[$table][$pk])) {
                            continue;
                        }

                        $this->db->connection()
                            ->table($table)
                            ->where('id', $pk)
                            ->where('user_id', $userId)
                            ->delete();
                    }
                }
            },
        );
    }

    // json_decode handles numeric strings correctly (returns int); for
    // non-JSON strings it returns null, so this falls back to the raw
    // string (e.g. "42" -> int 42, but "edited" -> the raw string "edited").
    private function decodeValue(string $value): mixed
    {
        $decoded = json_decode($value, true);

        if ($decoded === null && $value !== 'null') {
            return $value;
        }

        return $decoded;
    }
}
