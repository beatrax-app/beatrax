<?php

declare(strict_types=1);

/*
 * Mobile-app-specific NativePHP config.
 *
 * Deliberately minimal. `nativephp/mobile` merges its own config shallowly, so
 * every key absent here — runtime mode, the Android SDK/build block, the dev
 * server, hot reload — resolves from the package default, and only the keys
 * that genuinely differ are restated. Everything the desktop root's config
 * carries (Electron updater providers, prebuild hooks, queue workers, NSIS
 * installer options) is desktop-only: the mobile shell ships via
 * `nativephp/mobile` (Xcode / Android Studio), not electron-builder.
 *
 * There is no `provider` key. One used to live here pointing at
 * `Modules\Mobile\Internal\NativeMobileAppServiceProvider`, but
 * `config('nativephp.provider')` is read by `nativephp/desktop` alone —
 * `nativephp/mobile` has never consulted it, in v3 or v4 — so it booted
 * nothing. That provider is now invoked from `bootstrap/app.php`'s `->booted()`
 * hook, the same on-device attach point the storage reconciliation uses.
 *
 * Topology note: desktop and mobile cannot share one Composer
 * `vendor/` tree because `nativephp/desktop` declares
 * `"conflict": {"nativephp/mobile": "*"}`. This file lives under the sibling
 * `mobile-app/` root, which has its OWN `vendor/` (with `nativephp/mobile`) and
 * consumes the shared `Modules/*` / `app/` / `resources/` / `database/` /
 * `routes/` via symlinks — one shared domain codebase, two app shells.
 */

return [
    // 'DEBUG' is not a placeholder: the shells treat that exact string as
    // "always re-extract the PHP bundle on launch". Without it a dev build
    // ships a new binary over the OLD PHP code, because the update gate
    // compares version+build and both are pinned — which presented as an
    // unexplainable whole-app 404 that survived restarts.
    // FILTER_VALIDATE_BOOL, not truthiness: env() types only the literal
    // true/false/null spellings, so `off` and `no` arrived as non-empty
    // strings and shipped a release build whose version was DEBUG.
    'version' => filter_var(env('NATIVEPHP_DEBUG_BUNDLE', false), FILTER_VALIDATE_BOOL)
        ? 'DEBUG'
        : env('NATIVEPHP_APP_VERSION', '0.0.0-dev'),

    'app_id' => env('NATIVEPHP_APP_ID', 'com.beatrax.mobile'),

    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    'author' => env('NATIVEPHP_APP_AUTHOR'),

    'copyright' => env('NATIVEPHP_APP_COPYRIGHT'),

    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'Beatrax mobile peer'),

    'website' => env('NATIVEPHP_APP_WEBSITE', 'https://beatrax.test'),

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
        // Code-signing credentials are BUILD-TIME ONLY (keytool/gradle read
        // them on the host). They must NEVER ship inside the APK/IPA .env —
        // the keystore passwords especially would hand an attacker the
        // release-signing key. Strip every signing var from the bundled env.
        'ANDROID_KEYSTORE_*',
        'ANDROID_KEY_*',
        'APP_STORE_API_*',
        'IOS_TEAM_ID',
    ],

    /*
     * iOS Info.plist permission usage strings (nativephp/mobile's
     * 'permissions' override array — see
     * mobile-app/vendor/nativephp/mobile/config/nativephp.php for the
     * shape this mirrors).
     *
     * NSLocalNetworkUsageDescription is REQUIRED for the LAN sync client:
     * the phone's outbound amphp/websocket-client dial to
     * the desktop's `sync:serve` listener crosses iOS's Local Network
     * Privacy boundary. A real-iPhone spike proved
     * this permission gates the FIRST LAN connection per install — without
     * this string declared, iOS never shows the permission prompt at all
     * and every LAN dial fails permanently. Unlike camera/biometric
     * permissions, this key is NOT auto-provided by any installed plugin
     * and must be declared here.
     */
    'permissions' => [
        'NSLocalNetworkUsageDescription' => 'Beatrax uses your local network to sync your finances directly with your other Beatrax devices — nothing ever leaves your home network for this.',
    ],

    /*
     * iOS signing is split between local development and cloud distribution.
     *
     * LOCAL DEV (kept): `development_team` feeds Xcode AUTOMATIC signing so
     * `native:run` / `native:build ios` can sign a *development* build for an
     * on-device run ({@see PackagesIos::teamId}, {@see InstallsIos}). The Team
     * ID is non-secret, comes from `IOS_TEAM_ID` in the git-ignored env, and is
     * stripped from the bundled .env by `cleanup_env_keys` above. This must
     * stay wired or on-device dev runs can no longer sign.
     *
     * DISTRIBUTION (moved to Bifrost): App Store builds + signing now run in
     * NativePHP's Bifrost cloud, which signs with the distribution certificate
     * + provisioning profile uploaded to its Credentials → iOS panel (minted by
     * `mobile-app/scripts/create-ios-signing.sh`). Bifrost builds from uploaded
     * credentials, NOT from this repo's config, so the local App Store Connect
     * API-key wiring that drove headless `native:package ios
     * --export-method=app-store` has been retired from here. The
     * `APP_STORE_API_*` strip rules stay in `cleanup_env_keys` as
     * defense-in-depth for any stale local env values.
     */
    'development_team' => env('IOS_TEAM_ID'),

    /*
     * Deliberately NOT trimming `vendor/` here.
     *
     * Stripping the require-dev packages (phpunit, pestphp, myclabs, …) looks
     * free — no on-device path reaches a test runner — but the bundle ships a
     * composer classmap generated from the FULL vendor tree, so the autoloader
     * still resolves those paths and the app dies on boot with
     * `require(.../myclabs/deep-copy/...): No such file or directory`.
     *
     * Any future trim has to regenerate the autoloader after the exclusion,
     * not before. Until then the storage entries below are the package
     * defaults, restated because declaring this key replaces them wholesale.
     */
    'cleanup_exclude_files' => [
        'storage/framework/sessions',
        'storage/framework/cache',
        'storage/framework/testing',
        'storage/logs/laravel.log',

        // Vite's dev-server marker. `public/` is a symlink to the repo root's,
        // so running the desktop dev server writes this file straight into the
        // mobile bundle's view of it — and Laravel then emits every asset URL
        // pointing at http://[::1]:5174, which on a phone is the phone.

        // The result is a shipped app with no CSS and no app.js at all, so the
        // app-lock veil never even loads. Never bundle it.
        'public/hot',
    ],
];
