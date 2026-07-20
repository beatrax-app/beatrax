<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Native\Desktop\System;

/**
 * Holds the already-unwrapped data key in the OS keychain (Electron
 * safeStorage) while the session is unlocked on the desktop build.
 *
 * STATUS: WIRED. Implements the Public {@see KeyCustodian} contract and is
 * bound to it by `DesktopServiceProvider` when `nativephp-internal.running`
 * is true, so `LockStateManager::unlock()/heldKey()/lock()` route the
 * unlocked key through this class on the desktop bundle. On web/CI the
 * pass-through `NullKeyCustodian` default applies instead (WR-07 session
 * custody, unchanged). This was the contract + call-site change the class
 * was always built to receive — not new crypto.
 *
 * Design contract (D-20):
 *   - On the desktop bundle: the unwrapped data key must NEVER sit in a
 *     plaintext disk or session slot. `store()` encrypts it via
 *     `$this->system->encrypt()` (Electron safeStorage → OS keychain / DPAPI /
 *     Keychain Services) and returns an opaque ciphertext blob. `read()`
 *     decrypts the blob back to the raw key bytes.
 *   - Fallback: when `System::canEncrypt()` returns false (headless CI,
 *     early-boot race before Electron initialises safeStorage), this class
 *     degrades gracefully by returning the value unchanged. The fallback
 *     path means the Auth module's encrypted-session custody applies instead
 *     — the web/CI key path is unchanged.
 *   - The web path never instantiates this class; it is only resolved by
 *     `DesktopServiceProvider` when `nativephp-internal.running` is true.
 *
 * Scope: this class protects the key AT REST while unlocked (between the
 * Auth module unwrapping it and a Phase 14 caller retrieving it). Wrap /
 * unwrap operations (Argon2id KDF + secretbox) stay in the Auth module;
 * `DesktopKeyCustodian` never touches `AppLockKeyWrap` or `AppLockKdf`.
 *
 * This class depends on the CONCRETE `Native\Desktop\System` (injected), not
 * the `System` facade, precisely because the facade's `@method static string
 * encrypt/decrypt` PHPDoc wrongly hides the real `?string` return — using the
 * concrete class lets PHPStan see the nullable return so the null guards below
 * are honoured rather than flagged as always-false. As a non-facade call it
 * also needs no `noFacadeRule` carve-out in phpstan.neon.
 */
final class DesktopKeyCustodian implements KeyCustodian
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly System $system,
    ) {}

    /**
     * Encrypt the raw key bytes using Electron safeStorage (OS keychain).
     *
     * Returns the opaque ciphertext string when safeStorage is available.
     * Falls back to returning the plain key unchanged when:
     *   - `System::canEncrypt()` is false (headless CI / pre-init race).
     *   - The bundle is not running.
     *
     * The caller (Auth module) stores the returned blob in the session;
     * `read()` reverses the operation.
     */
    public function store(string $rawKey): string
    {
        if (! $this->canEncrypt()) {
            // Graceful degradation: return the key as-is so the Auth
            // module's encrypted-session custody path applies unchanged.
            return $rawKey;
        }

        // base64 so a (binary) key or wrap-blob survives the JSON/HTTP
        // transport to the Electron safeStorage process intact; read()
        // reverses it. Without this, non-UTF-8 bytes corrupt in transit.
        $encoded = base64_encode($rawKey);
        $encrypted = $this->system->encrypt($encoded);
        sodium_memzero($encoded);

        // $this->system->encrypt() is really ?string (the concrete class returns
        // ->json('result'), null on a native-side failure); the facade's
        // `@method static string` annotation wrongly hides that. Treat
        // null/'' as failure and fall back to holding the raw key.
        if ($encrypted === null || $encrypted === '') {
            return $rawKey;
        }

        return $encrypted;
    }

    /**
     * Decrypt the blob previously returned by `store()`.
     *
     * Returns the raw key bytes. When safeStorage is unavailable (fallback
     * path), the blob IS the raw key, so it is returned unchanged.
     */
    public function read(string $blob): ?string
    {
        if (! $this->canEncrypt()) {
            // Fallback path: the blob IS the raw key (store() returned it
            // unchanged when safeStorage was unavailable), so hand it back.
            return $blob;
        }

        // decrypt() is really ?string and returns null on any safeStorage
        // failure (Electron replies HTTP 400 and the NativePHP client does
        // not ->throw(), so ->json('result') is null). A null/'' result means
        // this ciphertext no longer decrypts on this machine (rotated master
        // key, wrong host) OR the value was never ciphertext — the key is
        // unrecoverable. Return null so KeyCustodian callers fall back to a
        // PIN unlock; the SecretShield wrapper turns null back into the input
        // (legacy pass-through) via its own `?? $shielded`.
        $decrypted = $this->system->decrypt($blob);
        if ($decrypted === null || $decrypted === '') {
            return null;
        }

        // Reverse store()'s base64. A decode failure means the plaintext was
        // not one we wrote (legacy/foreign) — also unrecoverable → null.
        $decoded = base64_decode($decrypted, strict: true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * No external state to release: the handle returned by store() is the
     * self-contained safeStorage ciphertext, held only in the session, which
     * LockStateManager::lock() forgets directly. safeStorage keeps no
     * per-key entry to delete here (unlike the mobile Keychain custodian).
     */
    public function forget(string $handle): void
    {
        // Intentionally empty — the ciphertext handle carries no backing
        // keychain entry of its own; forgetting the session copy is enough.
    }

    /**
     * Returns true when the NativePHP bundle is running AND Electron's
     * safeStorage is available (System::canEncrypt()).
     */
    private function canEncrypt(): bool
    {
        if ($this->config->get('nativephp-internal.running') !== true) {
            return false;
        }

        return $this->system->canEncrypt();
    }
}
