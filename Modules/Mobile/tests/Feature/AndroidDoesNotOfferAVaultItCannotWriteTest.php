<?php

declare(strict_types=1);

use Modules\Mobile\Tests\Support\PlatformStubVault;
use Modules\Mobile\Tests\Support\RecordingMobileLogger;

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
//
// The refusal used to be PHP_OS_FAMILY !== 'Linux', which is the right answer
// to a question nobody was asking: an iPhone with Face ID switched off and a
// Samsung with no finger enrolled both read as able to hold the key. The
// device is asked now, and the reason it gives is what these arms carry.

it('does not offer the vault when the device says it cannot hold the key', function (string $reason): void {
    $vault = new PlatformStubVault(['available' => false, 'reason' => $reason]);

    expect($vault->isAvailable())->toBeFalse();
})->with([
    'a sensor the OS says needs a security update first' => ['security_update_required'],
    'a phone with no finger or face enrolled' => ['none_enrolled'],
    'a phone with no biometric sensor' => ['no_hardware'],
    'a sensor the OS has locked out after too many attempts' => ['hardware_unavailable'],
]);

it('still offers the vault where the device says it can hold the key', function (): void {
    expect((new PlatformStubVault(['available' => true, 'reason' => 'available']))->isAvailable())->toBeTrue();
});

it('refuses an enrolment rather than letting it fail at the bridge', function (): void {
    $vault = new PlatformStubVault(['available' => false, 'reason' => 'none_enrolled']);

    expect($vault->enroll(1, 'a-data-key'))->toBeFalse();
});

// A bridge that answered nothing is not a device that said yes. This is the
// off-device shape — no phone, no plugin, an empty array — and the desktop and
// the test suite both take it.
it('treats an unanswered probe as a device that cannot hold the key', function (): void {
    $log = new RecordingMobileLogger;

    expect((new PlatformStubVault([], true, $log))->isAvailable())->toBeFalse()
        ->and($log->records[0]['context']['reason'])->toBe('unreadable');
});

// The word "false" was the whole account of it on the iPhone. Whatever the
// device said, the log says it too, or the next reader is back on the phone
// with a patched file.
it('writes down which of the reasons the device gave', function (): void {
    $log = new RecordingMobileLogger;

    (new PlatformStubVault(['available' => false, 'reason' => 'none_enrolled'], true, $log))->isAvailable();

    expect($log->records)->toHaveCount(1)
        ->and($log->records[0]['level'])->toBe('debug')
        ->and($log->records[0]['context']['reason'])->toBe('none_enrolled');
});

// Nothing is asked of the platform off-device: runtimeAvailable() is the first
// half of the conjunction, and a probe that ran anyway would log a refusal on
// every desktop boot for a vault that was never on offer there.
it('never asks the device anything off a phone', function (): void {
    $log = new RecordingMobileLogger;

    expect((new PlatformStubVault(['available' => true, 'reason' => 'available'], false, $log))->isAvailable())
        ->toBeFalse()
        ->and($log->records)->toBe([]);
});
