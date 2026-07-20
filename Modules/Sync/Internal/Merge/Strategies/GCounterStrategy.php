<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class GCounterStrategy implements MergeStrategyInterface
{
    // Used for fields like merchant_memories.occurrence_count where each
    // device independently increments its own count: result = SUM of
    // MAX(value) per device_id. Re-replaying the same ops yields the same
    // sum because each device stores its running total, not a delta.
    /**
     * @param  list<OpLogEntry>  $candidateEntries  HLC-sorted ascending.
     * @return int The merged count: sum of per-device maxima.
     */
    public function resolve(array $candidateEntries): mixed
    {
        /** @var array<string, int> $perDeviceMax */
        $perDeviceMax = [];

        foreach ($candidateEntries as $entry) {
            if ($entry->value === null) {
                continue;
            }

            $decoded = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);

            // A non-int decoded value (e.g. a float or a string from a corrupted
            // op) must NOT be silently coerced to 0 — that would LOWER a device's
            // contribution and break convergence with no signal.
            if (! is_int($decoded)) {
                throw new \UnexpectedValueException('Malformed G-Counter value: expected an integer total.');
            }

            $count = $decoded;

            if (! isset($perDeviceMax[$entry->deviceId]) || $count > $perDeviceMax[$entry->deviceId]) {
                $perDeviceMax[$entry->deviceId] = $count;
            }
        }

        return array_sum($perDeviceMax);
    }
}
