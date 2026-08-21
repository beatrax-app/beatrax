<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;

// The suite runs from both roots, so base_path() is not one place — the same
// asymmetry NativeBuildPatches::locate() exists for. Resolved rather than assumed.
function mobileComposerManifest(): string
{
    foreach ([base_path('composer.json'), base_path('mobile-app/composer.json')] as $candidate) {
        if (! is_file($candidate)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($candidate), true);

        if (is_array($decoded) && ($decoded['name'] ?? null) === 'beatrax/beatrax-mobile') {
            return $candidate;
        }
    }

    throw new RuntimeException('no mobile composer.json reachable from '.base_path());
}

/** @return list<string> the nativephp_*.php scripts a composer hook invokes */
function patchScriptsIn(string $hook): array
{
    $manifest = json_decode(
        (string) file_get_contents(mobileComposerManifest()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)->toBeArray()->toHaveKey('scripts');

    $scripts = [];

    foreach ($manifest['scripts'][$hook] ?? [] as $line) {
        if (preg_match('/(nativephp_[a-z0-9_]+\.php)/', (string) $line, $m) !== 1) {
            continue;
        }

        // Both hooks call one runner rather than listing the patches, because
        // composer aborts a script list on the first non-zero exit and one
        // patch legitimately fails on a freshly generated project. The list
        // moved inside the runner, so following it is the only way to keep
        // asserting what the hooks actually apply.
        if ($m[1] === 'nativephp_patch_all.php') {
            $scripts = array_merge($scripts, patchScriptsInRunner());

            continue;
        }

        $scripts[] = $m[1];
    }

    sort($scripts);

    return $scripts;
}

/** @return list<string> the scripts nativephp_patch_all.php runs, in its own order */
function patchScriptsInRunner(): array
{
    $runner = dirname(mobileComposerManifest()).'/../scripts/nativephp_patch_all.php';

    expect($runner)->toBeReadableFile();

    preg_match_all(
        "/'(nativephp_[a-z0-9_]+)'/",
        (string) file_get_contents($runner),
        $matches,
    );

    /** @var list<string> $names */
    $names = $matches[1];

    expect($names)->not->toBeEmpty();

    return array_map(static fn (string $name): string => $name.'.php', $names);
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

// Two lists name the same set of shell patches and they drifted: composer's
// post-update-cmd applies them after an update, NativeBuildPatches re-applies them
// before a build. The gap is invisible locally, because `composer update` runs the
// full list, while CI and Bifrost run `composer install` and never fire the hook.

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
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    foreach (perBuildPatchScripts() as $script) {
        expect($scripts.'/'.$script)->toBeFile();
    }
});
