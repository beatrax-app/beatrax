<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Contracts;

use Illuminate\Contracts\Session\Session;

// Whether a stored blind-index digest can be REPRODUCED from the plaintext
// beside it under a given key. Implemented outside Sync because only the
// ledger knows how each column's plaintext is normalised before it is hashed,
// and a probe that cannot re-derive can only measure shape.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
interface BlindIndexProvenance
{
    /**
     * @param  string  $keyHex  The blind-index key to re-derive under.
     */
    public function reproducesAStoredDigest(int $userId, string $keyHex, Session $session): bool;
}
