<?php

declare(strict_types=1);

use Modules\Core\Public\Bootstrap\EnsurePrivateDatabaseFile;
use Modules\Core\Public\Services\UserDataPathService;

beforeEach(function (): void {
    $this->previousStorageEnv = getenv('NATIVEPHP_STORAGE_PATH');
    $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'precreated-ledger-'.bin2hex(random_bytes(6));
    mkdir((string) $this->tempRoot, 0755, true);
    putenv('NATIVEPHP_STORAGE_PATH='.$this->tempRoot);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg((string) $this->tempRoot));

    $previous = $this->previousStorageEnv;
    is_string($previous) && $previous !== ''
        ? putenv('NATIVEPHP_STORAGE_PATH='.$previous)
        : putenv('NATIVEPHP_STORAGE_PATH');
});

function precreatedLedgerMode(): int
{
    $file = UserDataPathService::databaseFile();
    clearstatcache(true, $file);

    return (int) fileperms($file) & 0777;
}

// SQLite copies the database file's own mode onto -wal and -shm, so the mode
// settled here is the mode of the recently written pages too, not just of the
// committed ones.
it('brings a missing ledger into existence owner-only', function (): void {
    $previousUmask = umask(0o022);

    try {
        expect(is_file(UserDataPathService::databaseFile()))->toBeFalse();

        $this->app->make(EnsurePrivateDatabaseFile::class)->run();

        expect(is_file(UserDataPathService::databaseFile()))->toBeTrue()
            ->and(precreatedLedgerMode())->toBe(0o600);
    } finally {
        umask($previousUmask);
    }
});

// The mode was settled once, at creation, so a ledger restored by a copy that
// did not preserve modes — an unzip, a `cp` without -p, a file manager drag —
// arrived at 0644 and no later boot ever looked at it again.
it('narrows a ledger that arrived from somewhere else already readable', function (): void {
    $file = UserDataPathService::databaseFile();
    mkdir(dirname($file), 0755, true);
    touch($file);
    chmod($file, 0o644);

    $this->app->make(EnsurePrivateDatabaseFile::class)->run();

    expect(precreatedLedgerMode())->toBe(0o600);
});

it('leaves the contents of an existing ledger untouched', function (): void {
    $file = UserDataPathService::databaseFile();
    mkdir(dirname($file), 0755, true);
    file_put_contents($file, 'SQLite format 3');

    $this->app->make(EnsurePrivateDatabaseFile::class)->run();

    expect(file_get_contents($file))->toBe('SQLite format 3');
});
