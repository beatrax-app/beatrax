<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Native\Desktop\Facades\System;

/**
 * Holds the already-unwrapped data key in the OS keychain (Electron
 * safeStorage) while the session is unlocked on the desktop build.
 *
 * ⚠ STATUS: NOT YET WIRED (WR-08 — D-20 custody is DEFERRED). No caller
 * invokes store()/read() anywhere: the Auth module cannot import this
 * Internal class (module-boundary rule), and no Public KeyCustodian
 * contract/bridge exists yet. Until Phase 14 wires a contract (Desktop
 * binding here, pass-through default on web), the unlocked key follows the
 * Auth module's session custody on ALL platforms — see the accepted-risk
 * note on LockStateManager (WR-07). The class is registered and tested so
 * the wiring is a contract + call-site change, not new crypto.
 *
 * Design contract (D-20):
 *   - On the desktop bundle: the unwrapped data key must NEVER sit in a
 *     plaintext disk or session slot. `store()` encrypts it via
 *     `System::encrypt()` (Electron safeStorage → OS keychain / DPAPI /
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
 * phpstan.neon carve-out: both `System::canEncrypt()` and
 * `System::encrypt()` / `System::decrypt()` calls are in the
 * `Native\Desktop\Facades\(Menu|Window|System|Notification|App)` allow-list
 * paths block in phpstan.neon (same entry as NativeBiometricUnlock and
 * OsThemeProbe).
 */
final class DesktopKeyCustodian
{
    public function __construct(
        private readonly ConfigRepository $config,
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

        $encrypted = System::encrypt($rawKey);

        // Guard against an empty result (e.g. safeStorage returned an
        // empty string on a partial failure). The facade declares string
        // as the return type so no null check is needed here.
        if ($encrypted === '') {
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
    public function read(string $blob): string
    {
        if (! $this->canEncrypt()) {
            return $blob;
        }

        $decrypted = System::decrypt($blob);

        if ($decrypted === '') {
            // Decrypt returned an empty string (e.g. key rotated, wrong
            // machine). Return the blob as-is; the Auth module will detect
            // an invalid key when it tries to use it. The facade declares
            // string, so no null check is needed here.
            return $blob;
        }

        return $decrypted;
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

        return System::canEncrypt();
    }
}
