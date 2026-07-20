<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\SecretShield;

/**
 * Desktop {@see SecretShield}: runs a persisted secret through Electron
 * `safeStorage` (OS keychain / DPAPI / Keychain Services) so the stored blob
 * is machine-bound ciphertext, layered under the caller's own APP_KEY column
 * encryption.
 *
 * It owns no crypto of its own — it delegates to {@see DesktopKeyCustodian},
 * the single class in the desktop module that calls `System::encrypt()` /
 * `System::decrypt()`. `protect()`/`reveal()` map onto the custodian's
 * `store()`/`read()`, which already implement the graceful fallback (return
 * the value unchanged when safeStorage is unavailable), so a value written
 * before shielding was enabled, or read on a headless boot, still reveals to
 * its original bytes. Keeping the facade call in one place means this class
 * needs no additional `phpstan.neon` native-facade carve-out.
 *
 * Bound to the SecretShield contract by `DesktopServiceProvider` only when
 * `nativephp-internal.running` is true; web / mobile keep the pass-through
 * default.
 */
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
        // custodian->read() returns null when the value does not decrypt as
        // safeStorage ciphertext — for a persisted SECRET that means it was
        // never shielded (a legacy row written before shielding, or on a
        // machine whose keychain changed), so the stored bytes ARE the
        // plaintext: return them unchanged. This is the crucial difference
        // from the session-key path, where the same null means "key gone →
        // force a PIN unlock". Same primitive, opposite failure semantics.
        return $this->custodian->read($shielded) ?? $shielded;
    }
}
