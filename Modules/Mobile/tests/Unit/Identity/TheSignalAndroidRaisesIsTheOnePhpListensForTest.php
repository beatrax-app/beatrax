<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;

// Android recovery completes by signal, and for as long as nothing raised the
// signal nothing could notice that PHP was listening on a channel the native
// side cannot produce. NativeActionCoordinator builds its call as
// `window.Livewire.dispatch("native:" + event, payload)` — the prefix is the
// coordinator's and is not optional — while MobileLockScreen listened for a
// bare `cold-start-recovered`. Both halves passed their own tests; the join
// between them was the thing nobody could run.

/**
 * Both Composer roots run this directory and only one of them is mobile-app.
 */
function androidVaultSource(string $relative): ?string
{
    $under = 'nativephp-plugins/biometric-vault/resources/'.$relative;

    foreach ([base_path($under), base_path('mobile-app/'.$under)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

function androidVaultEventName(string $constant): string
{
    $kotlin = androidVaultSource('android/BiometricVaultFunctions.kt');

    expect($kotlin)->not->toBeNull('The BiometricVault Kotlin source moved.');

    $found = PatternScan::first('/private const val '.$constant.' = "([^"]+)"/', (string) $kotlin);

    expect($found)->toHaveCount(2, 'The Kotlin no longer declares '.$constant.'.');

    return $found[1];
}

it('listens on the channel the Android side raises, prefix included', function (string $constant): void {
    $raised = androidVaultEventName($constant);

    /** @var string $listened */
    $listened = (new ReflectionClassConstant(MobileLockScreen::class, $constant))->getValue();

    expect($listened)->toBe('native:'.$raised);
})->with(['EVENT_RECOVERED', 'EVENT_FAILED']);

// The manifest is the third copy of these two names, and it is the one the
// plugin compiler reads. A name only two of the three agree on is the same
// defect in a new place.
it('raises the events the plugin manifest declares', function (): void {
    $manifest = collect([
        base_path('nativephp-plugins/biometric-vault/nativephp.json'),
        base_path('mobile-app/nativephp-plugins/biometric-vault/nativephp.json'),
    ])->first(static fn (string $candidate): bool => is_file($candidate));

    expect($manifest)->not->toBeNull('The BiometricVault manifest moved.');

    /** @var array{events: list<string>} $declared */
    $declared = json_decode((string) file_get_contents((string) $manifest), true, flags: JSON_THROW_ON_ERROR);

    expect($declared['events'])->toContain(androidVaultEventName('EVENT_RECOVERED'))
        ->and($declared['events'])->toContain(androidVaultEventName('EVENT_FAILED'));
});

// The positive control for the prefix above. It lives in the NativePHP package
// rather than in this repository, so it is readable only from the root that
// installs it — and asserting the prefix without ever reading the code that
// applies it is how the bare channel names survived in the first place.
it('takes the prefix from the coordinator that applies it', function (): void {
    $coordinator = base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt');

    expect((string) file_get_contents($coordinator))
        ->toContain('window.Livewire.dispatch("native:$eventForJs", payload)');
})->skip(
    fn (): bool => ! is_file(base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt')),
    'The NativePHP Android sources are installed only in the mobile-app root.',
);
