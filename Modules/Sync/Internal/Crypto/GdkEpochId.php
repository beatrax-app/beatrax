<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// Mints the identifier a group-data key is known by.
//
// These used to be a local counter — the first epoch was 1 and a rotation took
// max(held) + 1 — so two devices that minted or rotated without having heard
// from each other both arrived at the same number holding different keys. A
// phone that set itself up standalone and then paired wrote every op under its
// own "epoch 3", and the desktop, holding a different key by that name, threw
// away everything the phone ever wrote as gdk_decrypt_failed. Nothing surfaced
// it: the phone said "Syncing…" and the desktop simply never changed.
//
// Nothing reads these in order. Every lookup is by exact id — keyFor(), the
// associated data, the wrap signature — so an id only has to be unique, and a
// random one is unique without anyone having to agree on a sequence.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
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
