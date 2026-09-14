<?php

declare(strict_types=1);

/*
 * Gives the AppImage target an ${arch} in its file name.
 *
 * NativePHP's generated electron-builder.mjs sets
 * `appImage.artifactName = appName + '-${version}.${ext}'` while its
 * package.json builds Linux for BOTH architectures
 * (`build:linux": "cross-env npm run build:linux-x64 -- --arm64"`). Two builds,
 * one file name: electron-builder writes
 * `building target=AppImage arch=x64 file=dist/Beatrax-<v>.AppImage` and then
 * `building target=AppImage arch=arm64 file=dist/Beatrax-<v>.AppImage`, so the
 * arm64 image overwrites the x64 one and the only AppImage that survives is
 * arm64 -- published under a name that says nothing about architecture, and
 * pointed at by latest-linux.yml, which electron-updater reads on x64.
 *
 * Measured on v2.0.0-rc.2, where the release smoke test launched what the build
 * produced and got `Exec format error` from a bundle it had just been handed.
 * `mac` and `dmg` in the same file already carry ${arch}, and the `deb` target
 * takes electron-builder's own default, which does too; the AppImage target is
 * the only one that does not.
 *
 * Idempotent, and it refuses rather than guesses: a shape it does not recognise
 * is a NativePHP upgrade that moved this, and silently doing nothing there
 * would put the defect back. Delete this script and the pin in
 * tests/Contracts/AnAppImageCarriesItsArchitectureArchTest.php the day the
 * package ships an ${arch} of its own.
 */

const CONFIG = 'nativephp/electron/electron-builder.mjs';

const WANTED = "appName + '-\${version}-\${arch}.\${ext}'";

const CURRENT = "appName + '-\${version}.\${ext}'";

$root = dirname(__DIR__);
$path = $root.'/'.CONFIG;

if (! is_file($path)) {
    // native:install has not run yet in this checkout; nothing to patch.
    printf("nativephp_disambiguate_appimage_by_arch: %s is absent, nothing to do.\n", CONFIG);

    exit(0);
}

$source = (string) file_get_contents($path);

// Non-greedy to the first brace that closes a line: the value itself carries
// `${version}` and `${ext}`, so a [^}] body stops inside the string it is reading.
if (preg_match('/\bappImage:\s*\{(?<body>.*?)\n\s*\},/s', $source, $block) !== 1) {
    fwrite(STDERR, sprintf(
        "nativephp_disambiguate_appimage_by_arch: %s has no appImage block. The AppImage name is what this script exists to fix, so a config it cannot read is a failure, not a skip.\n",
        CONFIG,
    ));

    exit(1);
}

if (str_contains($block['body'], '${arch}')) {
    printf('nativephp_disambiguate_appimage_by_arch: the AppImage name already carries ${arch}.'."\n");

    exit(0);
}

if (! str_contains($block['body'], CURRENT)) {
    fwrite(STDERR, sprintf(
        'nativephp_disambiguate_appimage_by_arch: the appImage block names %s, which is neither the shape this patches nor one carrying ${arch}. Read it before changing this script:'."\n%s\n",
        trim($block['body']),
        CONFIG,
    ));

    exit(1);
}

$patched = str_replace($block[0], str_replace(CURRENT, WANTED, $block[0]), $source);

if (@file_put_contents($path, $patched) === false) {
    fwrite(STDERR, sprintf("nativephp_disambiguate_appimage_by_arch: could not write %s\n", CONFIG));

    exit(1);
}

printf('nativephp_disambiguate_appimage_by_arch: the AppImage name now carries ${arch}.'."\n");
