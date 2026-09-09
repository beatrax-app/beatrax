<?php

declare(strict_types=1);

// Two facts about the same platform, held in two classes in one file. Set()
// knows it cannot write on Android until the BiometricPrompt wiring lands;
// IsAvailable() is asked whether the vault can hold a key. Answering the
// second from the sensor alone is what put an "Enroll this device" button on
// every Android build for a vault that refuses every write.
//
// The Kotlin and the Swift are compiled by a toolchain no PHP run has, so this
// reads the sources. The pair is what is checked, not either half: when the
// prompt wiring lands, setIsAsyncOnly() goes and this test goes with it.

/**
 * Both Composer roots run this directory and only one of them is mobile-app.
 */
function biometricVaultPluginSource(string $relative): string
{
    $under = 'nativephp-plugins/biometric-vault/resources/'.$relative;

    $path = collect([base_path($under), base_path('mobile-app/'.$under)])
        ->first(static fn (string $candidate): bool => is_file($candidate));

    expect($path)->not->toBeNull('The BiometricVault plugin source moved: '.$relative);

    return (string) file_get_contents((string) $path);
}

it('does not let the Android probe answer yes while Set still refuses', function (): void {
    $kotlin = biometricVaultPluginSource('android/BiometricVaultFunctions.kt');

    // A positive control: if Set no longer refuses, the rest of this is about
    // a state the file is not in and the assertions below would pass vacuously.
    expect(str_contains($kotlin, '"async_required" to true'))->toBeTrue(
        'Set() no longer refuses on Android, so delete setIsAsyncOnly() and this test with it.'
    );

    expect(str_contains($kotlin, 'sensorReady && setIsAsyncOnly() -> "async_unimplemented"'))->toBeTrue(
        'IsAvailable() offers a vault Set() will refuse: it reads the sensor and not setIsAsyncOnly().'
    );
});

// Read off the Samsung: the refusal has to be one fact, not two copies. Set()
// and IsAvailable() both call it, so a build that wires the prompt cannot flip
// one and leave the other offering or refusing on its own.
it('has both Android callers read the same refusal', function (): void {
    $kotlin = biometricVaultPluginSource('android/BiometricVaultFunctions.kt');

    expect(substr_count($kotlin, 'setIsAsyncOnly()'))->toBe(3, 'Set, IsAvailable, and the declaration.');
});

// iOS is the other half of the same pair and reaches the opposite conclusion:
// Set() writes synchronously there, so the probe answers from the enclave. The
// policy has to be the one the keychain item is written under — a passcode
// canEvaluatePolicy would admit is one .biometryCurrentSet then refuses.
it('has the iOS probe ask for the authentication the keychain item requires', function (): void {
    $swift = biometricVaultPluginSource('ios/BiometricVaultFunctions.swift');

    expect(str_contains($swift, 'canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics'))->toBeTrue(
        'The iOS probe admits something .biometryCurrentSet will refuse.'
    );

    expect(str_contains($swift, '.biometryCurrentSet'))->toBeTrue(
        'The iOS keychain item no longer gates on the current biometric set.'
    );
});

// The reasons cross the bridge as strings and the PHP side shows nothing but
// passes them to a log a person reads. Both platforms have to draw the same
// distinctions or the log means one thing on a phone and another on the other.
it('has both platforms name the refusals the same way', function (string $reason): void {
    $kotlin = biometricVaultPluginSource('android/BiometricVaultFunctions.kt');
    $swift = biometricVaultPluginSource('ios/BiometricVaultFunctions.swift');

    expect(str_contains($kotlin, '"'.$reason.'"'))->toBeTrue('Android never answers '.$reason.'.')
        ->and(str_contains($swift, '"'.$reason.'"'))->toBeTrue('iOS never answers '.$reason.'.');
})->with(['available', 'none_enrolled', 'no_hardware', 'hardware_unavailable', 'unsupported']);
