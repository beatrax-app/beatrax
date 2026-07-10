<?php

declare(strict_types=1);

use Modules\Mobile\Internal\NativeMobileAppServiceProvider;

/*
 * Mobile-app-specific NativePHP config.
 *
 * This is the ONLY NativePHP config that MUST differ from the desktop root's
 * `config/nativephp.php`: the `provider` key points at the mobile-native boot
 * provider (`Modules\Mobile\Internal\NativeMobileAppServiceProvider`) instead
 * of the desktop `NativeAppServiceProvider`. Everything else that the desktop
 * config carries (Electron updater providers, prebuild hooks, queue workers,
 * NSIS installer options) is desktop-only and intentionally omitted here — the
 * mobile shell ships via `nativephp/mobile` (Xcode / Android Studio), not
 * electron-builder, so those keys have no mobile analog.
 *
 * Phase 15 topology note (15-02): desktop and mobile cannot share one Composer
 * `vendor/` tree because `nativephp/desktop` declares
 * `"conflict": {"nativephp/mobile": "*"}`. This file lives under the sibling
 * `mobile-app/` root, which has its OWN `vendor/` (with `nativephp/mobile`) and
 * consumes the shared `Modules/*` / `app/` / `resources/` / `database/` /
 * `routes/` via symlinks — one shared domain codebase, two app shells.
 */

return [
    'version' => env('NATIVEPHP_APP_VERSION', '0.0.0-dev'),

    'app_id' => env('NATIVEPHP_APP_ID', 'com.beatrax.mobile'),

    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    'author' => env('NATIVEPHP_APP_AUTHOR'),

    'copyright' => env('NATIVEPHP_APP_COPYRIGHT'),

    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'beatrax mobile peer'),

    'website' => env('NATIVEPHP_APP_WEBSITE', 'https://beatrax.test'),

    /*
     * The mobile-native boot provider. Unlike the desktop analog this is NOT a
     * long-lived-listener host: iOS/Android forbid always-on background
     * sockets, so the phone is an OUTBOUND CLIENT ONLY (bounded dial-out
     * bursts). See the NativeMobileAppServiceProvider class docblock.
     */
    'provider' => NativeMobileAppServiceProvider::class,

    /*
     * Keys stripped from the bundled .env for a production mobile build.
     * Mirrors the desktop config's secret-hygiene list.
     */
    'cleanup_env_keys' => [
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'BIFROST_*',
        'BEATRAX_DEV_MODE',
        'REDIS_*',
    ],
];
