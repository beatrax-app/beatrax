#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runs every NativePHP prebuild patch and reports all of them.
 *
 * Composer aborts a script list on the first non-zero exit, so listing the
 * patches directly under `native:patch` meant one failure silently skipped
 * every patch after it. That is not hypothetical: the permission-stripping
 * script exits 1 while the Android manifest is still in its generated state,
 * which sits fifth of fourteen — so a fresh checkout ran two thirds of the
 * patches, exited non-zero, and left the iOS ones unapplied. The build then
 * succeeded, because none of the skipped patches is load-bearing at compile
 * time; they only change what ships.
 *
 * Each patch is idempotent and guards its own state, so a later one running
 * after an earlier failure is safe — it applies or explains why it cannot.
 *
 * It is also the one list. `native:patch` and `post-update-cmd` each carried
 * their own copy and had drifted apart: the boot splash and the bundle-copy
 * timeout ran on composer update and not when a developer patched by hand.
 *
 * @link ../.docs/features/mobile/architecture.md
 */
$patches = [
    'nativephp_grant_webview_camera',
    'nativephp_android_file_chooser',
    'nativephp_keep_webview_cookies',
    'nativephp_exclude_data_from_backup',
    'nativephp_strip_unused_permissions',
    'nativephp_theme_native_shell',
    'nativephp_brand_boot_splash',
    'nativephp_android_adaptive_icon',
    'nativephp_ios_app_icon',
    'nativephp_extend_bundle_copy_timeout',
    'nativephp_ios_request_body_stream',
    'nativephp_ios_download_delegate',
    'nativephp_ios_privacy_manifest',
    'nativephp_ios_export_compliance',
];

$failed = [];

foreach ($patches as $patch) {
    $script = __DIR__.'/'.$patch.'.php';

    if (! is_file($script)) {
        fwrite(STDERR, "nativephp_patch_all: {$patch}.php is missing.\n");
        $failed[] = $patch;

        continue;
    }

    $status = 0;
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), $status);

    if ($status !== 0) {
        $failed[] = $patch;
    }
}

if ($failed !== []) {
    fwrite(STDERR, sprintf(
        "\nnativephp_patch_all: %d of %d patches failed and the rest still ran:\n  %s\n",
        count($failed),
        count($patches),
        implode("\n  ", $failed),
    ));

    exit(1);
}

echo sprintf("nativephp_patch_all: all %d patches applied.\n", count($patches));
