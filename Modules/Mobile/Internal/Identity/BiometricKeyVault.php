<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Beatrax\BiometricVault\Facades\BiometricVault;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Cold-start biometric unlock vault (per `.docs/design/cold-start-biometric-unlock.md`).
 *
 * Stores a biometric-wrapped copy of the data key in an ENCLAVE-GATED entry
 * (the first-party `beatrax/mobile-biometric-vault` plugin — iOS
 * SecAccessControl(.biometryCurrentSet), Android Keystore
 * setUserAuthenticationRequired), so a cold start can recover the data key
 * after a fresh Face ID / Touch ID, with no PIN. The PIN stays the enrollment
 * root: you can only enroll while already unlocked (data key in hand).
 *
 * Blob crypto is delegated to the Auth Public {@see BiometricKeyBlobCodec}
 * (`BWS || wrapped-key`, the same primitive the desktop WebAuthn path uses) —
 * this class never wraps/unwraps itself and never touches `Modules\Auth\Internal`.
 * It only moves the blob in and out of the enclave and maps native outcomes.
 *
 * Guard + testability mirror {@see SecureStorageKeyCustodian}: the native
 * `Beatrax\BiometricVault\Facades\BiometricVault` facade lives only in
 * `mobile-app/vendor`, so every call is confined to a `class_exists()`-guarded
 * seam method (`vaultSet/vaultGet/vaultDelete`, overridable in tests) and the
 * whole class degrades to unavailable off-device.
 *
 * Platform asymmetry (spike finding): `recover()` returns a
 * {@see BiometricRecoverResult}; iOS yields `recovered` inline, Android yields
 * `pendingAsync` and the key arrives via the `BiometricVault.Recovered` event.
 */
class BiometricKeyVault
{
    /** Enclave entry-name prefix; the current user id is appended (per-user, like the custodian). */
    private const SLOT_PREFIX = 'beatrax.coldstart.datakey.';

    public function __construct(
        private readonly BiometricKeyBlobCodec $codec,
        private readonly CurrentUser $currentUser,
    ) {}

    /**
     * True on a build where the biometric-vault plugin is present and the
     * on-device mobile runtime signal is set. Safe to call anywhere.
     */
    public function isAvailable(): bool
    {
        return $this->runtimeAvailable();
    }

    /**
     * Enroll cold-start unlock: wrap the (currently-held) data key into a
     * biometric blob and store it in the enclave-gated entry. Must be called
     * while unlocked (the caller supplies the live data key). Returns false
     * off-device or on a native failure.
     */
    public function enroll(string $dataKey): bool
    {
        if (! $this->runtimeAvailable()) {
            return false;
        }

        $blob = $this->codec->wrap($dataKey);

        return $this->vaultSet($this->slot(), base64_encode($blob));
    }

    /**
     * Attempt a cold-start recovery. Presents the biometric prompt (iOS) or
     * dispatches it (Android). Never prompts when nothing is enrolled.
     */
    public function recover(string $reason = 'Unlock beatrax'): BiometricRecoverResult
    {
        if (! $this->runtimeAvailable()) {
            return BiometricRecoverResult::unavailable();
        }

        $outcome = $this->vaultGet($this->slot(), $reason);

        // Empty result = the native bridge failed to answer at all (facade
        // vanished after the availability check) — a failure, not "missing".
        if ($outcome === []) {
            return BiometricRecoverResult::failed();
        }
        if (($outcome['async'] ?? false) === true) {
            return BiometricRecoverResult::pendingAsync();
        }
        if (($outcome['canceled'] ?? false) === true) {
            return BiometricRecoverResult::canceled();
        }
        // Enrolled but authentication failed (wrong finger / lockout / native
        // error) — must NOT be mistaken for "nothing enrolled".
        if (($outcome['failed'] ?? false) === true) {
            return BiometricRecoverResult::failed();
        }

        $stored = $outcome['value'] ?? null;
        if (! is_string($stored) || $stored === '') {
            return BiometricRecoverResult::missing();
        }

        $blob = base64_decode($stored, strict: true);
        if ($blob === false) {
            return BiometricRecoverResult::missing();
        }

        $dataKey = $this->codec->unwrap($blob);

        return $dataKey === null
            ? BiometricRecoverResult::missing()
            : BiometricRecoverResult::recovered($dataKey);
    }

    /**
     * Complete an ASYNC (Android) recovery after the native BiometricPrompt has
     * already authenticated. The prompt callback decrypts the blob and stashes
     * it in a transient native slot, then emits a bare `BiometricVault.Recovered`
     * signal (NO key over the JS bridge); MobileLockScreen's event handler calls
     * this, which re-reads the stashed blob PHP-side and unwraps it. iOS never
     * uses this (recover() is synchronous). Returns MISSING when nothing is
     * pending / the slot is empty, failing closed.
     */
    public function completePendingRecover(): BiometricRecoverResult
    {
        if (! $this->runtimeAvailable()) {
            return BiometricRecoverResult::unavailable();
        }

        $stored = $this->pollRecovered();
        if ($stored === null) {
            return BiometricRecoverResult::missing();
        }

        $blob = base64_decode($stored, strict: true);
        if ($blob === false) {
            return BiometricRecoverResult::missing();
        }

        $dataKey = $this->codec->unwrap($blob);

        return $dataKey === null
            ? BiometricRecoverResult::missing()
            : BiometricRecoverResult::recovered($dataKey);
    }

    /**
     * Remove the enrolled entry (on disable, PIN reset re-enroll, or a Phase 14
     * rekey/revocation — see the design doc lifecycle table).
     */
    public function clear(): void
    {
        if (! $this->runtimeAvailable()) {
            return;
        }

        $this->vaultDelete($this->slot());
    }

    private function slot(): string
    {
        return self::SLOT_PREFIX.$this->currentUser->id();
    }

    // --- Native seam (overridable in tests; facade confined here) ------------

    protected function runtimeAvailable(): bool
    {
        if (! class_exists(BiometricVault::class)) {
            return false;
        }

        return getenv('NATIVEPHP_PLATFORM') !== false;
    }

    protected function vaultSet(string $key, string $value): bool
    {
        if (! class_exists(BiometricVault::class)) {
            return false;
        }

        return BiometricVault::set($key, $value) === true;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function vaultGet(string $key, string $reason): array
    {
        if (! class_exists(BiometricVault::class)) {
            return [];
        }

        $result = BiometricVault::get($key, $reason);

        return is_array($result) ? $result : [];
    }

    protected function vaultDelete(string $key): void
    {
        if (! class_exists(BiometricVault::class)) {
            return;
        }

        BiometricVault::delete($key);
    }

    /**
     * Read the base64 blob the async (Android) BiometricPrompt callback stashed
     * in the transient native slot after a successful decrypt, or null when
     * nothing is pending. NO biometric prompt happens here — the prompt already
     * ran; this just collects its result PHP-side so the key never crosses the
     * JS bridge. Overridable in tests; native `PollRecovered` is on-device.
     */
    protected function pollRecovered(): ?string
    {
        if (! class_exists(BiometricVault::class)) {
            return null;
        }

        $value = BiometricVault::pollRecovered();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
