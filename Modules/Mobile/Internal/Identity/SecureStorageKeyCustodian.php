<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Enums\KeyCustody;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Exceptions\SecureStorageException;
use Native\Mobile\Facades\SecureStorage;
use Psr\Log\LoggerInterface;

/**
 * @see KeyCustodian
 */
class SecureStorageKeyCustodian implements KeyCustodian
{
    // Keychain/Keystore entry-name prefix; the current user id is
    // appended.
    private const string SLOT_PREFIX = 'beatrax.session.data_key.';

    // Written over a slot the store would not delete. The colon is outside the
    // base64 alphabet, so readThrough() decodes it to false and answers null —
    // and on a device it is greppable as what it is.
    private const string UNREADABLE_MARKER = 'beatrax:key-cleared';

    // Slot name => the key last read out of it. SensitiveColumnCodec resolves
    // the KEK once per decrypted VALUE, reaching release() before
    // GdkKeyringService can consult a memo keyed on a fingerprint OF that KEK
    // — so a 140-row page made 140 Keystore round trips through JNI.
    /** @var array<string, string> */
    private array $keyCache = [];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly LoggerInterface $log,
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

        return $this->keyCache[$handle] ?? $this->readThrough($handle);
    }

    private function readThrough(string $handle): ?string
    {
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

        if ($this->nativeDelete($handle)) {
            return;
        }

        $this->maskSlotThatWouldNotDelete($handle);
    }

    // A refused delete leaves the raw data key in the Keychain while the lock
    // screen says the app is locked, and the session has just dropped the only
    // handle naming it. Nothing would ever come back for it.

    // Overwriting with bytes that will not decode is the second way to make the
    // key unrecoverable: read() then answers null and the next unlock takes the
    // PIN path, which is where a custodian that cannot produce a key belongs.
    private function maskSlotThatWouldNotDelete(string $handle): void
    {
        if ($this->nativeGet($handle) === null) {
            // Refused over an entry that was not there: no key survives it.
            return;
        }

        if ($this->nativeSet($handle, self::UNREADABLE_MARKER)
            && $this->nativeGet($handle) === self::UNREADABLE_MARKER) {
            return;
        }

        $this->log->warning('SecureStorageKeyCustodian: the platform store would neither delete nor overwrite the session data key, so the unlocked key outlives the lock.', [
            'slot' => $handle,
        ]);
    }

    // A store that answers on a phone is a real one: the iOS entry is
    // kSecAttrAccessibleWhenUnlockedThisDeviceOnly and the Android one an
    // EncryptedSharedPreferences value under a Keystore master key. Neither
    // platform has the keyring-less mode a Linux desktop has to be asked about.
    public function custody(): KeyCustody
    {
        return $this->runtimeAvailable() ? KeyCustody::OperatingSystem : KeyCustody::Session;
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

    // Only a literal true is a success, as with set(): the native side answers
    // false both for a refusal and for an entry that was not there, and this
    // seam cannot tell them apart. Overridable in tests.
    protected function nativeDelete(string $key): bool
    {
        if (! class_exists(SecureStorage::class)) {
            return false;
        }

        return SecureStorage::delete($key) === true;
    }
}
