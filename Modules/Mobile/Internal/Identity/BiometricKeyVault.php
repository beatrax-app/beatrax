<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Beatrax\BiometricVault\Facades\BiometricVault;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
class BiometricKeyVault
{
    // Enclave entry-name prefix; the current user id is appended
    // (per-user, like the custodian).
    private const SLOT_PREFIX = 'beatrax.coldstart.datakey.';

    public function __construct(
        private readonly BiometricKeyBlobCodec $codec,
        private readonly CurrentUser $currentUser,
    ) {}

    public function isAvailable(): bool
    {
        return $this->runtimeAvailable();
    }

    // Wraps the (currently-held) data key into a biometric blob and
    // stores it in the enclave-gated entry. Must be called while unlocked.
    // Returns false off-device or on a native failure.
    public function enroll(string $dataKey): bool
    {
        if (! $this->runtimeAvailable()) {
            return false;
        }

        $blob = $this->codec->wrap($dataKey);

        return $this->vaultSet($this->slot(), base64_encode($blob));
    }

    // Presents the biometric prompt (iOS) or dispatches it (Android).
    // Never prompts when nothing is enrolled.
    public function recover(string $reason = 'Unlock Beatrax'): BiometricRecoverResult
    {
        if (! $this->runtimeAvailable()) {
            return BiometricRecoverResult::unavailable();
        }

        $outcome = $this->vaultGet($this->slot(), $reason);

        // Empty means the native bridge never answered — the facade vanished
        // after the availability check — which is a failure, not "missing".
        // Same for the failed flag: enrolled but not authenticated must never
        // be reported as nothing enrolled.
        $refusal = match (true) {
            $outcome === [] => BiometricRecoverResult::failed(),
            ($outcome['async'] ?? false) === true => BiometricRecoverResult::pendingAsync(),
            ($outcome['canceled'] ?? false) === true => BiometricRecoverResult::canceled(),
            ($outcome['failed'] ?? false) === true => BiometricRecoverResult::failed(),
            default => null,
        };

        return $refusal ?? $this->recoveredFrom($outcome['value'] ?? null);
    }

    // Completes an async (Android) recovery after the native
    // BiometricPrompt has already authenticated and stashed the
    // decrypted blob in a transient native slot (no key over the JS
    // bridge). iOS never uses this. Returns MISSING when nothing pending.
    public function completePendingRecover(): BiometricRecoverResult
    {
        if (! $this->runtimeAvailable()) {
            return BiometricRecoverResult::unavailable();
        }

        return $this->recoveredFrom($this->pollRecovered());
    }

    // Turns a stored wrapped blob into a result. Both recovery paths decoded
    // and unwrapped identically, in longhand. Every failure here is MISSING,
    // not FAILED: an absent or unusable blob means nothing is enrolled to
    // authenticate against, which is not the same as being refused.
    private function recoveredFrom(mixed $stored): BiometricRecoverResult
    {
        if (! is_string($stored) || $stored === '') {
            return BiometricRecoverResult::missing();
        }

        $blob = base64_decode($stored, strict: true);
        $dataKey = $blob === false ? null : $this->codec->unwrap($blob);

        return $dataKey === null
            ? BiometricRecoverResult::missing()
            : BiometricRecoverResult::recovered($dataKey);
    }

    // Removes the enrolled entry (on disable, PIN reset re-enroll, or a
    // rekey/revocation - see the design doc lifecycle table).
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

    // -------------------------------------------------------------------------
    // Native seam (overridable in tests; facade confined here)
    // -------------------------------------------------------------------------

    protected function runtimeAvailable(): bool
    {
        if (! class_exists(BiometricVault::class)) {
            return false;
        }

        return UserDataPathService::isMobileRuntime();
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

    // Reads the base64 blob the async (Android) BiometricPrompt callback
    // stashed in the transient native slot, or null when nothing is
    // pending. No biometric prompt happens here - it already ran.
    protected function pollRecovered(): ?string
    {
        if (! class_exists(BiometricVault::class)) {
            return null;
        }

        $value = BiometricVault::pollRecovered();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
