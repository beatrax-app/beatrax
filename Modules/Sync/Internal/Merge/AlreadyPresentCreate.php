<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Psr\Log\LoggerInterface;

// What it means when the database refuses a replayed create as already
// present. Three different things arrive here: the row is here under a peer's
// own id, the row is here and this is the same create again (possibly its
// other half), or the row is here and it is a DIFFERENT row wearing the same id.
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
        private ?LoggerInterface $logger = null,
    ) {}

    // True when the create is answered and replay carries on, false when it was
    // refused and recorded. An alias means the content landed under the other
    // id; only a payload that contradicts the stored row is a real loss.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    public function answer(string $table, array $payload, array $fields, string $now, string $deviceId, int|string $pk, int $userId): bool
    {
        $this->aliases->remember($table, $deviceId, $pk, $payload, $userId);

        if ($this->aliases->localFor($table, $deviceId, $pk, $userId) !== null) {
            return true;
        }

        if (! $this->collisions->contradicts($table, $pk, $payload, SuppliedCreationTime::seededValueFor($fields))) {
            // The same row, so this is the create arriving again — and a
            // transport that splits one row's ops across two frames makes the
            // second half look exactly like that. Returning here without it
            // dropped every column the first half did not carry.
            $this->tail->fill($table, $pk, $payload, $userId);

            return true;
        }

        // The newest entry in the group, not the first: replay rehydrates the
        // row's whole history, so the first is this device's own create and
        // quarantining under it would name the reader's device as the sender
        // of a row it already holds.
        $arriving = self::latestOf($fields);

        if ($arriving !== null) {
            $this->quarantine->record($arriving, QuarantineReason::PrimaryKeyCollision, $now);
            $deviceId = $arriving->deviceId;
        }

        $this->logger?->warning('OpLogEntryApplier: two devices minted one primary key.', [
            'table' => $table,
            'pk' => (string) $pk,
            'device_id' => $deviceId,
        ]);

        return false;
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
