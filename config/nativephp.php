<?php

use Modules\Desktop\Internal\NativeAppServiceProvider;

return [
    /**
     * The version of your app.
     * It is used to determine if the app needs to be updated.
     * Increment this value every time you release a new version of your app.
     */
    'version' => env('NATIVEPHP_APP_VERSION', '0.0.0-dev'),

    /**
     * The ID of your application. This should be a unique identifier
     * usually in the form of a reverse domain name.
     * For example: com.nativephp.app
     */
    'app_id' => env('NATIVEPHP_APP_ID', 'com.nativephp.app'),

    /**
     * If your application allows deep linking, you can specify the scheme
     * to use here. This is the scheme that will be used to open your
     * application from within other applications.
     * For example: "nativephp"
     *
     * This would allow you to open your application using a URL like:
     * nativephp://some/path
     */
    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    /**
     * The author of your application.
     */
    'author' => env('NATIVEPHP_APP_AUTHOR'),

    /**
     * The copyright notice for your application.
     */
    'copyright' => env('NATIVEPHP_APP_COPYRIGHT'),

    /**
     * The description of your application.
     */
    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'An awesome app built with NativePHP'),

    /**
     * The Website of your application.
     */
    'website' => env('NATIVEPHP_APP_WEBSITE', 'https://nativephp.com'),

    /**
     * The default service provider for your application. This provider
     * takes care of bootstrapping your application and configuring
     * any global hotkeys, menus, windows, etc.
     */
    'provider' => NativeAppServiceProvider::class,

    /**
     * A list of environment keys that should be removed from the
     * .env file when the application is bundled for production.
     * You may use wildcards to match multiple keys.
     */
    'cleanup_env_keys' => [
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'BIFROST_*',
        // Dev-only keys that must never reach the shipped bundle: the
        // developer-mode flag and any Redis connection settings (the
        // shipped build runs the database queue + cache lock store).
        'BEATRAX_DEV_MODE',
        'REDIS_*',
        'NATIVEPHP_UPDATER_PATH',
        'NATIVEPHP_APPLE_ID',
        'NATIVEPHP_APPLE_ID_PASS',
        'NATIVEPHP_APPLE_TEAM_ID',
        'NATIVEPHP_MAC_IDENTITY',
        'NATIVEPHP_AZURE_PUBLISHER_NAME',
        'NATIVEPHP_AZURE_ENDPOINT',
        'NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME',
        'NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME',
    ],

    /**
     * A list of files and folders that should be removed from the
     * final app before it is bundled for production.
     * You may use glob / wildcard patterns here.
     */
    'cleanup_exclude_files' => [
        'build',
        'temp',
        'content',
        'node_modules',
        '*/tests',

        // The toolchain's own scratch space, which has no business in a
        // shipped app: 194 MB across 8,110 files, a third of everything in
        // the bundle. Cost is not the disk — it is that codesign runs
        // per-file with --timestamp, and every one of those files bought its
        // own network round-trip to Apple's timestamp server. The build spent
        // the bulk of half an hour notarizing analysis caches.
        '.phpstan-cache',
        '.phpunit.cache',
        '.pint.cache',

        // The repo-root `tests/` tree. `*/tests` above catches every nested
        // one, but fnmatch needs that slash to match, so the top-level
        // directory the pattern was plainly aimed at slipped through.
        'tests',

        // Contributor-facing material, not runtime: the repo docs the
        // `@link` docblocks point at, and the CI workflows.
        '.docs',
        '.github',

        // Vite's dev-server marker, written whenever `npm run dev` is up.
        // Bundling it makes Laravel emit every asset URL against
        // http://localhost:5173 — an app that ships with no styling and no
        // JavaScript, on whichever machine happens to open it.
        'public/hot',

        // The `nativephp/` working dir at the project root holds every
        // prior `php artisan native:build` output (the full Electron
        // distribution under `nativephp/electron/dist/<arch>/beatrax.app/`).
        // Without this exclude, the copy walker descends into the
        // previous build's app bundle — a near-complete recursive copy
        // of the project — and the build appears to hang while it
        // shuffles ~6 GB of stale electron output into the new build
        // directory. Gitignored at the repo level for the same reason;
        // mirror that here so the copy step skips it too.
        'nativephp',

        // Claude Code worktrees. The `.claude/worktrees/agent-*/`
        // directories are independent git worktrees of the full repo —
        // each one carries its OWN `vendor/`, `nativephp/`, and
        // `node_modules/` tree. Six concurrent agent worktrees can
        // easily reach 3 GB and the copy walker has no reason to fold
        // them into the production bundle.
        '.claude',
        // Belt-and-braces: also drop any `.claude/` that ended up
        // nested inside `vendor/nativephp/desktop/resources/build/app/`
        // from a previous build attempt that ran without this exclude
        // in place. PHP's fnmatch defaults to no FNM_PATHNAME, so `*`
        // matches across slashes — `*/.claude` matches `vendor/.claude`,
        // `vendor/nativephp/desktop/resources/build/app/.claude`, etc.
        '*/.claude',

        // The `mobile-app/` sibling shell is the NativePHP MOBILE app (a
        // separate root that symlinks the shared `Modules/`). It is tracked
        // inside this repo but the DESKTOP bundle never needs it — and it
        // carries ~1.1 GB of iOS/Android native build artifacts under
        // `mobile-app/nativephp/{ios,android}/build/` (Xcode DerivedData,
        // SwiftPM checkouts). The top-level `nativephp` exclude above does
        // NOT catch `mobile-app/nativephp` (fnmatch has no wildcard there),
        // so without this the copy walker folds the whole mobile shell into
        // the desktop bundle and codesign then aborts on a locked-perms
        // SwiftPM test fixture (ZIPFoundation's read-only .zip). Exclude the
        // shell outright. (Do NOT add `*/nativephp` here — fnmatch has no
        // FNM_PATHNAME so `*` spans slashes and it would also match
        // `vendor/nativephp`, stripping the NativePHP desktop package
        // itself → "Class Native\Desktop\NativeServiceProvider not found".)
        'mobile-app',

        // NOTE: `bootstrap/cache/*.php` are intentionally NOT excluded here.
        // Laravel's PackageManifest requires `bootstrap/cache/` to already
        // exist and be writable -- it does not create the directory. The
        // desktop build copies the working tree, then runs `composer
        // install --no-dev` (with scripts enabled). The `post-autoload-dump`
        // script in composer.json runs `package:discover`, which OVERWRITES
        // the copied caches with manifests built against the bundle's own
        // --no-dev vendor tree. Letting the (stale) caches copy in normally
        // guarantees the directory exists for that regeneration step.
    ],

    /**
     * The NativePHP updater configuration.
     */
    'updater' => [
        /**
         * Whether or not the updater is enabled. Please note that the
         * updater will only work when your application is bundled
         * for production.
         */
        'enabled' => env('NATIVEPHP_UPDATER_ENABLED', true),

        /**
         * The updater provider to use.
         * Supported: "github", "s3", "spaces"
         * Note: The "s3" provider is compatible with S3-compatible services like Cloudflare R2.
         */
        'default' => env('NATIVEPHP_UPDATER_PROVIDER', 'spaces'),

        'providers' => [
            'github' => [
                'driver' => 'github',
                'repo' => env('GITHUB_REPO'),
                'owner' => env('GITHUB_OWNER'),
                'token' => env('GITHUB_TOKEN'),
                'vPrefixedTagName' => env('GITHUB_V_PREFIXED_TAG_NAME', true),
                'private' => env('GITHUB_PRIVATE', false),
                'autoupdate_token' => env('GITHUB_AUTOUPDATE_TOKEN'), // Read-only token used by the updater for private repos
                'channel' => env('GITHUB_CHANNEL', 'latest'),
                'releaseType' => env('GITHUB_RELEASE_TYPE', 'draft'),
            ],

            's3' => [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
                /**
                 * Optional public URL for serving updates (e.g., CDN or custom domain).
                 * When set, updates will be downloaded from this URL instead of the S3 endpoint.
                 * Useful for S3 with CloudFront or Cloudflare R2 with public access
                 * Example: 'https://updates.yourdomain.com'
                 */
                'public_url' => env('AWS_PUBLIC_URL'),
            ],

            'spaces' => [
                'driver' => 'spaces',
                'key' => env('DO_SPACES_KEY_ID'),
                'secret' => env('DO_SPACES_SECRET_ACCESS_KEY'),
                'name' => env('DO_SPACES_NAME'),
                'region' => env('DO_SPACES_REGION'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],
        ],
    ],

    /**
     * Queue workers auto-started by the NativePHP shell (D-05).
     *
     * Each entry under `queue_workers` is spawned by the Electron main
     * process as a persistent child process running `queue:work`. The
     * NativePHP supervisor restarts the worker automatically on a
     * single ProcessExited; a sustained crash-loop is escalated via the
     * `SurfaceWorkerCrashAlert` listener (D-07) into a critical
     * `system_alerts` row + OS notification.
     *
     * The single `default` worker drains every queued job dispatched
     * through the `database` queue driver — the shipped bundle does
     * NOT ship Redis (Phase 14 carve-out). Sizing matches the dev-box
     * defaults: memory cap 128 MB, single-job timeout 60 s, 3 s sleep
     * between empty-queue polls. The Laravel scheduler runs
     * automatically inside a NativePHP app per RESEARCH.md Pattern 2
     * — no extra ChildProcess and no `schedule:work` entry is needed.
     */
    'queue_workers' => [
        'default' => [
            'queues' => ['default'],
            'memory_limit' => 128,
            // 60s was a CPU-bound figure on a queue whose slowest jobs are
            // network-bound: an inbox scan pages the Gmail/Graph API over the
            // wire and routinely needs longer, so the worker killed it
            // mid-flight and the run was recorded as failed. The scan jobs
            // carry their own, lower timeout so they fail cleanly on their
            // own alarm rather than being cut off by this ceiling.
            'timeout' => 300,
            'sleep' => 3,
        ],
    ],

    /**
     * Define your own scripts to run before and after the build process.
     */
    'prebuild' => [
        // Stage committed brand icons + the macOS Hardened Runtime
        // entitlements file into the electron-builder `buildResources`
        // directory. The `nativephp/` working dir is gitignored, so the
        // canonical icons (`public/icon.*`) and the entitlements file
        // (`build/entitlements.mac.plist`) must be copied in on every
        // build for electron-builder to discover them.
        'php scripts/nativephp_stage_build_resources.php',

        // Real Developer ID macOS code signing. Pins an EXPLICIT
        // `mac.identity` (from NATIVEPHP_MAC_IDENTITY) + Hardened Runtime so
        // electron-builder signs the whole bundle — shell AND nested Electron
        // Framework/helpers — with one identity/Team ID. This both enables
        // notarization (build/notarize.js afterSign) and avoids the Team ID
        // mismatch that ad-hoc auto-discovery caused on Apple Silicon.
        // (Replaces the earlier nativephp_force_adhoc_signing.php, kept in the
        // repo for local unsigned dev builds.)
        'php scripts/nativephp_developer_id_signing.php',

        // Bypass electron-updater's OS-signature differential-download
        // validation on macOS by setting `autoUpdater.disableDifferential
        // Download = true` in the compiled Electron main-process JS.
        // The bundle is ad-hoc signed (the hook above), so the
        // differential path's OS-signature check fails and the auto-
        // update aborts; the full-binary path retains SHA-512 binary
        // verification driven by ElectronUpdateChannel, so end-to-end
        // integrity is preserved.
        'php scripts/nativephp_inject_macos_update_settings.php',

        // Hold electron-updater at update-available (autoDownload=false,
        // autoInstallOnAppQuit=false) so nothing downloads or installs until
        // the Ed25519-signed manifest is verified and the user consents.
        'php scripts/nativephp_inject_explicit_consent_updates.php',

        // Expose `#plugin/*` in the electron package.json `imports` map so
        // subpath imports like `#plugin/server/state.js` resolve under Node
        // subpath imports. The NativePHP-published template only declares the
        // bare `#plugin` entry, which makes the Vite / Rollup build abort.
        'php scripts/nativephp_patch_electron_imports.php',

        // Inject a persistent macOS menu-bar tray (D-09) directly into the
        // Electron main process. Replaces NativePHP's `MenuBar` facade,
        // which wraps the popover-style npm `menubar` library and couples
        // tray menu items to the focused BrowserWindow — once the user
        // closes the main window via the X button, the tray's
        // "Open beatrax" item silently does nothing under that paradigm.
        // The injected block creates a native Electron `Tray` with a
        // template-flagged icon and a context menu whose handlers either
        // show + focus the existing main window OR post to
        // `/api/window/open` to re-construct it from any state.
        'php scripts/nativephp_inject_persistent_tray.php',

        // Patch NativePHP's `php.js` so electron-builder's beforePack
        // extracts the bundled static PHP binary BYTE-EXACT (ditto/unzip
        // instead of the yauzl streaming pipe, which inflates the arm64
        // Mach-O ~5 MB and makes codesign reject it: "main executable failed
        // strict validation"). A hard precondition for Developer ID signing
        // + notarization; idempotent and reapplied on every build.
        'php scripts/nativephp_fix_php_binary_extraction.php',
    ],

    'postbuild' => [
        // 'rm -rf public/build',
    ],

    /**
     * The NSIS installer configuration for Windows builds.
     *
     * @see https://www.electron.build/generated/nsisoptions
     */
    'nsis' => [
        'delete_app_data_on_uninstall' => env('NATIVEPHP_NSIS_DELETE_APP_DATA', false),
    ],

    /**
     * Custom PHP binary path.
     */
    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH', null),
];
