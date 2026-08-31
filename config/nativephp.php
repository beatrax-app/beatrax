<?php

use Modules\Desktop\Internal\NativeAppServiceProvider;

return [
    'version' => env('NATIVEPHP_APP_VERSION', '0.0.0-dev'),

    'app_id' => env('NATIVEPHP_APP_ID', 'com.beatrax.desktop'),

    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    'author' => env('NATIVEPHP_APP_AUTHOR', 'NightWorks <info@nightworks.io>'),

    'copyright' => env('NATIVEPHP_APP_COPYRIGHT', '© 2026 NightWorks'),

    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'Local-only personal finance dashboard.'),

    'website' => env('NATIVEPHP_APP_WEBSITE', 'https://beatrax.app'),

    'provider' => NativeAppServiceProvider::class,

    // Stripped from the .env the production bundle ships. Wildcards allowed.
    'cleanup_env_keys' => [
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'BIFROST_*',
        // Dev-only: the shipped build runs the database queue + cache lock
        // store, so a stray Redis host would point it at nothing.
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
     * @link ../.docs/features/desktop/build-file-exclusions.md
     */
    'cleanup_exclude_files' => [
        'build',
        'temp',
        'content',
        'node_modules',
        '*/tests',

        // 8,110 files, each of which codesign timestamps over the network.
        '.phpstan-cache',
        '.phpunit.cache',
        '.pint.cache',

        // `*/tests` above needs a real slash to match, so the repo-root
        // tree needs its own entry.
        'tests',

        '.docs',
        '.github',

        // Shipping Vite's dev-server marker points every asset URL at
        // localhost:5173 — an app with no styling and no JavaScript.
        'public/hot',

        // Holds the previous build's own app bundle; without this the copy
        // walker recurses into it and shuffles ~6 GB of stale output.
        'nativephp',

        // Agent worktrees: full independent checkouts, each with its own
        // vendor/ and node_modules/. Six of them reach ~3 GB.
        '.claude',
        // Catches copies nested under vendor/ from earlier builds.
        '*/.claude',

        // By name, never `*/nativephp`: fnmatch spans slashes here, so that
        // would also strip `vendor/nativephp` and the app boots to
        // "Class Native\Desktop\NativeServiceProvider not found".
        'mobile-app',

        // `bootstrap/cache/*.php` is deliberately absent: PackageManifest
        // needs the directory to already exist and will not create it, and
        // package:discover overwrites the stale copies anyway.
    ],

    'updater' => [
        'enabled' => env('NATIVEPHP_UPDATER_ENABLED', true),

        // "github", "s3" or "spaces"; "s3" also covers S3-compatible services
        // such as Cloudflare R2.
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
                // Set this and updates download from here rather than the S3
                // endpoint — a CDN or a custom domain in front of the bucket.
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

    // One worker drains everything: with no Redis in the shipped bundle every
    // job lands on the `database` default queue. NativePHP runs the scheduler
    // in-process, so it needs no entry.
    'queue_workers' => [
        'default' => [
            'queues' => ['default'],
            'memory_limit' => 128,
            // A 60s ceiling killed network-bound inbox scans mid-flight and
            // recorded them as failed. Scan jobs carry their own lower
            // timeout, so they still fail on their own alarm.
            'timeout' => 300,
            'sleep' => 3,
        ],
    ],

    /**
     * @link ../.docs/features/desktop/build-prebuild-hooks.md
     */
    'prebuild' => [
        // Must stay first: the signing hooks below configure paths into
        // the buildResources directory this one populates.
        'php scripts/nativephp_stage_build_resources.php',

        // Pins an explicit mac.identity so the shell and the nested Electron
        // helpers sign under one Team ID; auto-discovery mismatched them on
        // Apple Silicon and notarisation refused the result.
        'php scripts/nativephp_developer_id_signing.php',

        // electron-builder 26 requires `publisherName` in win.azureSignOptions
        // and NativePHP omits it; without this the build aborts in validation.
        'php scripts/nativephp_azure_publisher_name.php',

        // Forces the full-binary update path, which is the one covered by
        // the Ed25519 manifest + SHA-512 check in ElectronUpdateChannel.
        'php scripts/nativephp_inject_macos_update_settings.php',

        // Holds electron-updater at update-available so nothing downloads or
        // installs before the signed manifest is verified and the user agrees.
        'php scripts/nativephp_inject_explicit_consent_updates.php',

        // The published template declares only the bare `#plugin` entry, so
        // `#plugin/*` subpath imports abort the Vite/Rollup build.
        'php scripts/nativephp_patch_electron_imports.php',

        // Replaces NativePHP's MenuBar facade, whose tray items bind to the
        // focused BrowserWindow: after the window is closed nothing is
        // focused and the tray's open item silently did nothing.
        'php scripts/nativephp_inject_persistent_tray.php',

        // Without a fileAssociations block no OS routes a .csv or .eml here,
        // so macOS never fires open-file and no argv ever carries a document:
        // the whole FileOpenIntake path was unreachable on every platform.
        'php scripts/nativephp_inject_file_associations.php',

        // The other half of the same gap: Windows and Linux deliver the path
        // on argv and through second-instance, neither of which NativePHP
        // forwards as a document.
        'php scripts/nativephp_inject_file_open_ingress.php',

        // The yauzl streaming extractor inflates the arm64 Mach-O by ~5 MB
        // and codesign then rejects it ("main executable failed strict
        // validation"); ditto/unzip keeps the binary byte-exact.
        'php scripts/nativephp_fix_php_binary_extraction.php',
    ],

    'postbuild' => [
    ],

    /**
     * @see https://www.electron.build/generated/nsisoptions
     */
    'nsis' => [
        'delete_app_data_on_uninstall' => env('NATIVEPHP_NSIS_DELETE_APP_DATA', false),
    ],

    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH', null),
];
