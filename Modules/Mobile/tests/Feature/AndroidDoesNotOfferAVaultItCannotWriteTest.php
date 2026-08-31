<?php

declare(strict_types=1);

use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\NullLogger;

// Settings on the Samsung offered "Use fingerprint — Enroll this device to
// unlock with biometrics", and Enroll answered "Your device declined to store
// the key. Biometric unlock is unavailable." The device had not declined: it
// authenticates other apps all day. The app's own vault plugin had, and its
// logcat line says why —
//
//   W BiometricVault.Set: Async BiometricPrompt required on Android
//
// which its Kotlin docblock spells out: on Android every Cipher operation on
// that Keystore key needs an asynchronous BiometricPrompt, and that wiring is
// deliberately still a skeleton. So enrolment cannot succeed on any Android
// build, and the control was offered on all of them.

final class PlatformStubVault extends BiometricKeyVault
{
    public function __construct(private readonly string $family, private readonly bool $runtime = true)
    {
        parent::__construct(app(BiometricKeyBlobCodec::class), new NullLogger);
    }

    protected function runtimeAvailable(): bool
    {
        return $this->runtime;
    }

    protected function platformFamily(): string
    {
        return $this->family;
    }
}

it('does not offer the vault on Android, where nothing can be written to it', function (): void {
    expect((new PlatformStubVault('Linux'))->isAvailable())->toBeFalse();
});

it('still offers the vault on iOS, where the keychain answers synchronously', function (): void {
    expect((new PlatformStubVault('Darwin'))->isAvailable())->toBeTrue();
});

it('refuses an enrolment on Android rather than letting it fail at the bridge', function (): void {
    expect((new PlatformStubVault('Linux'))->enroll(1, 'a-data-key'))->toBeFalse();
});
