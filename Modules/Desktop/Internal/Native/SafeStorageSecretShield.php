<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\SecretShield;

// Runs a persisted secret through Electron safeStorage so the stored
// blob is machine-bound ciphertext, layered under the caller's own
// APP_KEY encryption. Delegates to DesktopKeyCustodian — the only
// class calling System::encrypt()/decrypt().
final class SafeStorageSecretShield implements SecretShield
{
    public function __construct(
        private readonly DesktopKeyCustodian $custodian,
    ) {}

    public function protect(string $plaintext): string
    {
        return $this->custodian->store($plaintext);
    }

    public function reveal(string $shielded): string
    {
        // null means the value never decrypts as safeStorage
        // ciphertext — a legacy row written before shielding, or a
        // keychain that changed — so the stored bytes ARE the
        // plaintext.
        return $this->custodian->read($shielded) ?? $shielded;
    }
}
