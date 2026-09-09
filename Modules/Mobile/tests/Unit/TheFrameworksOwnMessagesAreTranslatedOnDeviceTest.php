<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

// Measured on a Galaxy A51 set to Dutch: every screen was Dutch and the
// validation error under the file picker read "The file field is required."
//
// The mobile Composer root reaches the application through symlinks — app,
// Modules, public, resources, routes, tests — and `lang` was not among them.
// The published translations for all 26 locales therefore did not exist in the
// root the bundle is built from, and `./app_storage/laravel/lang/` was absent
// on the device.
//
// It read as English rather than as a missing key because Laravel ships its
// own `en` files inside the framework package, so the fallback always
// resolved. Only the translated locales were missing, and only on the phone.

/** @return list<string> the directories the mobile Composer root has to reach */
function mobileRootDirectories(): array
{
    return ['app', 'Modules', 'config', 'database', 'lang', 'public', 'resources', 'routes'];
}

// Resolved rather than assumed: the suite runs from both roots, and base_path()
// is the mobile one in the mobile-app job.
function mobileComposerRoot(): string
{
    foreach ([base_path('mobile-app'), base_path()] as $candidate) {
        if (is_file($candidate.'/composer.json')) {
            $manifest = json_decode((string) file_get_contents($candidate.'/composer.json'), true);

            if (is_array($manifest) && ($manifest['name'] ?? null) === 'beatrax/beatrax-mobile') {
                return $candidate;
            }
        }
    }

    throw new RuntimeException('no mobile composer root reachable from '.base_path());
}

// The bundle is built from that root and copies what it finds there. A
// directory the root cannot see is not "missing from the build" — it was never
// a candidate for it.
it('reaches every directory the bundle is built from', function (): void {
    $root = mobileComposerRoot();

    $missing = array_values(array_filter(
        mobileRootDirectories(),
        static fn (string $directory): bool => ! is_dir($root.'/'.$directory),
    ));

    expect($missing)->toBe([], 'the mobile root cannot reach: '.implode(', ', $missing));
});

// The one the device actually failed on. Laravel's own `en` files live in the
// framework package and always resolve, so a missing published translation
// reads as a fluent English sentence rather than as a missing key — which is
// why a fully translated phone showed one English line and nothing looked
// broken enough to chase.
it('answers a framework validation message in a language that is not the fallback', function (): void {
    $dutch = Lang::get('validation.required', ['attribute' => 'bestand'], 'nl');

    expect($dutch)->not->toBe('validation.required')
        ->and($dutch)->not->toContain('is required')
        ->and($dutch)->toContain('verplicht');
});

// Every locale the application ships, not only the one the defect was found in.
it('answers that message in every published locale', function (): void {
    $root = mobileComposerRoot();

    $locales = array_values(array_filter(
        array_map('basename', (array) glob($root.'/lang/*', GLOB_ONLYDIR)),
        static fn (string $locale): bool => $locale !== 'en',
    ));

    expect($locales)->not->toBeEmpty();

    $untranslated = array_values(array_filter(
        $locales,
        static fn (string $locale): bool => str_contains(
            (string) Lang::get('validation.required', ['attribute' => 'x'], $locale),
            'is required',
        ),
    ));

    expect($untranslated)->toBe([], 'still falling back to English: '.implode(', ', $untranslated));
});
