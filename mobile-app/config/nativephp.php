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
     * NSLocalNetworkUsageDescription is REQUIRED for Plan 05's LanSyncClient
     * (15-05-PLAN.md): the phone's outbound amphp/websocket-client dial to
     * the desktop's `sync:serve` listener crosses iOS's Local Network
     * Privacy boundary. 15-SPIKE-FINDINGS.md (Spike A, real iPhone) proved
     * this permission gates the FIRST LAN connection per install — without
     * this string declared, iOS never shows the permission prompt at all
     * and every LAN dial fails permanently. Unlike camera/biometric
     * permissions, this key is NOT auto-provided by any installed plugin
     * and must be declared here.
     */
    'permissions' => [
        'NSLocalNetworkUsageDescription' => 'beatrax uses your local network to sync your finances directly with your other beatrax devices — nothing ever leaves your home network for this.',
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
];
