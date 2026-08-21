<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Exceptions\SecureStorageException;
use Native\Mobile\Facades\SecureStorage;

/**
 * @see KeyCustodian
 */
class SecureStorageKeyCustodian implements KeyCustodian
{
    // Keychain/Keystore entry-name prefix; the current user id is
    // appended.
    private const SLOT_PREFIX = 'beatrax.session.data_key.';

    // Slot name => the key last read out of it. SensitiveColumnCodec resolves
    // the KEK once per decrypted VALUE, reaching release() before
    // GdkKeyringService can consult a memo keyed on a fingerprint OF that KEK
    // — so a 140-row page made 140 Keystore round trips through JNI.
    /** @var array<string, string> */
    private array $keyCache = [];

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

        // On device, a native set() failure must NOT fall back to holding the
        // raw key in-session: with SESSION_DRIVER=database that KEK would land
        // in the sessions table in plaintext. Fail closed so the unlock surface
        // re-runs the PIN path instead of degrading to a plaintext key.
        if (! $this->nativeSet($slot, base64_encode($rawKey))) {
            throw SecureStorageException::nativeSetFailed($slot);
        }

        $this->keyCache[$slot] = $rawKey;

        return $slot;
    }

    public function read(string $handle): ?string
    {
        if (! $this->runtimeAvailable() || ! str_starts_with($handle, self::SLOT_PREFIX)) {
            // Not on device, or the handle is a pass-through raw key from a
            // store() that ran while the runtime was unavailable -- return it
            // unchanged. An on-device set() failure no longer reaches here: it
            // throws instead, so a handle is never a failed-store raw key.
            return $handle;
        }

        if (isset($this->keyCache[$handle])) {
            return $this->keyCache[$handle];
        }

        $stored = $this->nativeGet($handle);
        if (! is_string($stored)) {
            // Entry missing / evicted — the key is unrecoverable. Return null
            // so the session falls back to a PIN unlock instead of releasing
            // the slot name as if it were the key.
            return null;
        }

        $decoded = base64_decode($stored, strict: true);

        // A failure is deliberately not cached: an unreadable entry must stay
        // retryable rather than become sticky for the life of the process.
        return $decoded === false ? null : $this->keyCache[$handle] = $decoded;
    }

    public function forget(string $handle): void
    {
        // Dropped first and unconditionally: this instance is a singleton and
        // the persistent mobile runtime keeps it alive between requests, so a
        // cached key that outlived the lock would outlive it for good.
        unset($this->keyCache[$handle]);

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
