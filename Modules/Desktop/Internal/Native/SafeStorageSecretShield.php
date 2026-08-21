<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\SecretShield;

// Makes a persisted secret machine-bound ciphertext, layered under the caller's
// own APP_KEY encryption.
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
        // null means the value never was safeStorage ciphertext — a row written
        // before shielding, or a changed keychain — so the stored bytes are it.
        return $this->custodian->read($shielded) ?? $shielded;
    }
}
