<?php

declare(strict_types=1);

// NativePHP's generated electron-builder.mjs named the AppImage
// `<app>-<version>.AppImage` with no architecture in it, while its package.json
// builds Linux for x64 AND arm64. Two builds, one name: arm64 landed second and
// overwrote x64, so the only AppImage a release carried was arm64, published
// under a name that said nothing, and named by a latest-linux.yml that
// electron-updater reads on x64. v2.0.0-rc.2 launched it and got Exec format
// error.
//
// The config is generated and gitignored, so what is pinned here is the patch
// being on the prebuild chain that rewrites it, and the two places that would
// go back to reading an AppImage without knowing which one it is.
//
// The name itself is the second trap and cost its own release run: ${arch}
// renders as `x64` for a dmg and `x86_64` for an AppImage, so the obvious
// spelling matches no file.
// @link ../../scripts/nativephp_disambiguate_appimage_by_arch.php

const ARCH_PATCH = 'scripts/nativephp_disambiguate_appimage_by_arch.php';

const ARCH_PREBUILD = 'config/nativephp.php';

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function archRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('keeps the patch this rule is about', function (): void {
    expect(archRead(ARCH_PATCH))->not->toBe('', sprintf('%s is gone, so a freshly generated config keeps NativePHP\'s single AppImage name and the arm64 image overwrites the x64 one again.', ARCH_PATCH));
});

it('runs the patch from the chain that rewrites the generated config', function (): void {
    $config = archRead(ARCH_PREBUILD);
    expect($config)->not->toBe('', sprintf('%s is not where this rule looks, so it read nothing about what runs before a build.', ARCH_PREBUILD));

    // `prebuild` is the hook the sibling electron-builder patches use, and it
    // fires after native:install republishes the config and before
    // electron-builder reads it. A script on disk and on no chain never runs.
    $at = strpos($config, "'prebuild' => [");
    expect($at)->not->toBeFalse(sprintf('%s has no prebuild hook list, so this rule cannot tell whether the AppImage patch ever runs.', ARCH_PREBUILD));

    $end = strpos($config, '],', (int) $at);
    $hooks = substr($config, (int) $at, (int) $end - (int) $at);

    expect(str_contains($hooks, 'nativephp_disambiguate_appimage_by_arch.php'))->toBeTrue(sprintf('The AppImage patch is not on the prebuild chain in %s, so a build regenerates the config and packs both architectures under one name again.', ARCH_PREBUILD));
});

it('never reads an AppImage without saying which architecture it wants', function (): void {
    $body = archRead('.github/workflows/release.yml');
    $at = strpos($body, "\n    build-linux:\n");
    expect($at)->not->toBeFalse('The release workflow has no build-linux job, so this rule read nothing about the images it produces.');

    $next = preg_match('/\n    [a-z][a-z0-9-]*:\n/', $body, $m, PREG_OFFSET_CAPTURE, (int) $at + 1) === 1
        ? $m[0][1]
        : strlen($body);
    $job = substr($body, (int) $at, $next - (int) $at);

    // A bare `*.AppImage` is what picked the surviving image before, and with
    // two on disk it picks whichever the filesystem returns first.
    expect(str_contains($job, "-name '*.AppImage'"))->toBeFalse('build-linux finds an AppImage by a glob that matches both architectures, so it reads whichever one `find` returns first — the defect that shipped an arm64 image to x64 desktops.');
    expect(str_contains($job, '-name "*.AppImage"'))->toBeFalse('build-linux finds an AppImage by a glob that matches both architectures, so it reads whichever one `find` returns first.');
    // `x86_64`, not `x64`. electron-builder renders the same ${arch} macro as
    // `x64` for a dmg and `x86_64` for an AppImage, so the spelling that works
    // everywhere else matches no file here. It cost a release run to learn.
    expect(str_contains($job, '*-x86_64.AppImage'))->toBeTrue('Nothing in build-linux names the x86_64 AppImage, so the leg that must run on an x86_64 runner no longer says which image it means.');
    expect(str_contains($job, '-x64.AppImage'))->toBeFalse('build-linux looks for an AppImage named `-x64`, which electron-builder never writes: it spells that architecture `x86_64` in an AppImage name. The glob matches nothing and the build fails on a missing artefact.');
    expect(str_contains($job, '*-arm64.AppImage'))->toBeTrue('Nothing in build-linux names the arm64 AppImage, so an architecture can go missing from a release without the shape check noticing.');
});
