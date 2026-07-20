<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Core\Public\Contracts\CurrentUser;
use Native\Mobile\Facades\SecureStorage;

/**
 * Mobile {@see KeyCustodian}: holds the unlocked data key in the device's
 * hardware-backed secure store (iOS Keychain / Android Keystore) via the
 * `nativephp/mobile-secure-storage` plugin, instead of leaving it in the
 * Laravel session payload at rest (the WR-07 accepted risk on other
 * platforms).
 *
 * This is the mobile twin of the Desktop module's DesktopKeyCustodian
 * and the direct analog of {@see BiometricUnlockBridge}: it is the ONLY
 * place in the codebase that calls `Native\Mobile\Facades\SecureStorage`,
 * and it never derives, wraps, or unwraps key material — wrap/unwrap stays
 * in the Auth module (AppLockKdf + AppLockKeyWrap). It only takes custody of
 * the already-unwrapped key while the session is unlocked.
 *
 * Custody model (differs from desktop): the raw key is written OUT of the
 * session into the Keychain under a PER-USER entry, and the entry's KEY NAME
 * is returned as the handle for the session to hold. So `forget()` MUST
 * delete the Keychain entry on lock — unlike the desktop custodian whose
 * handle is self-contained ciphertext.
 *
 * Per-user slot (`beatrax.session.data_key.{userId}`): the entry name is
 * scoped to the current user so that if two users are ever both unlocked on
 * the same device (multi-user-readiness constraint), one `store()` cannot
 * overwrite the other's key — each reads back its own slot. `store()` needs
 * the current user to name the slot; `read()`/`forget()` take the slot name
 * straight off the handle and need no user context (important: `forget()`
 * runs during logout, after the guard is already cleared).
 *
 * On iOS the entry is written with `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`
 * (the plugin default): readable only while the device is unlocked and never
 * synced off-device — matching this project's local-only privacy constraint.
 * If the Keychain entry is missing on read (device restore, OS eviction),
 * `read()` returns NULL — the caller's `AppLockKeyService::release()` then
 * returns null and the session falls back to a fresh PIN unlock, rather than
 * releasing the slot name as if it were the key. The PIN remains LOCK-04's
 * root.
 *
 * Guard for non-mobile / CI / web (mirrors BiometricUnlockBridge::isAvailable()):
 * {@see self::runtimeAvailable()} returns false unless BOTH the SecureStorage
 * facade is resolvable (the plugin lives only in `mobile-app/vendor`, not the
 * repo-root toolchain) AND the on-device runtime signal
 * `getenv('NATIVEPHP_PLATFORM')` is present. When false, store()/read()
 * degrade to pass-through exactly like NullKeyCustodian, so the repo-root
 * Pest/PHPStan run and any off-device resolution behave identically to web.
 *
 * phpstan.neon carve-out: `Native\Mobile\Facades\SecureStorage` is
 * allow-listed for `Modules/Mobile/Internal/Identity/*.php` alongside the
 * Biometrics/Scanner/Network facades. Every facade call is confined to the
 * `nativeSet()/nativeGet()/nativeDelete()` seam methods, each with its own
 * in-scope `class_exists()` guard (PHPStan narrowing is scope-local).
 *
 * NOTE: intentionally NOT `final` — the real `SecureStorage` facade is
 * unreachable from the repo-root test toolchain, so on-device tests subclass
 * this and override the `nativeSet/nativeGet/nativeDelete/runtimeAvailable`
 * seam methods to simulate the Keychain (mirrors BiometricUnlockBridge's own
 * test seam). The off-device pass-through path is directly testable without a
 * subclass.
 */
class SecureStorageKeyCustodian implements KeyCustodian
{
    /** Keychain / Keystore entry-name prefix; the current user id is appended. */
    private const SLOT_PREFIX = 'beatrax.session.data_key.';

    public function __construct(
        private readonly CurrentUser $currentUser,
    ) {}

    public function store(string $rawKey): string
    {
        if (! $this->runtimeAvailable()) {
            // Degrade to pass-through: the handle IS the raw key, exactly
            // like NullKeyCustodian (web / CI / off-device resolution).
            return $rawKey;
        }

        $slot = self::SLOT_PREFIX.$this->currentUser->id();

        // set() returns false on a native-side failure. Fall back to holding
        // the raw key in the session (degraded pass-through); read() then
        // takes the `! str_starts_with(handle, SLOT_PREFIX)` branch and
        // returns it unchanged.
        if (! $this->nativeSet($slot, base64_encode($rawKey))) {
            return $rawKey;
        }

        return $slot;
    }

    public function read(string $handle): ?string
    {
        if (! $this->runtimeAvailable() || ! str_starts_with($handle, self::SLOT_PREFIX)) {
            // Not on device, or the handle is a pass-through raw key from a
            // store() that ran while unavailable / failed — return unchanged.
            return $handle;
        }

        $stored = $this->nativeGet($handle);
        if (! is_string($stored)) {
            // Entry missing / evicted — the key is unrecoverable. Return null
            // so the session falls back to a PIN unlock instead of releasing
            // the slot name as if it were the key.
            return null;
        }

        $decoded = base64_decode($stored, strict: true);

        return $decoded === false ? null : $decoded;
    }

    public function forget(string $handle): void
    {
        if (! $this->runtimeAvailable() || ! str_starts_with($handle, self::SLOT_PREFIX)) {
            return;
        }

        $this->nativeDelete($handle);
    }

    /**
     * True only when the SecureStorage facade is resolvable AND the on-device
     * mobile runtime signal is present. Safe to call unconditionally — never
     * references the native facade when false. Overridable in tests.
     */
    protected function runtimeAvailable(): bool
    {
        if (! class_exists(SecureStorage::class)) {
            return false;
        }

        return getenv('NATIVEPHP_PLATFORM') !== false;
    }

    /**
     * Write to the native secure store. Returns false on failure or when the
     * facade is unresolvable. The in-method `class_exists()` re-check is
     * load-bearing: PHPStan narrowing is scope-local, so it must sit in the
     * same method as the `SecureStorage::` call. Overridable in tests.
     */
    protected function nativeSet(string $key, string $value): bool
    {
        if (! class_exists(SecureStorage::class)) {
            return false;
        }

        // The facade is statically unresolvable in the repo-root toolchain, so
        // narrow its `mixed` result: only a literal true is a success.
        return SecureStorage::set($key, $value) === true;
    }

    /**
     * Read from the native secure store. Returns the stored string or null
     * when absent / unresolvable. Overridable in tests.
     */
    protected function nativeGet(string $key): ?string
    {
        if (! class_exists(SecureStorage::class)) {
            return null;
        }

        // Narrow the facade's statically-unresolvable `mixed` result to ?string.
        $value = SecureStorage::get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Delete from the native secure store. Overridable in tests.
     */
    protected function nativeDelete(string $key): void
    {
        if (! class_exists(SecureStorage::class)) {
            return;
        }

        SecureStorage::delete($key);
    }
}
