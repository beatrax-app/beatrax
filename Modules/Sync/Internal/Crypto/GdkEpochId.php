<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// Mints the identifier a group-data key is known by. A local counter is
// unique only among the epochs ONE device holds, so two that rotated apart
// both reached "epoch 3" over different keys. Nothing reads these in order —
// every lookup is by exact id — so uniqueness was the only property needed.
final class GdkEpochId
{
    // 2^53 - 1: the largest integer that survives a JSON round-trip exactly.
    // Epoch ids travel in wrap payloads, so an id that arrived rounded would
    // name a key nobody holds.
    public const int MAX = 9_007_199_254_740_991;

    /**
     * @param  list<int>  $held  Epoch ids already in this keyring.
     */
    public static function mint(array $held): int
    {
        do {
            $candidate = random_int(1, self::MAX);
        } while (in_array($candidate, $held, true));

        return $candidate;
    }
}
