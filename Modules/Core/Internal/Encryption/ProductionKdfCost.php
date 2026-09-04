<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Encryption;

use Modules\Core\Public\Contracts\KdfCost;

// The only implementation that ships, and the only one CoreServiceProvider
// binds. libsodium's MODERATE pair is 256 MiB and three passes — roughly half
// a second per derivation — and both numbers are pinned by a known-answer
// vector, so lowering either fails the build rather than the reader.
/**
 * @link ../../../../.docs/architecture/argon2id-cost.md
 */
final class ProductionKdfCost implements KdfCost
{
    public function opslimit(): int
    {
        return SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
    }

    public function memlimit(): int
    {
        return SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
    }
}
