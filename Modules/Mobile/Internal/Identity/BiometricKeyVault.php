<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Beatrax\BiometricVault\Facades\BiometricVault;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Public\Services\UserDataPathService;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
class BiometricKeyVault
{
    // Enclave entry-name prefix; the owning user id is appended. The id comes
    // from the caller and never from the session: a console or job caller has
    // no authenticated user, and reading one there both threw and, when it did
    // not, would have written one account's key into another's slot.
    private const string SLOT_PREFIX = 'beatrax.coldstart.datakey.';

    public function __construct(
        private readonly BiometricKeyBlobCodec $codec,
        private readonly LoggerInterface $log,
    ) {}

    public function isAvailable(): bool
    {
        return $this->runtimeAvailable() && $this->platformCanStore();
    }

    // Wraps the (currently-held) data key into a biometric blob and
    // stores it in the enclave-gated entry. Must be called while unlocked.
    // Returns false off-device or on a native failure.
    public function enroll(int $userId, string $dataKey): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $blob = $this->codec->wrap($dataKey);

        if ($this->vaultSet($this->slot($userId), base64_encode($blob))) {
            return true;
        }

        // The reader is told the device declined and nothing else. Found on an
        // iPhone 12 mini where enrolment failed every time and left no trace at
        // all: the only way to learn why was to patch this file onto the phone.
        $this->logRefusal($this->lastNativeError());

        return false;
    }

    // Presents the biometric prompt (iOS) or dispatches it (Android).
    // Never prompts when nothing is enrolled.
    public function recover(int $userId, string $reason = 'Unlock Beatrax'): BiometricRecoverResult
    {
        if (! $this->runtimeAvailable()) {
            return BiometricRecoverResult::unavailable();
        }

        $outcome = $this->vaultGet($this->slot($userId), $reason);

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

    // Answers whether the entry is gone. A delete that quietly failed leaves a
    // wrapped data key in the enclave while the app records the reader as
    // un-enrolled, and one of the callers is account deletion.
    public function clear(int $userId): bool
    {
        if (! $this->runtimeAvailable()) {
            // Nowhere to hold a key is not a refusal to release one.
            return true;
        }

        $cleared = $this->vaultDelete($this->slot($userId));

        if (! $cleared) {
            $this->logDeleteRefusal($this->lastNativeError());
        }

        return $cleared;
    }

    private function slot(int $userId): string
    {
        return self::SLOT_PREFIX.$userId;
    }

    // -------------------------------------------------------------------------
    // Native seam (overridable in tests; facade confined here)
    // -------------------------------------------------------------------------

    // Asks the device rather than reading which operating system is running.
    // PHP_OS_FAMILY answered the same on a phone with a fingerprint enrolled
    // and on one with none, so the reader was offered an enrolment the enclave
    // then refused, and the only account of it was the word "false".
    /**
     * @link ../../../../mobile-app/nativephp-plugins/biometric-vault/resources/android/BiometricVaultFunctions.kt
     */
    protected function platformCanStore(): bool
    {
        $capability = $this->vaultCapability();

        if (($capability['available'] ?? null) === true) {
            return true;
        }

        $this->logUnavailable($capability['reason'] ?? 'unreadable');

        return false;
    }

    /**
     * @return array{available?: bool, reason?: string}
     */
    protected function vaultCapability(): array
    {
        $answer = $this->capabilityAnswer();

        if (! is_array($answer)) {
            return [];
        }

        $reason = $answer['reason'] ?? null;

        return [
            'available' => ($answer['available'] ?? null) === true,
            'reason' => is_string($reason) && $reason !== '' ? $reason : 'unreadable',
        ];
    }

    // The bridge call itself, kept apart from the reading of its answer: the
    // repo root does not autoload the plugin, so every case below this line was
    // reachable only from the mobile-app root and went untested in both.
    protected function capabilityAnswer(): mixed
    {
        if (! class_exists(BiometricVault::class)) {
            return null;
        }

        return BiometricVault::capability();
    }

    // Debug rather than warning: on a phone with no biometric enrolled this is
    // the ordinary state of the world, not a fault, and the screen already
    // shows the reader a PIN pad instead of an affordance that cannot work.
    protected function logUnavailable(string $reason): void
    {
        $this->log->debug('BiometricKeyVault: this device cannot gate an entry behind a biometric.', [
            'reason' => $reason,
        ]);
    }

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

    protected function vaultDelete(string $key): bool
    {
        if (! class_exists(BiometricVault::class)) {
            return false;
        }

        return BiometricVault::delete($key) === true;
    }

    protected function lastNativeError(): ?string
    {
        if (! class_exists(BiometricVault::class)) {
            return null;
        }

        $value = BiometricVault::lastError();

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function logRefusal(?string $reason): void
    {
        $this->log->warning('BiometricKeyVault: the enclave refused to store the cold-start key.', [
            'reason' => $reason ?? 'the native side gave none',
        ]);
    }

    // Its own message rather than the one above. A removal that failed is not
    // a store that failed, and a log line naming the wrong operation sends
    // whoever reads it to the wrong half of the vault.
    protected function logDeleteRefusal(?string $reason): void
    {
        $this->log->warning('BiometricKeyVault: the enclave refused to remove the cold-start key, so a wrapped data key is still held.', [
            'reason' => $reason ?? 'the native side gave none',
        ]);
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
