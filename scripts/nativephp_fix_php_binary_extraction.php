#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Patches NativePHP's `php.js` so the bundled static PHP binary is
 * extracted BYTE-EXACT — a hard precondition for Developer ID signing +
 * notarization on macOS.
 *
 * Runs as a `prebuild` hook (see config/nativephp.php). electron-builder's
 * `beforePack` runs `node php.js --<os> --<arch>`, which unzips the PHP
 * binary from `vendor/nativephp/php-bin/...` into the build's `php/`
 * directory. The shipped `php.js` streams the zip entry through `yauzl`
 * (`readStream.pipe(writeStream)`), which CORRUPTS the arm64 Mach-O — the
 * output is ~5 MB LARGER than the source (71.5 MB vs the correct 66.4 MB
 * that `ditto`/`unzip` produce). codesign then rejects it with "main
 * executable failed strict validation" and the whole signed build aborts.
 *
 * The fix replaces the `yauzl` streaming extraction with a synchronous
 * `ditto -x -k` (on the macOS build host) / `unzip -o` (other POSIX hosts)
 * call, which reproduces the binary exactly. Idempotent: re-running detects
 * the `execFileSync` marker and leaves the file untouched. Applied to BOTH
 * the published `nativephp/electron/php.js` and the vendored fallback so
 * whichever the build resolves is correct. This hook exists BECAUSE the
 * patch must reapply automatically on every build — never a manual step.
 *
 * Exit codes:
 *   0  patched (or already patched, or no php.js present yet)
 *   1  a php.js was found but could not be patched
 */
$projectRoot = dirname(__DIR__);

$candidates = array_filter([
    $projectRoot.'/nativephp/electron/php.js',
    $projectRoot.'/vendor/nativephp/desktop/resources/electron/php.js',
], 'is_file');

if ($candidates === []) {
    // Nothing published/vendored yet — the copy/scaffold step will bring a
    // php.js later; a subsequent build re-runs this hook. Not an error.
    fwrite(STDOUT, "nativephp_fix_php_binary_extraction: no php.js found yet — skipping.\n");

    exit(0);
}

$corrected = <<<'JS'
import fs from 'fs';
import fs_extra from 'fs-extra';
import { join } from 'path';
import { execFileSync } from 'child_process';
const { removeSync, ensureDirSync } = fs_extra;

const isBuilding = Boolean(process.env.NATIVEPHP_BUILDING);
const phpBinaryPath = process.env.NATIVEPHP_PHP_BINARY_PATH;
const phpVersion = process.env.NATIVEPHP_PHP_BINARY_VERSION;

// Differentiates for Serving and Building
const isArm64 = isBuilding ? process.argv.includes('--arm64') : process.arch.includes('arm64');
const isWindows = isBuilding ? process.argv.includes('--win') : process.platform.includes('win32');
const isLinux = isBuilding ? process.argv.includes('--linux') : process.platform.includes('linux');
const isDarwin = isBuilding ? process.argv.includes('--mac') : process.platform.includes('darwin');

// false because string mapping is done in is{OS} checks
const platform = {
    os: false,
    arch: false,
    phpBinary: 'php',
};

if (isWindows) {
    platform.os = 'win';
    platform.arch = 'x64';
    platform.phpBinary += '.exe';
}

if (isLinux) {
    platform.os = 'linux';
    platform.arch = 'x64';
}

if (isDarwin) {
    platform.os = 'mac';
    platform.arch = 'x64';
}

if (isArm64) {
    platform.arch = 'arm64';
}

// isBuilding overwrites platform to the desired architecture
if (isBuilding) {
    platform.arch = process.argv.includes('--x64') ? 'x64' : platform.arch;
    platform.arch = process.argv.includes('--arm64') ? 'arm64' : platform.arch;
}

const phpVersionZip = 'php-' + phpVersion + '.zip';
const binarySrcDir = join(phpBinaryPath, platform.os, platform.arch, phpVersionZip);
const binaryDestDir = join(process.env.NATIVEPHP_BUILD_PATH, 'php');

console.log('Binary Source: ', binarySrcDir);
console.log('Binary Filename: ', platform.phpBinary);
console.log('PHP version: ' + phpVersion);

if (platform.phpBinary) {
    try {
        console.log('Extracting PHP binary from ' + binarySrcDir + ' to ' + binaryDestDir);
        removeSync(binaryDestDir);
        ensureDirSync(binaryDestDir);

        // BYTE-EXACT extraction (patched by scripts/nativephp_fix_php_binary_extraction.php).
        // The upstream yauzl streaming pipe inflates/corrupts the static PHP
        // Mach-O (~+5 MB) so codesign --options runtime rejects it with
        // "main executable failed strict validation". ditto (macOS) / unzip
        // (other POSIX build hosts) reproduce the binary exactly, which is a
        // hard precondition for Developer ID signing + notarization.
        if (process.platform === 'darwin') {
            execFileSync('ditto', ['-x', '-k', binarySrcDir, binaryDestDir], { stdio: 'inherit' });
        } else {
            execFileSync('unzip', ['-o', binarySrcDir, '-d', binaryDestDir], { stdio: 'inherit' });
        }

        const binaryPath = join(binaryDestDir, platform.phpBinary);
        fs.chmodSync(binaryPath, 0o755);
        console.log('Copied PHP binary to ', binaryPath);
    } catch (e) {
        console.error('Error copying PHP binary', e);
    }
}
JS;

$failed = false;

foreach ($candidates as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "nativephp_fix_php_binary_extraction: could not read {$path}\n");
        $failed = true;

        continue;
    }

    if (str_contains($source, 'execFileSync')) {
        fwrite(STDOUT, "nativephp_fix_php_binary_extraction: already patched — {$path}\n");

        continue;
    }

    // Only rewrite a php.js that still uses the corrupting yauzl extraction,
    // so an unrelated future NativePHP php.js is never silently clobbered.
    if (! str_contains($source, "yauzl")) {
        fwrite(STDOUT, "nativephp_fix_php_binary_extraction: unexpected php.js shape (no yauzl) — leaving {$path} untouched.\n");

        continue;
    }

    if (file_put_contents($path, $corrected) === false) {
        fwrite(STDERR, "nativephp_fix_php_binary_extraction: could not write {$path}\n");
        $failed = true;

        continue;
    }

    fwrite(STDOUT, "nativephp_fix_php_binary_extraction: patched extraction to ditto/unzip — {$path}\n");
}

exit($failed ? 1 : 0);
