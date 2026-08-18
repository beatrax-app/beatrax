<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;

/*
 * Two lists name the same set of shell patches, and they drifted.
 *
 * composer's post-update-cmd applies them after an update; NativeBuildPatches
 * re-applies them immediately before a build, because the build tooling
 * regenerates the Android project and a regenerated project carries only what
 * ran after it. The second list was three short — including the file chooser,
 * without which nothing can enter the app on Android at all.
 *
 * That gap is invisible to a developer: `composer update` runs the full list,
 * so their own device has every patch. It only shows up in CI and Bifrost,
 * which run `composer install` — and post-update-cmd does not fire for that.
 */

/** @return list<string> the nativephp_*.php scripts a composer hook invokes */
function patchScriptsIn(string $hook): array
{
    $manifest = json_decode(
        (string) file_get_contents(base_path('mobile-app/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)->toBeArray()->toHaveKey('scripts');

    $scripts = [];

    foreach ($manifest['scripts'][$hook] ?? [] as $line) {
        if (preg_match('/(nativephp_[a-z0-9_]+\.php)/', (string) $line, $m) === 1) {
            $scripts[] = $m[1];
        }
    }

    sort($scripts);

    return $scripts;
}

/** @return list<string> the scripts NativeBuildPatches re-applies per build */
function perBuildPatchScripts(): array
{
    $scripts = (new ReflectionClass(NativeBuildPatches::class))
        ->getReflectionConstant('SCRIPTS')
        ->getValue();

    expect($scripts)->toBeArray();

    /** @var list<string> $scripts */
    sort($scripts);

    return $scripts;
}

it('re-applies before a build every patch composer applies after an update', function (): void {
    expect(perBuildPatchScripts())->toBe(patchScriptsIn('post-update-cmd'));
});

it('re-applies everything the native:patch command applies', function (): void {
    // A subset check rather than equality: native:patch is the by-hand repair
    // route and deliberately omits the two that only matter on a fresh
    // scaffold, but nothing it does may be missing from the per-build pass.
    expect(array_diff(patchScriptsIn('native:patch'), perBuildPatchScripts()))->toBe([]);
});

it('names only scripts that exist on disk', function (): void {
    foreach (perBuildPatchScripts() as $script) {
        expect(base_path('scripts/'.$script))->toBeFile();
    }
});
