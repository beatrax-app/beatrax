<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\MobilePlatform;
use Modules\Core\Public\Services\UserDataPathService;

beforeEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_PLATFORM');
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_PLATFORM');
});

it('platform() reads NATIVEPHP_PLATFORM via getenv as a MobilePlatform, returning null when unset', function (): void {
    expect(UserDataPathService::platform())->toBeNull();

    putenv('NATIVEPHP_PLATFORM=ios');
    expect(UserDataPathService::platform())->toBe(MobilePlatform::Ios);

    putenv('NATIVEPHP_PLATFORM=android');
    expect(UserDataPathService::platform())->toBe(MobilePlatform::Android);
});

it('platform() reads a shell NativePHP names but this app does not model as null, while isMobileRuntime() still routes its user data to the persisted store', function (): void {
    putenv('NATIVEPHP_PLATFORM=harmonyos');

    expect(UserDataPathService::platform())->toBeNull()
        ->and(UserDataPathService::isMobileRuntime())->toBeTrue();
});

it('only the Android shell needs the redirect rewritten on the client', function (): void {
    expect(MobilePlatform::Android->needsClientSideRedirect())->toBeTrue()
        ->and(MobilePlatform::Ios->needsClientSideRedirect())->toBeFalse();
});

it('storageBase() does not branch on NATIVEPHP_PLATFORM alone, but databaseFile() DOES branch to the persisted store the moment the mobile signal is present (NATIVEPHP_STORAGE_PATH absent)', function (): void {
    $unsignaledDatabaseFile = UserDataPathService::databaseFile();
    $unsignaledStorageBase = UserDataPathService::storageBase();

    putenv('NATIVEPHP_PLATFORM=ios');

    expect(UserDataPathService::storageBase())->toBe($unsignaledStorageBase);
    // base_path(), not storage_path(): storage_path() is relocated per test,
    // so only base_path() pins the unset-env fallback of projectRoot()/storage.
    expect(UserDataPathService::storageBase())->toBe(base_path('storage'));

    expect(UserDataPathService::databaseFile())->not->toBe($unsignaledDatabaseFile);
    expect(UserDataPathService::databaseFile())
        ->toBe(dirname(base_path()).DIRECTORY_SEPARATOR.'persisted_data'.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
});

it('resolves databaseFile() AND storage/app (secrets, backups, keyring) under the sibling persisted-data root, while the disposable storage/ tree stays bundle-rooted, under a relocated base_path() with NATIVEPHP_PLATFORM present and NATIVEPHP_STORAGE_PATH absent (the iOS reality)', function (): void {
    // The sandboxed base_path() IS the app bundle, wiped and re-shipped on every
    // update. Leaving storage/app there destroyed the keyring on each install
    // while the database it decrypts survived — on a real device 124 synced
    // transactions rendered as raw ciphertext. storage/framework + logs may stay.
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
        $persistedApp = dirname($sandbox).DIRECTORY_SEPARATOR.'persisted_data'
            .DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app';
        expect(UserDataPathService::secretsPath())
            ->toBe($persistedApp.DIRECTORY_SEPARATOR.'secrets');
        expect(UserDataPathService::backupsPath())
            ->toBe($persistedApp.DIRECTORY_SEPARATOR.'backups');
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
    // getenv('NATIVEPHP_PLATFORM') reads null when config/database.php
    // re-evaluates per request, so the env-only branch silently fell back to the
    // app-bundle DB on-device. The structural fallback keys off the NativePHP
    // layout instead: a `<app_storage>/laravel` base_path with a sibling store.
    $appStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-appstorage-'.bin2hex(random_bytes(8));
    $bundleRoot = $appStorage.DIRECTORY_SEPARATOR.'laravel';
    $persistedDir = $appStorage.DIRECTORY_SEPARATOR.'persisted_data';
    mkdir($bundleRoot, 0700, true);
    mkdir($persistedDir, 0700, true);

    $originalBasePath = $this->app->basePath();
    putenv('NATIVEPHP_PLATFORM');

    try {
        $this->app->setBasePath($bundleRoot);

        expect(UserDataPathService::platform())->toBeNull();
        expect(UserDataPathService::databaseFile())
            ->toBe($persistedDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
    } finally {
        $this->app->setBasePath($originalBasePath);
    }
});
