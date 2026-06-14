<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;

/**
 * G-Counter (grow-only counter) merge strategy.
 *
 * Used for fields like merchant_memories.occurrence_count where each device
 * independently increments its own count. The merge is convergent:
 *
 *   result = SUM of MAX(value) per device_id
 *
 * This is Pitfall-5-safe: re-replaying the same ops yields the same sum because
 * we take the per-device MAX (not add all ops from the same device). Each device
 * stores its running total as the op value, not an increment delta.
 *
 * Standard Kulkarni-Demirbas / Shapiro 2011 G-Counter semantics.
 */
final class GCounterStrategy implements MergeStrategyInterface
{
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

            // IN-04: a non-int decoded value (e.g. a float 3.0 or a string from a
            // corrupted op) must NOT be silently coerced to 0 — that would LOWER a
            // device's contribution and break convergence with no signal. Throw a
            // typed error so the replayer quarantines the op with a precise reason.
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
