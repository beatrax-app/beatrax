#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Signs every Windows PE binary in the package, not only the .exe files.
 *
 * Why this is needed:
 *
 *   electron-builder decides what to sign in `WinPackager.shouldSignFile`:
 *
 *     shouldSignFile(file, fallbackValue = false) {
 *         const backwardCompatibility = file.endsWith(".exe");
 *         const signExts = this.platformSpecificBuildOptions.signExts;
 *         if (!signExts?.length) {
 *             return backwardCompatibility || fallbackValue;
 *         }
 *         ...
 *
 *   With `win.signExts` unset — which is what NativePHP emits — the answer
 *   for every file that is not an .exe is false. The desktop bundle carries
 *   a whole PHP runtime under extraResources: php.exe is signed, and
 *   php8ts.dll, every bundled extension and every support library beside it
 *   are not.
 *
 *   That is invisible on a direct download, because Windows only checks the
 *   signature of what it is asked to launch, and the installer and the shell
 *   are both signed. Microsoft Store certification is per FILE: an unsigned
 *   PE anywhere in the package fails it. The same gap is what lets a DLL
 *   beside a signed executable be replaced without the signature saying so.
 *
 * `.node` joins the list for the same reason: a native Electron module is a
 * PE by another extension, and electron-builder's own fallback misses it.
 *
 * Signing more files does not weaken anything and needs no new credential —
 * the same Trusted Signing profile signs them, in the same pass.
 *
 * The patch is reapplied before every build (it is a `prebuild` hook) and is
 * idempotent: a config that already names signExts is left untouched.
 *
 * Exit codes:
 *   0  patched, or already correct
 *   1  the config exists but does not have the expected shape
 */
$projectRoot = dirname(__DIR__);
$configPath = $projectRoot.'/nativephp/electron/electron-builder.mjs';

if (! is_file($configPath)) {
    fwrite(STDERR, "nativephp_sign_every_pe_in_the_package: no electron-builder.mjs found at {$configPath} — has `php artisan native:install --publish` run?\n");

    exit(1);
}

$source = file_get_contents($configPath);

if ($source === false) {
    fwrite(STDERR, "nativephp_sign_every_pe_in_the_package: could not read {$configPath}\n");

    exit(1);
}

if (str_contains($source, 'signExts')) {
    fwrite(STDOUT, "nativephp_sign_every_pe_in_the_package: signExts already present, leaving as-is.\n");

    exit(0);
}

// Anchored on the first key of the win block rather than on `win: {` itself,
// so a config whose win block ever changes shape fails here rather than
// producing a file that parses and signs nothing extra.
$anchor = '/(\bwin\s*:\s*\{\s*\n\s*executableName:\s*fileName,)/';

$replacement = "$1\n"
    ."        // Every PE in the package, not just the .exe files: the bundled PHP\n"
    ."        // runtime is mostly DLLs, and electron-builder signs only .exe when\n"
    ."        // this key is absent. See scripts/nativephp_sign_every_pe_in_the_package.php.\n"
    ."        signExts: ['.exe', '.dll', '.node'],";

$patched = preg_replace($anchor, $replacement, $source, 1, $count);

if ($patched === null || $count !== 1) {
    fwrite(STDERR, "nativephp_sign_every_pe_in_the_package: could not locate the win block's executableName in {$configPath}.\n");
    fwrite(STDERR, "The generated config changed shape; confirm every bundled DLL is still signed before shipping to a store.\n");

    exit(1);
}

if (file_put_contents($configPath, $patched) === false) {
    fwrite(STDERR, "nativephp_sign_every_pe_in_the_package: could not write {$configPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_sign_every_pe_in_the_package: win.signExts now covers .exe, .dll and .node.\n");

exit(0);
