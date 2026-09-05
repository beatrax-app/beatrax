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

    // Two questions, because either alone answers yes on a desktop with no
    // keyring. The custody report is what rules out a Linux backend that
    // encrypts under a password published in Chromium's own source; the round
    // trip is what rules out a safeStorage that handed the bytes back unchanged.
    public function protectsAtRest(): bool
    {
        if (! $this->custodian->custody()->protectsAtRest()) {
            return false;
        }

        $probe = random_bytes(32);
        $protects = ! hash_equals($probe, $this->custodian->store($probe));
        sodium_memzero($probe);

        return $protects;
    }
}
