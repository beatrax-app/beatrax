<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\EmailScan\Public\Services\EmlBlobStore;

// put() writes a sibling .tmp, fsyncs, narrows it to 0600 and renames over the
// canonical path, so a reader never sees a partial .eml and the bytes are never
// briefly world-readable. Every failure must tear the temp file down: a
// half-written blob is indistinguishable from a complete one on the next scan.
function ebsWriteStore(): EmlBlobStore
{
    return new EmlBlobStore(new Filesystem, new UserDataPathService, new OwnerOnlyPath);
}

function ebsTempTarget(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-eml-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    return $dir.DIRECTORY_SEPARATOR.'message.eml';
}

function ebsCleanup(string $target): void
{
    foreach ((array) glob(dirname($target).DIRECTORY_SEPARATOR.'*') as $entry) {
        is_dir((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
    }
    @rmdir(dirname($target));
}

// fopen refuses a path that is already a directory, which is the shape a
// previous run interrupted between the open and the rename leaves behind.
it('reports a temp file it cannot open', function (): void {
    $target = ebsTempTarget();
    mkdir($target.'.tmp');

    expect(fn () => ebsWriteStore()->put($target, 'raw mime bytes'))
        ->toThrow(RuntimeException::class, 'could not open temp file');

    ebsCleanup($target);
});

// A directory in the way fails the rename with the bytes already written —
// the one failure where a complete temp file exists, and it still must not be
// left behind.
it('reports a rename it cannot complete, and leaves no temp file', function (): void {
    $target = ebsTempTarget();
    mkdir($target);
    file_put_contents($target.DIRECTORY_SEPARATOR.'occupied', 'x');

    expect(fn () => ebsWriteStore()->put($target, 'raw mime bytes'))
        ->toThrow(RuntimeException::class, 'atomic rename failed');

    expect(is_file($target.'.tmp'))->toBeFalse();

    @unlink($target.DIRECTORY_SEPARATOR.'occupied');
    @rmdir($target);
    ebsCleanup($target);
});
