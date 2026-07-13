<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

/*
 * 15-04 Task 1 — mobile-signal coverage for UserDataPathService.
 *
 * Per 15-SPIKE-FINDINGS.md (Spike B, run on a real iPhone): NATIVEPHP_STORAGE_PATH
 * stays UNSET on-device. NativePHP mobile instead retargets base_path() itself
 * into the app-sandbox container (`…/Documents/app`), so most UserDataPathService
 * accessors (which derive from base_path()) already resolve inside the sandbox
 * with NO dedicated mobile code path. The reliable on-device signal is
 * NATIVEPHP_PLATFORM (`ios`/`android`); this file pins:
 *
 *  - platform() reads NATIVEPHP_PLATFORM via getenv(), returning null when unset.
 *  - storageBase() does NOT branch on NATIVEPHP_PLATFORM alone (NATIVEPHP_STORAGE_PATH
 *    absent) — it stays derived from base_path(), identical to the unsignaled case.
 *  - databaseFile() DOES branch on NATIVEPHP_PLATFORM (device-UAT fix, post-Spike-B):
 *    the sandboxed base_path() is the NativePHP app BUNDLE — wiped and re-shipped on
 *    every app update, and shipping a populated dev database.sqlite (the
 *    `/database/database.sqlite` build-exclude does not match through the
 *    `mobile-app/database -> ../database` symlink). Using it would leak dev/financial
 *    data into every install and keep a fresh phone from ever being empty. On the
 *    mobile runtime (`platform() !== null`), databaseFile() instead targets the
 *    NativePHP PERSISTED store — a sibling of the bundle root, empty on a genuine
 *    fresh install and retained across app updates.
 *  - With base_path() relocated to a sandbox-like directory (simulating what
 *    NativePHP mobile does on-device) AND NATIVEPHP_PLATFORM present,
 *    storageBase()/secretsPath()/backupsPath() resolve under the relocated
 *    (bundle) root as before, while databaseFile() resolves under the sibling
 *    persisted-data root instead — the mobile (NATIVEPHP_PLATFORM-present,
 *    NATIVEPHP_STORAGE_PATH-absent) case.
 *  - appPath() traversal guard still rejects `..` segments under a relocated
 *    base_path().
 *  - No native signal at all (plain web/test) behaves exactly as before
 *    (regression) — databaseFile() stays base_path()-derived on desktop/host.
 */

beforeEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_PLATFORM');
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_PLATFORM');
});

it('platform() reads NATIVEPHP_PLATFORM via getenv, returning null when unset', function (): void {
    expect(UserDataPathService::platform())->toBeNull();

    putenv('NATIVEPHP_PLATFORM=ios');
    expect(UserDataPathService::platform())->toBe('ios');

    putenv('NATIVEPHP_PLATFORM=android');
    expect(UserDataPathService::platform())->toBe('android');
});

