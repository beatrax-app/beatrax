#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Puts the bundled interpreter where a nested executable is allowed to live,
 * and teaches the shell to look there.
 *
 * Why:
 *
 *   The desktop bundle carries its interpreter at
 *   `Contents/Resources/build/php/php`. Apple's submission tooling refuses a
 *   Mach-O executable outside a `Contents/MacOS` directory before a reviewer
 *   ever sees the upload, so the Mac App Store lane cannot ship that layout.
 *   `desktop:review-mac-bundle` names it as a refusal.
 *
 * Two halves, and both are needed:
 *
 *   The BUILD half adds the interpreter to the store lane's `extraFiles` with
 *   `to: 'MacOS/php'`; electron-builder writes extraFiles under `Contents/`,
 *   so that lands it beside the app's own executable. It is added to the `mas`
 *   block only: the Developer ID lane is notarised rather than sandboxed and
 *   its layout is already accepted, and moving a signed binary there would
 *   invalidate a shipping product to fix a lane it is not in.
 *
 *   The RUNTIME half teaches src/main/index.js to prefer that copy when it
 *   exists. On a Developer ID build it does not — `Contents/MacOS` holds the
 *   app's own executable, named after the app — so the lookup is unchanged
 *   there, which is what makes the patch safe to apply to both.
 *
 * Idempotent, marker-guarded, and a missing anchor is a hard failure rather
 * than a silent skip: a lane that quietly keeps the old layout produces a
 * bundle that is refused at upload, weeks later, by a machine.
 *
 * Exit codes:
 *   0  patched, or already correct
 *   1  a file exists but does not have the expected shape
 */
$projectRoot = dirname(__DIR__);
$electron = $projectRoot.'/nativephp/electron';

$configPath = $electron.'/electron-builder.mjs';
$mainPath = $electron.'/src/main/index.js';

foreach ([$configPath, $mainPath] as $required) {
    if (! is_file($required)) {
        fwrite(STDERR, "nativephp_interpreter_into_macos: {$required} is not there — has `php artisan native:install --publish` run?\n");

        exit(1);
    }
}

$config = (string) file_get_contents($configPath);

if (! str_contains($config, 'mas: {')) {
    fwrite(STDERR, "nativephp_interpreter_into_macos: no mas block in {$configPath}.\n");
    fwrite(STDERR, "nativephp_mac_app_store_lane.php has to run first; the ordering in config/nativephp.php is what guarantees it.\n");

    exit(1);
}

if (! str_contains($config, "to: 'MacOS/php'")) {
    $anchor = "    mas: {\n";

    if (! str_contains($config, $anchor)) {
        fwrite(STDERR, "nativephp_interpreter_into_macos: the mas block is not the shape this patches in {$configPath}.\n");

        exit(1);
    }

    $extraFiles = <<<'JS'
        // The interpreter, beside the app's own executable rather than under
        // Resources: Apple's submission tooling refuses a Mach-O outside a
        // MacOS directory. See scripts/nativephp_interpreter_into_macos.php.
        extraFiles: [
            {
                from: join(process.env.NATIVEPHP_BUILD_PATH, 'php', 'php'),
                to: 'MacOS/php',
            },
        ],
JS;

    $config = str_replace($anchor, $anchor.$extraFiles."\n", $config);

    if (file_put_contents($configPath, $config) === false) {
        fwrite(STDERR, "nativephp_interpreter_into_macos: could not write {$configPath}\n");

        exit(1);
    }
}

$main = (string) file_get_contents($mainPath);
$sentinel = 'sandboxedInterpreter';

if (str_contains($main, $sentinel)) {
    fwrite(STDOUT, "nativephp_interpreter_into_macos: already patched.\n");

    exit(0);
}

$mainAnchor = "const phpBinary = path.join(buildPath, 'php', executable);";

if (! str_contains($main, $mainAnchor)) {
    fwrite(STDERR, "nativephp_interpreter_into_macos: the interpreter lookup is not the shape this patches in {$mainPath}.\n");
    fwrite(STDERR, "Confirm where the shell finds PHP before shipping a sandboxed build; a wrong path fails at launch with no output.\n");

    exit(1);
}

$mainPatched = <<<'JS'
// A sandboxed build carries the interpreter beside the app's own executable,
// because Apple refuses a Mach-O outside a MacOS directory. A Developer ID
// build does not — that directory holds only the app itself — so this resolves
// to the Resources copy there and the lookup is unchanged.
const sandboxedInterpreter = path.join(buildPath, '..', '..', 'MacOS', executable);
const phpBinary = existsSync(sandboxedInterpreter)
    ? sandboxedInterpreter
    : path.join(buildPath, 'php', executable);
JS;

$main = str_replace($mainAnchor, $mainPatched, $main);

// existsSync comes from node:fs and the generated file imports neither. Added
// beside the other imports rather than inline: a require() in an ES module is
// a runtime error, and this file is one.
if (! str_contains($main, "from 'node:fs'")) {
    $importAnchor = "import path from 'path';";

    if (! str_contains($main, $importAnchor)) {
        fwrite(STDERR, "nativephp_interpreter_into_macos: no path import to add the fs one beside in {$mainPath}.\n");

        exit(1);
    }

    $main = str_replace($importAnchor, $importAnchor."\nimport { existsSync } from 'node:fs';", $main);
}

if (file_put_contents($mainPath, $main) === false) {
    fwrite(STDERR, "nativephp_interpreter_into_macos: could not write {$mainPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_interpreter_into_macos: the store lane carries its interpreter in Contents/MacOS, and the shell looks there first.\n");

exit(0);
