<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Wraps libsodium's Argon2id password-hashing API for PIN storage, via
// libsodium (not PHP's password_hash()) so the sodium extension is the
// single dependency. INTERACTIVE limits are used because the PIN is
// verified in a web request, unlike BackupEncryptor's offline MODERATE tier.
final class PinHasher
{
    public function hash(string $pin): string
    {
        return sodium_crypto_pwhash_str(
            $pin,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
    }

    // Note the argument order: the hash comes first, matching libsodium's
    // sodium_crypto_pwhash_str_verify($hash, $password) signature.
    public function verify(string $pin, string $hash): bool
    {
        return sodium_crypto_pwhash_str_verify($hash, $pin);
    }
}
