<?php

use Modules\Desktop\Internal\NativeAppServiceProvider;

return [
    /**
     * The version of your app.
     * It is used to determine if the app needs to be updated.
     * Increment this value every time you release a new version of your app.
     */
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),

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
        'DIEDERIK_DEV_MODE',
        'REDIS_*',
        'NATIVEPHP_UPDATER_PATH',
        'NATIVEPHP_APPLE_ID',
        'NATIVEPHP_APPLE_ID_PASS',
        'NATIVEPHP_APPLE_TEAM_ID',
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
            'timeout' => 60,
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

        // Force deterministic ad-hoc macOS code signing. Without an explicit
        // `mac.identity`, electron-builder auto-discovers a keychain identity
        // and can partially sign the bundle, producing a Team ID mismatch
        // between the app shell and the nested Electron Framework that aborts
        // launch on Apple Silicon.
        'php scripts/nativephp_force_adhoc_signing.php',
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
