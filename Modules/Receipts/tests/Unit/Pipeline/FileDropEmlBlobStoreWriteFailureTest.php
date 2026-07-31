<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Modules\Receipts\Public\Exceptions\FileDropBlobWriteException;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;

/*
 * The atomic put() unwinds partway-failed writes into a named
 * FileDropBlobWriteException rather than a bare RuntimeException, so a
 * half-written blob is never mistaken for a stored receipt. These drive
 * two of the failure branches with real filesystem faults; the chmod /
 * short-write / catch-all arms are defence-in-depth that cannot be
 * provoked without privileged FS trickery and are covered by the
 * exception's own factory test.
 */

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

    // Pre-create the partition so put() takes the "directory existed"
    // path (no chmod) — then strip write permission so fopen of the
    // .tmp sibling cannot open.
    mkdir($this->dir, 0o700, true);
    chmod($this->dir, 0o500);

    expect(fn () => fileDropStore()->put($this->dir.'/blocked.eml', 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'could not open temp file');
});

it('raises atomicRenameFailed when the destination path is an existing directory', function (): void {
    // A directory squatting on the final path makes rename() of the
    // temp file impossible — the atomic swap cannot complete.
    $target = $this->dir.'/collision.eml';
    mkdir($target, 0o700, true);
    // Non-empty so rename can never treat it as a replaceable empty dir.
    file_put_contents($target.'/squatter', 'x');

    expect(fn () => fileDropStore()->put($target, 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'atomic rename failed');

    // The temp file was unwound — no orphan .tmp is left behind.
    expect(is_file($target.'.tmp'))->toBeFalse();
});
