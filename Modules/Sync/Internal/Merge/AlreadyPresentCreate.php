<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Psr\Log\LoggerInterface;

// What it means when the database refuses a replayed create as already
// present: the row is here under a peer's own id, the row is here and this is
// the same create again (possibly its other half), or a DIFFERENT row wears the
// id and the arriving one is not here at all.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class AlreadyPresentCreate
{
    public function __construct(
        private PeerRowAliases $aliases,
        private CreateRowCollision $collisions,
        private OpLogQuarantine $quarantine,
        private SplitCreateTail $tail,
        private RehomedCreate $rehome,
        private ?LoggerInterface $logger = null,
    ) {}

    // The id the content is under here, or null when the create was refused
    // and recorded. An alias means it landed under the other id, a re-home
    // under one this device minted; both are answers, and the caller addresses
    // the row by what comes back rather than by the id the peer used.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    public function answer(string $table, array $payload, array $fields, string $now, string $deviceId, int|string $pk, int $userId): int|string|null
    {
        $this->aliases->remember($table, $deviceId, $pk, $payload, $userId);

        $local = $this->aliases->localFor($table, $deviceId, $pk, $userId);

        if ($local !== null) {
            // For the reason the branch below fills, and at the id this device
            // minted rather than the one the peer used. Returning here without
            // it left a re-homed row holding whatever its first copy happened
            // to carry, permanently: nothing comes back through this branch.
            $this->tail->fill($table, $local, $payload, $userId, SuppliedCreationTime::seededValueFor($fields));

            return $this->aliases->resolvePk($table, $deviceId, $pk, $userId);
        }

        if (! $this->collisions->contradicts($table, $pk, $payload, $userId, SuppliedCreationTime::seededValueFor($fields))) {
            // The same row, so this is the create arriving again — and a
            // transport that splits one row's ops across two frames makes the
            // second half look exactly like that. Returning here without it
            // dropped every column the first half did not carry.
            $this->tail->fill($table, $pk, $payload, $userId, SuppliedCreationTime::seededValueFor($fields));

            return $pk;
        }

        return $this->rehomeOrRefuse($table, $payload, $fields, $now, $deviceId, $pk, $userId);
    }

    // The stored row at that id is a different row, so the arriving one is not
    // here under any id. It is stored under a fresh one where a natural key
    // can find it again, and quarantined where none can.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private function rehomeOrRefuse(string $table, array $payload, array $fields, string $now, string $deviceId, int|string $pk, int $userId): ?int
    {
        $rehomed = $this->rehome->under($table, $payload, $deviceId, $pk, $userId);

        if ($rehomed !== null) {
            return $rehomed;
        }

        // The newest entry in the group, not the first: replay rehydrates the
        // row's whole history, so the first is this device's own create and
        // quarantining under it would name the reader's device as the sender
        // of a row it already holds.
        $arriving = self::latestOf($fields);

        if ($arriving !== null) {
            $this->quarantine->record($arriving, $this->refusal($table, $payload), $now);
            $deviceId = $arriving->deviceId;
        }

        $this->logger?->warning('OpLogEntryApplier: two devices minted one primary key.', [
            'table' => $table,
            'pk' => (string) $pk,
            'device_id' => $deviceId,
        ]);

        return null;
    }

    // Which of the two halves of a collision this is, asked of the finder that
    // decided: a table with no natural key can never take this row, so the
    // verdict is terminal and disclosed, while a re-home the database refused
    // is a hold a later pass takes again.
    /**
     * @param  array<string, mixed>  $payload
     */
    private function refusal(string $table, array $payload): QuarantineReason
    {
        return $this->aliases->naturalKeyIdentifies($table, $payload)
            ? QuarantineReason::PrimaryKeyCollision
            : QuarantineReason::UnplaceableCollision;
    }

    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private static function latestOf(array $fields): ?OpLogEntry
    {
        $latest = null;

        foreach ($fields as $entries) {
            foreach ($entries as $entry) {
                if ($latest === null || [$entry->hlcL, $entry->hlcC] > [$latest->hlcL, $latest->hlcC]) {
                    $latest = $entry;
                }
            }
        }

        return $latest;
    }
}
