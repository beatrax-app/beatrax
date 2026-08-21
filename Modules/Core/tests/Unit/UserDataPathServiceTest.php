<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

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

it('resolves storage-rooted accessors under the project storage dir when the env var is unset (local-dev parity)', function (): void {
    // base_path(), not storage_path(): storage_path() is relocated per test, so
    // only base_path() pins the fallback under test — projectRoot()/storage.
    $projectStorage = base_path('storage');

    expect(UserDataPathService::storageBase())->toBe($projectStorage);
    expect(UserDataPathService::backupsPath())->toBe($projectStorage.'/app/backups');
    expect(UserDataPathService::secretsPath())->toBe($projectStorage.'/app/secrets');
    expect(UserDataPathService::frameworkPath('sessions'))->toBe($projectStorage.'/framework/sessions');
    expect(UserDataPathService::appPath())->toBe($projectStorage.'/app');
});

it('resolves every storage-rooted accessor under NATIVEPHP_STORAGE_PATH when it is set', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8));
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
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8));
    putenv('NATIVEPHP_STORAGE_PATH='.$tmp.'/');

    expect(UserDataPathService::storageBase())->toBe($tmp);
    expect(UserDataPathService::backupsPath())
        ->toBe($tmp.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups');
});

it('joins a relative sub-path onto the storage app root via appPath()', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8));
    putenv('NATIVEPHP_STORAGE_PATH='.$tmp);

    expect(UserDataPathService::appPath('inbox/2026/05'))
        ->toBe($tmp.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'inbox/2026/05');
});

it('rejects a path-traversal segment in appPath()', function (): void {
    expect(fn (): string => UserDataPathService::appPath('../../etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps modulesPath, migrationsPath and publicPath project-rooted even when the env var is set', function (): void {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8));
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

it('resolves UserDataPathService as the same singleton instance through the container', function (): void {
    $first = $this->app->make(UserDataPathService::class);
    $second = $this->app->make(UserDataPathService::class);

    expect($first)->toBeInstanceOf(UserDataPathService::class);
    expect($first)->toBe($second);
});

it('keeps backupsPath byte-identical to the previously container-bound storage path when the env var is unset', function (): void {
    // BackupDatabaseCommand used to resolve its directory from
    // $app->basePath('storage/app/backups'). With the env var unset the service
    // must still produce that exact string, or the dev-mode path shifts silently.
    expect(UserDataPathService::backupsPath())
        ->toBe($this->app->basePath('storage/app/backups'));
});
