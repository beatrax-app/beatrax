<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Modules\Receipts\Internal\Exceptions\FileDropBlobWriteException;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;

// Only two of put()'s failure branches can be provoked with real filesystem
// faults. The chmod, short-write and catch-all arms are defence-in-depth and
// are covered by the exception's own factory test instead.

beforeEach(function (): void {
    /** @var Application $app */
    $app = $this->app;
    $this->dir = $app->storagePath('app/inbox/8888/file-drop/2026/06');
    $files = new Filesystem;
    if ($files->isDirectory($this->dir)) {
        // Restore writability so the tree can be torn down.
        @chmod($this->dir, 0o700);
        $files->deleteDirectory($this->dir);
    }
});

afterEach(function (): void {
    $files = new Filesystem;
    if (isset($this->dir) && $files->isDirectory($this->dir)) {
        @chmod($this->dir, 0o700);
        $files->deleteDirectory($this->dir);
    }
});

function fileDropStore(): FileDropEmlBlobStore
{
    /** @var Application $app */
    $app = app();
    /** @var Filesystem $files */
    $files = $app->make(Filesystem::class);

    return new FileDropEmlBlobStore($files, $app);
}

it('raises couldNotOpenTempFile when the target directory is read-only', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root bypasses directory write permissions.');
    }

    // Pre-creating the directory sends put() down its "already existed" path,
    // which skips the chmod; stripping write then blocks the .tmp fopen.
    mkdir($this->dir, 0o700, true);
    chmod($this->dir, 0o500);

    expect(fn () => fileDropStore()->put($this->dir.'/blocked.eml', 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'could not open temp file');
});

it('raises atomicRenameFailed when the destination path is an existing directory', function (): void {
    // A directory squatting on the final path makes rename() of the temp file
    // impossible, so the atomic swap cannot complete.
    $target = $this->dir.'/collision.eml';
    mkdir($target, 0o700, true);
    // Non-empty so rename can never treat it as a replaceable empty dir.
    file_put_contents($target.'/squatter', 'x');

    expect(fn () => fileDropStore()->put($target, 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'atomic rename failed');

    expect(is_file($target.'.tmp'))->toBeFalse();
});
