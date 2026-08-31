<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\SecretShield;

// Makes a persisted secret machine-bound ciphertext, layered under the caller's
// own APP_KEY encryption.
final readonly class SafeStorageSecretShield implements SecretShield
{
    public function __construct(
        private DesktopKeyCustodian $custodian,
    ) {}

    public function protect(string $plaintext): string
    {
        return $this->custodian->store($plaintext);
    }

    public function reveal(string $shielded): string
    {
        // null means this machine cannot produce the plaintext: a row written
        // before shielding, a changed keychain, or a safeStorage that has not
        // come up yet. The stored bytes are all there is to hand back.
        return $this->custodian->read($shielded) ?? $shielded;
    }

    // Probed rather than declared: the custodian hands back the raw bytes
    // whenever Electron's safeStorage is unreachable, so a hardcoded true
    // would claim keychain protection on a desktop that has none.
    public function protectsAtRest(): bool
    {
        $probe = random_bytes(32);
        $protects = ! hash_equals($probe, $this->custodian->store($probe));
        sodium_memzero($probe);

        return $protects;
    }
}
