<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Give the bundle copy a timeout that fits a real machine.
 *
 * `BundleFileManager::copy()` shells out to rsync through `Process::run()`
 * with no timeout, so it inherits Laravel's 60s default. The copy is not
 * incremental — `File::cleanDirectory($destination)` runs first — so every
 * build pays the full cost of ~16k files, and the destination cannot be
 * pre-warmed to duck under the limit.
 *
 * On an idle machine that copy takes ~7s and the ceiling never shows. Under
 * load it does not degrade gracefully: measured at 140s while another test
 * suite was saturating the disk, i.e. 2.3x over, which no exclusion list can
 * close. The build then dies on "Copying Laravel source ... FAIL" having
 * ALREADY uninstalled the app from the device, so a loaded machine leaves the
 * phone with no app at all.
 *
 * 15 minutes is not a target, it is a ceiling high enough that only a genuine
 * hang trips it. A slow copy should make the build slow, never fail it.
 *
 * Patches `mobile-app/vendor/` (composer-managed, wiped by `composer update`),
 * so it is re-applied from post-update-cmd like the generated-source patches
 * beside it. Idempotent; a missing anchor is a hard failure rather than a
 * silent skip, because an unpatched copy fails only under load — exactly when
 * it is least welcome.
 */

const COPY_TIMEOUT_SECONDS = 900;

$vendorRoot = beatraxMobileVendorPath('nativephp/mobile/src') ?? '';

// Every step that shells out on the critical path. The copy inherits
// Laravel's 60s default; the autoloader step declares 60s outright and
// composer install 300s — all sized for an idle machine.
$patches = [
    $vendorRoot.'/Support/BundleFileManager.php' => [
        'Process::run("rsync ' => 'Process::timeout('.COPY_TIMEOUT_SECONDS.')->run("rsync ',
        'Process::run("robocopy ' => 'Process::timeout('.COPY_TIMEOUT_SECONDS.')->run("robocopy ',
    ],
    $vendorRoot.'/Concerns/PreparesBuild.php' => [
        "->timeout(60)\n                    ->run('composer dump-autoload" => '->timeout('.COPY_TIMEOUT_SECONDS.")\n                    ->run('composer dump-autoload",
        '->timeout(300)'."\n                    ->run(\"composer install " => '->timeout('.COPY_TIMEOUT_SECONDS.")\n                    ->run(\"composer install ",
    ],
];

$changed = 0;

foreach ($patches as $target => $replacements) {
    if (! is_file($target)) {
        fwrite(STDERR, "Target not found: {$target}\n");

        exit(1);
    }

    $contents = (string) file_get_contents($target);

    foreach ($replacements as $needle => $replacement) {
        if (str_contains($contents, $replacement)) {
            continue;
        }

        if (! str_contains($contents, $needle)) {
            fwrite(STDERR, 'Anchor not found in '.basename($target).": {$needle}\n");

            exit(1);
        }

        $contents = str_replace($needle, $replacement, $contents);
        $changed++;
    }

    file_put_contents($target, $contents);
}

echo $changed === 0
    ? "Build timeouts already extended; nothing to do.\n"
    : 'Extended build step timeouts to '.COPY_TIMEOUT_SECONDS."s ({$changed} call sites).\n";
