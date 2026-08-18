<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Wraps libsodium's Argon2id password-hashing for PIN storage (not PHP's
// password_hash(), so the sodium extension is the single dependency).
// MODERATE limits match AppLockKdf: an attacker cracks whichever hash is
// weaker, so the PIN hash and the wrap key must be hardened together.
final class PinHasher
{
    public function hash(string $pin): string
    {
        return sodium_crypto_pwhash_str(
            $pin,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
        );
    }

    // Note the argument order: the hash comes first, matching libsodium's
    // sodium_crypto_pwhash_str_verify($hash, $password) signature.
    public function verify(string $pin, string $hash): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $pin);
    }
}
