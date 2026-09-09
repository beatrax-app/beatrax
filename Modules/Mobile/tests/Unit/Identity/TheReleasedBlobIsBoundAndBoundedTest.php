<?php

declare(strict_types=1);

// Nothing in either Composer root compiles Kotlin, so the enclave half of
// cold-start unlock has no test that runs it. These read the source instead,
// and they read it for the four properties a released data key depends on:
// which slot it may be handed back to, how long it may sit unclaimed, what the
// envelope is bound to, and which Keystore pair may open it. A build that
// loses one of them still passes every test that runs.

/**
 * Both Composer roots run this directory and only one of them is mobile-app.
 */
function releasedBlobSource(string $relative): string
{
    $under = 'nativephp-plugins/biometric-vault/'.$relative;

    $path = collect([base_path($under), base_path('mobile-app/'.$under)])
        ->first(static fn (string $candidate): bool => is_file($candidate));

    expect($path)->not->toBeNull('The BiometricVault plugin source moved: '.$relative);

    return (string) file_get_contents((string) $path);
}

function releasedBlobAndroidSource(): string
{
    $kotlin = releasedBlobSource('resources/android/BiometricVaultFunctions.kt');

    // The positive control. Every assertion below is a str_contains over this
    // string, so a read that returned the wrong file, or nothing, would report
    // the same clean answer as a build that never had the property.
    expect($kotlin)->toContain('object BiometricVaultFunctions');

    return $kotlin;
}

// The wrap secret lives inside the blob, so an unwrap that succeeds says only
// that the blob was well formed — never whose it is. The slot name is the only
// thing that carries that, and a return trip that drops it hands one reader's
// session the key belonging to another's.
it('releases the blob only to the slot it was stashed under', function (): void {
    $kotlin = releasedBlobAndroidSource();

    expect($kotlin)->toContain('class PollRecovered')
        ->and($kotlin)->toContain('private class Released(val key: String')
        ->and($kotlin)->toContain('if (held.key != key)');

    $poll = substr($kotlin, (int) strpos($kotlin, 'class PollRecovered'), 500);

    expect($poll)->toContain('parameters["key"]')
        ->and($poll)->toContain('claim(key)');
});

// ON_STOP does not fire for a translucent activity, a split-screen focus loss
// or picture-in-picture, and an idle re-lock happens with the app still in the
// foreground. The lifecycle edge is a backstop; the deadline is the bound.
it('gives an unclaimed blob a deadline of its own', function (): void {
    $kotlin = releasedBlobAndroidSource();

    expect($kotlin)->toContain('SLOT_TTL_MS')
        ->and($kotlin)->toContain('main.postDelayed(deadline, SLOT_TTL_MS)')
        ->and($kotlin)->toContain('main.removeCallbacks(it)');
});

// A single shared alias made one reader's re-enrolment every other reader's
// problem, and the read path then minted a replacement pair mid-read rather
// than reporting that there was nothing left to authenticate against.
it('keys the Keystore pair on the slot and never mints one while reading', function (): void {
    $kotlin = releasedBlobAndroidSource();

    expect($kotlin)->toContain('private fun aliasFor(key: String): String = ALIAS_PREFIX + key')
        ->and($kotlin)->not->toContain('unwrapCipher(): Cipher');

    $get = substr($kotlin, (int) strpos($kotlin, 'class Get('), 1800);

    expect($get)->toContain('containsAlias(aliasFor(key))')
        ->and($get)->not->toContain('getOrCreateKeyPair');
});

// Without associated data anyone who can write the SharedPreferences file can
// move a blob between slots or restore one a rekey retired, and the envelope
// opens as if nothing happened.
it('binds the envelope on both the seal and the open', function (): void {
    $kotlin = releasedBlobAndroidSource();

    $seal = substr($kotlin, (int) strpos($kotlin, 'private fun seal('), 600);
    $open = substr($kotlin, (int) strpos($kotlin, 'private fun open('), 700);

    expect($seal)->toContain('updateAAD(aad(key))')
        ->and($open)->toContain('updateAAD(aad(key))');
});

// BiometricVaultFunctions is a Kotlin object and the process outlives its
// activity. A boolean latch left the observer on a DESTROYED registry and
// refused to attach the live activity's, so ON_STOP stopped meaning anything.
it('keys the lifecycle observer on the activity rather than on a flag', function (): void {
    $kotlin = releasedBlobAndroidSource();

    expect($kotlin)->not->toContain('watchingLifecycle')
        ->and($kotlin)->toContain('if (watched?.get() === activity) return')
        ->and($kotlin)->toContain('WeakReference(activity)');
});

// setUserAuthenticationParameters is API 30. The declared floor is what the
// build validates against, and a NoSuchMethodError is an Error rather than an
// Exception, so the bridge's catch would not see it coming.
it('declares an Android floor the Keystore call it makes actually has', function (): void {
    /** @var array{android: array{min_version: int}} $manifest */
    $manifest = json_decode(releasedBlobSource('nativephp.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(releasedBlobAndroidSource())->toContain('setUserAuthenticationParameters(')
        ->and($manifest['android']['min_version'])->toBeGreaterThanOrEqual(30);
});
