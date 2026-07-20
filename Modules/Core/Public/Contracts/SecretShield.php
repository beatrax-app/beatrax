<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

/**
 * Wraps a persisted secret in the OS keychain on bundles that have one,
 * layered UNDER whatever at-rest encryption already protects the column.
 *
 * Unlike `KeyCustodian` (which holds the unlocked data key for the life of a
 * session), a SecretShield protects a value that lives in the database
 * indefinitely — a biometric wrap blob, an OAuth token blob. It has no
 * handle / forget lifecycle: `protect()` returns bytes to persist and
 * `reveal()` turns them back into the plaintext, and that is all.
 *
 *   - Web / mobile (default `PassthroughSecretShield`, Core module):
 *     identity. The value is stored exactly as today (still AES-encrypted at
 *     rest by the caller's own column cast where one exists). Behaviour is
 *     unchanged.
 *
 *   - Desktop bundle (`SafeStorageSecretShield`, Desktop module):
 *     `protect()` runs the value through Electron `safeStorage`
 *     (OS keychain / DPAPI / Keychain Services) via the same
 *     `DesktopKeyCustodian` used for the session key, so the persisted blob
 *     is machine-bound ciphertext. Combined with the caller's existing
 *     APP_KEY column encryption this is defense-in-depth: a stolen SQLite
 *     file plus a stolen `.env` still cannot reveal the secret without the
 *     machine keychain.
 *
 * Degradation: when safeStorage is unavailable (headless CI, early-boot
 * race, or a value written before shielding was enabled), `reveal()` returns
 * its input unchanged so legacy / unshielded rows keep working — the caller
 * then decrypts them exactly as before. This mirrors `DesktopKeyCustodian`'s
 * own graceful fallback.
 */
interface SecretShield
{
    /**
     * Return the bytes to persist for $plaintext. Identity on the default
     * shield; machine-bound safeStorage ciphertext on the desktop bundle.
     */
    public function protect(string $plaintext): string;

    /**
     * Reverse {@see self::protect()}. Returns the input unchanged when the
     * keychain is unavailable or the value was never shielded.
     */
    public function reveal(string $shielded): string;
}
