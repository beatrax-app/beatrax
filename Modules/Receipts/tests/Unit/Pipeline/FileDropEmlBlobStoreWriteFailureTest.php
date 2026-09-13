<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Receipts\Internal\Exceptions\FileDropBlobWriteException;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;
use Tests\Helpers\FailingStream;

// Two of put()'s failure branches are provoked with real filesystem faults,
// and the three that ask whether the bytes reached the disk are provoked over
// a registered scheme — a full disk is not stageable with a real file, because
// fwrite answers from a userspace buffer and the failure arrives at the flush.
// Only the fclose arm is left uncovered: PHP's userland close cannot report a
// failure, and a plain file still can.

beforeEach(function (): void {
    $this->dir = UserDataPathService::appPath('inbox/8888/file-drop/2026/06');
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

    return new FileDropEmlBlobStore($files);
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

afterEach(function (): void {
    FailingStream::reset();
});

it('refuses a write the filesystem only half accepted', function (): void {
    FailingStream::register();
    FailingStream::$failWrites = true;
    $path = 'beatraxfail://blobs/2026/06/message.eml';

    expect(fn () => fileDropStore()->put($path, 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'short write');
});

// The arm this file exists for. The write was accepted, the count agreed, and
// the bytes are still only in a buffer the flush could not drain.
it('refuses a write whose bytes the flush could not put on disk', function (): void {
    FailingStream::register();
    FailingStream::$failFlush = true;
    $path = 'beatraxfail://blobs/2026/06/message.eml';

    expect(fn () => fileDropStore()->put($path, 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'fflush');
});

it('refuses a write the flush accepted and the fsync did not', function (): void {
    FailingStream::register();
    $path = 'beatraxfail://blobs/2026/06/message.eml';

    expect(fn () => fileDropStore()->put($path, 'raw mime bytes'))
        ->toThrow(FileDropBlobWriteException::class, 'fsync');
});