it('storageBase() does not branch on NATIVEPHP_PLATFORM alone, but databaseFile() DOES branch to the persisted store the moment the mobile signal is present (NATIVEPHP_STORAGE_PATH absent)', function (): void {
    $unsignaledDatabaseFile = UserDataPathService::databaseFile();
    $unsignaledStorageBase = UserDataPathService::storageBase();

    putenv('NATIVEPHP_PLATFORM=ios');

    expect(UserDataPathService::storageBase())->toBe($unsignaledStorageBase);
    expect(UserDataPathService::storageBase())->toBe(storage_path());

    // databaseFile() branches on platform() alone — it no longer matches the
    // unsignaled (base_path()-derived) value once NATIVEPHP_PLATFORM is set.
    expect(UserDataPathService::databaseFile())->not->toBe($unsignaledDatabaseFile);
    expect(UserDataPathService::databaseFile())
        ->toBe(dirname(base_path()).DIRECTORY_SEPARATOR.'persisted_data'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
});

it('resolves databaseFile() under the sibling persisted-data root (not the bundle) while storage/secrets/backups stay bundle-rooted, under a relocated base_path() with NATIVEPHP_PLATFORM present and NATIVEPHP_STORAGE_PATH absent (the iOS reality)', function (): void {
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-sandbox-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700, true);

    $originalBasePath = $this->app->basePath();
    putenv('NATIVEPHP_PLATFORM=ios');

    try {
        $this->app->setBasePath($sandbox);

        expect(UserDataPathService::databaseFile())
            ->toBe(dirname($sandbox).DIRECTORY_SEPARATOR.'persisted_data'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
        expect(UserDataPathService::storageBase())
            ->toBe($sandbox.DIRECTORY_SEPARATOR.'storage');
        expect(UserDataPathService::secretsPath())
            ->toBe($sandbox.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'secrets');
        expect(UserDataPathService::backupsPath())
            ->toBe($sandbox.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups');
    } finally {
        $this->app->setBasePath($originalBasePath);
    }
});

it('databaseFile() targets the persisted store on the mobile runtime, distinct from base_path()/database, so a re-shipped bundle DB is never used', function (): void {
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-sandbox-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700, true);

    $originalBasePath = $this->app->basePath();
    putenv('NATIVEPHP_PLATFORM=android');

    try {
        $this->app->setBasePath($sandbox);

        $bundleDatabaseFile = $sandbox.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';
        $persistedDatabaseFile = dirname($sandbox).DIRECTORY_SEPARATOR.'persisted_data'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';

        expect(UserDataPathService::databaseFile())
            ->not->toBe($bundleDatabaseFile)
            ->toBe($persistedDatabaseFile);

        // The persisted store lives OUTSIDE the bundle root entirely — a
        // rsync-wipe-and-re-ship of the bundle on app update cannot touch it.
        expect(str_starts_with($persistedDatabaseFile, $sandbox))->toBeFalse();
    } finally {
        $this->app->setBasePath($originalBasePath);
    }
});

it('rejects a path-traversal segment in appPath() under a relocated base_path() (regression)', function (): void {
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-sandbox-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700, true);

    $originalBasePath = $this->app->basePath();
    putenv('NATIVEPHP_PLATFORM=ios');

    try {
        $this->app->setBasePath($sandbox);

        expect(fn (): string => UserDataPathService::appPath('../../etc/passwd'))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        $this->app->setBasePath($originalBasePath);
    }
});

it('behaves unchanged with no native signal at all (plain web/test regression)', function (): void {
    expect(UserDataPathService::platform())->toBeNull();
    expect(UserDataPathService::databaseFile())->toBe(base_path('database/database.sqlite'));
});

it('detects the mobile runtime STRUCTURALLY when NATIVEPHP_PLATFORM is invisible at config-load — a sibling persisted_data store next to base_path routes databaseFile() to it', function (): void {
    // Mirrors the persistent-runtime reality: getenv('NATIVEPHP_PLATFORM')
    // reads null when config/database.php re-evaluates per request, so the
    // env-only branch silently fell back to the app-bundle DB path on-device.
    // The structural fallback keys off the NativePHP mobile layout — a
    // `<app_storage>/laravel` base_path with a sibling `persisted_data` store.
    $appStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-appstorage-'.bin2hex(random_bytes(8));
    $bundleRoot = $appStorage.DIRECTORY_SEPARATOR.'laravel';
    $persistedDir = $appStorage.DIRECTORY_SEPARATOR.'persisted_data';
    mkdir($bundleRoot, 0700, true);
    mkdir($persistedDir, 0700, true);

    $originalBasePath = $this->app->basePath();
    putenv('NATIVEPHP_PLATFORM'); // explicitly UNSET — no env signal at all

    try {
        $this->app->setBasePath($bundleRoot);

        expect(UserDataPathService::platform())->toBeNull();
        expect(UserDataPathService::databaseFile())
            ->toBe($persistedDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
    } finally {
        $this->app->setBasePath($originalBasePath);
    }
});
