<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

/*
 * 15-04 Task 1 — mobile-signal coverage for UserDataPathService.
 *
 * Per 15-SPIKE-FINDINGS.md (Spike B, run on a real iPhone): NATIVEPHP_STORAGE_PATH
 * stays UNSET on-device. NativePHP mobile instead retargets base_path() itself
 * into the app-sandbox container (`…/Documents/app`), so UserDataPathService
 * (which derives every accessor from base_path()) already resolves inside the
 * sandbox with NO dedicated mobile code path. The reliable on-device signal is
 * NATIVEPHP_PLATFORM (`ios`/`android`); this file pins:
 *
 *  - platform() reads NATIVEPHP_PLATFORM via getenv(), returning null when unset.
 *  - Setting NATIVEPHP_PLATFORM alone (NATIVEPHP_STORAGE_PATH absent) changes
 *    NOTHING about path resolution — there is deliberately no
 *    NATIVEPHP_PLATFORM branch in storageRoot()/databaseFile(); the sandbox is
 *    achieved by NativePHP relocating base_path(), not by this class branching.
 *  - With base_path() relocated to a sandbox-like directory (simulating what
 *    NativePHP mobile does on-device) AND NATIVEPHP_PLATFORM present, every
 *    storage-rooted accessor resolves under that relocated root — the mobile
 *    (NATIVEPHP_PLATFORM-present, NATIVEPHP_STORAGE_PATH-absent) case.
 *  - appPath() traversal guard still rejects `..` segments under a relocated
 *    base_path().
 *  - No native signal at all (plain web/test) behaves exactly as before
 *    (regression).
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

it('does not branch on NATIVEPHP_PLATFORM alone — path resolution is identical to the no-signal case when NATIVEPHP_STORAGE_PATH is absent', function (): void {
    $unsignaledDatabaseFile = UserDataPathService::databaseFile();
    $unsignaledStorageBase = UserDataPathService::storageBase();

    putenv('NATIVEPHP_PLATFORM=ios');

    expect(UserDataPathService::databaseFile())->toBe($unsignaledDatabaseFile);
    expect(UserDataPathService::storageBase())->toBe($unsignaledStorageBase);
    expect(UserDataPathService::storageBase())->toBe(storage_path());
});

it('resolves the sandboxed DB/storage/secrets paths under a relocated base_path() with NATIVEPHP_PLATFORM present and NATIVEPHP_STORAGE_PATH absent (the iOS reality)', function (): void {
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-sandbox-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700, true);

    $originalBasePath = $this->app->basePath();
    putenv('NATIVEPHP_PLATFORM=ios');

    try {
        $this->app->setBasePath($sandbox);

        expect(UserDataPathService::databaseFile())
            ->toBe($sandbox.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
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
