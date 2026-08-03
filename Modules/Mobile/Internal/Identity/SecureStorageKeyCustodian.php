<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Native\Mobile\Facades\SecureStorage;

/**
 * @see KeyCustodian
 * @link ../../../../.docs/features/mobile/architecture.md
 */
class SecureStorageKeyCustodian implements KeyCustodian
{
    // Keychain/Keystore entry-name prefix; the current user id is
    // appended.
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

    // Safe to call unconditionally - never references the native facade
    // when false. Overridable in tests.
    protected function runtimeAvailable(): bool
    {
        if (! class_exists(SecureStorage::class)) {
            return false;
        }

        return UserDataPathService::isMobileRuntime();
    }

    // Returns false on failure or when the facade is unresolvable. The
    // in-method class_exists() re-check is load-bearing: PHPStan
    // narrowing is scope-local, so it must sit in the same method as the
    // SecureStorage:: call. Overridable in tests.
    protected function nativeSet(string $key, string $value): bool
    {
        if (! class_exists(SecureStorage::class)) {
            return false;
        }

        // The facade is statically unresolvable in the repo-root toolchain, so
        // narrow its `mixed` result: only a literal true is a success.
        return SecureStorage::set($key, $value) === true;
    }

    // Returns the stored string or null when absent/unresolvable.
    // Overridable in tests.
    protected function nativeGet(string $key): ?string
    {
        if (! class_exists(SecureStorage::class)) {
            return null;
        }

        $value = SecureStorage::get($key);

        return is_string($value) ? $value : null;
    }

    protected function nativeDelete(string $key): void
    {
        if (! class_exists(SecureStorage::class)) {
            return;
        }

        SecureStorage::delete($key);
    }
}
