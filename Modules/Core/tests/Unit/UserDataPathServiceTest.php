<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

/*
 * Unit coverage for UserDataPathService — the single class through which
 * every filesystem path the app reads or writes resolves.
 *
 * The service reads getenv('NATIVEPHP_STORAGE_PATH') directly, so the
 * env var is set with putenv('NATIVEPHP_STORAGE_PATH='.$tmp) and cleared
 * with putenv('NATIVEPHP_STORAGE_PATH') (no `=`) — getenv() is the only
 * mechanism the service observes.
 *
 * Coverage:
 *  - env-unset (Herd dev): every accessor resolves to the project-rooted
 *    paths used today, byte-identical to base_path()/storage_path() output
 *    (the A2 Herd-parity regression guard).
 *  - env-set (simulated NativePHP): every storage-rooted accessor resolves
 *    under the NATIVEPHP_STORAGE_PATH root.
 *  - appPath() rejects `..` path-traversal segments.
 *  - modulesPath()/migrationsPath()/publicPath() stay project-rooted even
 *    when the env var is set (code/asset locations, not user data).
 */

beforeEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

it('resolves the SQLite database file under the project root when the env var is unset', function (): void {
    expect(UserDataPathService::databaseFile())
        ->toBe(base_path('database/database.sqlite'));
});

it('resolves storage-rooted accessors under the project storage dir when the env var is unset (Herd parity)', function (): void {
    expect(UserDataPathService::storageBase())->toBe(storage_path());
    expect(UserDataPathService::backupsPath())->toBe(storage_path('app/backups'));
    expect(UserDataPathService::secretsPath())->toBe(storage_path('app/secrets'));
    expect(UserDataPathService::frameworkPath('sessions'))->toBe(storage_path('framework/sessions'));
    expect(UserDataPathService::appPath())->toBe(storage_path('app'));
});

it('resolves every storage-rooted accessor under NATIVEPHP_STORAGE_PATH when it is set', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8));
    putenv('NATIVEPHP_STORAGE_PATH='.$tmp);

    expect(UserDataPathService::databaseFile())
        ->toBe($tmp.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
    expect(UserDataPathService::storageBase())->toBe($tmp);
    expect(UserDataPathService::backupsPath())
        ->toBe($tmp.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups');
    expect(UserDataPathService::secretsPath())
        ->toBe($tmp.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'secrets');
    expect(UserDataPathService::frameworkPath('down'))
        ->toBe($tmp.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'down');
});

it('normalises a trailing separator on NATIVEPHP_STORAGE_PATH', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8));
    putenv('NATIVEPHP_STORAGE_PATH='.$tmp.'/');

    expect(UserDataPathService::storageBase())->toBe($tmp);
    expect(UserDataPathService::backupsPath())
        ->toBe($tmp.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups');
});

it('joins a relative sub-path onto the storage app root via appPath()', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8));
    putenv('NATIVEPHP_STORAGE_PATH='.$tmp);

    expect(UserDataPathService::appPath('inbox/2026/05'))
        ->toBe($tmp.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'inbox/2026/05');
});

it('rejects a path-traversal segment in appPath()', function (): void {
    expect(fn (): string => UserDataPathService::appPath('../../etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps modulesPath, migrationsPath and publicPath project-rooted even when the env var is set', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-test-'.bin2hex(random_bytes(8));
    putenv('NATIVEPHP_STORAGE_PATH='.$tmp);

    expect(UserDataPathService::modulesPath())->toBe(base_path('Modules'));
    expect(UserDataPathService::migrationsPath())->toBe(base_path('database/migrations'));
    expect(UserDataPathService::publicPath('modules'))->toBe(base_path('public/modules'));
    expect(UserDataPathService::publicPath())->toBe(base_path('public'));
});

it('exposes instance accessors that delegate to the static surface', function (): void {
    $svc = new UserDataPathService;

    expect($svc->databasePath())->toBe(UserDataPathService::databaseFile());
    expect($svc->storagePath())->toBe(UserDataPathService::storageBase());
    expect($svc->backups())->toBe(UserDataPathService::backupsPath());
    expect($svc->secrets())->toBe(UserDataPathService::secretsPath());
    expect($svc->framework('down'))->toBe(UserDataPathService::frameworkPath('down'));
    expect($svc->appRelative('inbox'))->toBe(UserDataPathService::appPath('inbox'));
});
