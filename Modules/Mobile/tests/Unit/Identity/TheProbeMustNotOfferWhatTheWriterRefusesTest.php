<?php

declare(strict_types=1);

// Two facts about the same platform, held in two classes in one file. Set()
// writes, and IsAvailable() is asked whether the vault can hold a key; the two
// must not disagree in either direction. While Set() refused every write, an
// "Enroll this device" button appeared on every Android build, because the
// probe was answering from the sensor alone. The BiometricPrompt wiring has
// landed, so the refusal it reported is now the lie: `async_unimplemented` is
// no answer any build can honestly give, and setIsAsyncOnly() is gone.
//
// The Kotlin and the Swift are compiled by a toolchain no PHP run has, so this
// reads the sources. The pair is what is checked, not either half.

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

it('does not let the Android probe report a refusal Set no longer makes', function (): void {
    $kotlin = biometricVaultPluginSource('android/BiometricVaultFunctions.kt');

    // A positive control the other way up: while Set answered `async_required`
    // the assertions below described a state the file was not in. If that
    // refusal ever returns, the probe has to learn to report it again.
    expect(str_contains($kotlin, '"async_required" to true'))->toBeFalse(
        'Set() refuses on Android again, so IsAvailable() has to say so rather than offering the vault.'
    );

    expect(str_contains($kotlin, 'async_unimplemented'))->toBeFalse(
        'The probe still answers async_unimplemented, which no build can now be in.'
    );

    expect(str_contains($kotlin, 'setIsAsyncOnly'))->toBeFalse(
        'setIsAsyncOnly() outlived the refusal it stood for.'
    );
});

// Set() has to stay synchronous, and this is the reason in one line: the PHP
// caller zeroes the data key on the statement after the one that stores it, so
// a Set that answered by event would be waiting on a key that no longer exists.
it('keeps the Android write synchronous', function (): void {
    $kotlin = biometricVaultPluginSource('android/BiometricVaultFunctions.kt');

    $set = substr($kotlin, strpos($kotlin, 'class Set('), 900);

    expect($set)->toContain('"success" to true')
        ->and($set)->not->toContain('BiometricPrompt(');
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
