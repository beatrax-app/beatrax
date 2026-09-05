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

/*
 * The three purpose strings are read from the same tree the twenty-five
 * translations live in rather than written out here. Info.plist carries the base
 * language and <locale>.lproj/InfoPlist.strings carries the rest, so two copies
 * of the same sentence would drift the moment one was reworded — and the drift
 * would show only as a prompt whose English and Dutch say different things.
 *
 * A `require` rather than a config() read: config is what this file is producing,
 * and the same array is read by a build script that runs with no framework.
 *
 * Two candidate paths because Modules/ is not one place. Here it is a symlink to
 * ../Modules, so it resolves through mobile-app/; in a materialized Bifrost tree
 * this root IS the repo root and Modules/ sits beside config/. Probed rather
 * than assumed, the same way every patch script probes for the scaffold.
 *
 * @see ../../scripts/nativephp_ios_purpose_string_localisations.php
 */
$purposeStringsFile = array_values(array_filter([
    __DIR__.'/../Modules/Mobile/Resources/ios/lang/en/purpose-strings.php',
    __DIR__.'/../../Modules/Mobile/Resources/ios/lang/en/purpose-strings.php',
], 'is_file'))[0] ?? null;

/** @var array<string, string> $englishPurposeStrings */
$englishPurposeStrings = $purposeStringsFile === null ? [] : require $purposeStringsFile;

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

    // Null, not env(): a scheme here generates a BROWSABLE, host-unrestricted
    // intent filter, so any web page could hand the app a pairing URI. The QR
    // payload carries the initiator's relay endpoint and pin, adopted before
    // the safety-number gate — pointing a camera is the trust act.
    // `deeplink_host` is absent for the same reason; nothing merges it back.
    'deeplink_scheme' => null,

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
    /*
     * The camera and Face ID strings arrive from mobile-scanner and
     * mobile-biometrics and describe NativePHP's demo app, not this one: one
     * says the app scans barcodes, which it has never done. Both are restated
     * here because IOSPluginCompiler applies app-level entries last, so this
     * is the only place that wins a key collision with a plugin. The
     * biometric-vault plugin already carried a Beatrax Face ID string and
     * silently lost it to mobile-biometrics.
     *
     * LSApplicationCategoryType rides in the same array despite the key's
     * name: getAppInfoPlistOverrides() returns it verbatim into the plist and
     * is documented as app-level Info.plist overrides. App Store Connect
     * refuses a submission with no category.
     */
    'permissions' => $englishPurposeStrings + [
        'LSApplicationCategoryType' => 'public.app-category.finance',
    ],

    /*
     * App-level iOS entitlements, merged into NativePHP.entitlements at build
     * time. `BuildIosAppCommand::updateEntitlementsFile()` rewrites that file
     * from scratch out of deeplink_host + push_notifications + nfc, so an
     * entitlement declared anywhere earlier is discarded; this key is read
     * afterwards by IOSPluginCompiler, patched there by
     * `scripts/nativephp_ios_local_network_discovery.php`.
     *
     * `com.apple.developer.networking.multicast` is what LAN peer discovery
     * needs: MulticastMdnsQuery sends a raw datagram to 224.0.0.251, and since
     * iOS 14 that send is dropped without this entitlement. Neither
     * NSLocalNetworkUsageDescription nor NSBonjourServices covers a BSD socket
     * — they gate NWBrowser and unicast, which is why the prompt appears, the
     * reader grants it, and discovery still returns nothing.
     *
     * Apple grants this entitlement per Team by request, and no provisioning
     * profile for com.beatrax.mobile carries it yet. Declaring it before the
     * grant does not degrade — the build stops at signing with "doesn't
     * include the com.apple.developer.networking.multicast entitlement" — so
     * it stays env-gated and off. LAN discovery on iOS is dead until the grant
     * lands; the desktop and Android are unaffected.
     *
     * @see ../../.docs/features/mobile/ios-lan-discovery-entitlement.md
     */
    'entitlements' => filter_var(env('IOS_MULTICAST_ENTITLEMENT', false), FILTER_VALIDATE_BOOL)
        ? ['com.apple.developer.networking.multicast' => true]
        : [],

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

        // Durable user data belonging to whoever built this. On a device
        // UserDataPathService::appPath() resolves into persisted_data/, so
        // nothing here is ever read — but the bundle was carrying the builder's
        // encrypted device identities and group data keys under sync/, one file
        // per user id they had ever run locally, including a test-suite id.
        // gitignore keeps them out of git; it does not bound a build.
        'storage/app',

        // The Android release signing keystore. release.yml decodes it from a
        // repository secret into credentials/ and only then runs the packager,
        // so it is present in the tree at the moment the bundle is copied. The
        // vendor's own '*.jks' default is anchored to the project root and
        // never matched it one directory down.
        'credentials',

        // iOS signing artifacts, written here by scripts/create-ios-signing.sh.
        // The directory itself is tracked and only its contents are gitignored,
        // which is what kept it out of every check that reasoned about git.
        'build-secrets',

        // Vite's dev-server marker. `public/` is a symlink to the repo root's,
        // so running the desktop dev server writes this file straight into the
        // mobile bundle's view of it — and Laravel then emits every asset URL
        // pointing at http://[::1]:5174, which on a phone is the phone.

        // The result is a shipped app with no CSS and no app.js at all, so the
        // app-lock veil never even loads. Never bundle it.
        'public/hot',
    ],
];
