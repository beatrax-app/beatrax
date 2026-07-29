<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Public\Services\EmlBlobStore;

/*
 * What put() does when the disk refuses.
 *
 * A raw message is written to a sibling .tmp, fsynced, narrowed to 0600 and
 * renamed over the canonical path, so that a reader never sees a partial .eml
 * and the bytes are never briefly world-readable. Every failure in that
 * sequence has to tear the temp file down and surface — a half-written blob
 * left behind would be indistinguishable from a complete one on the next scan.
 *
 * None of these were exercised.
 */
function ebsWriteStore(): EmlBlobStore
{
    return new EmlBlobStore(new Filesystem, new UserDataPathService);
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

// The rename is the moment the blob becomes visible under its canonical name.
// A directory in the way makes it fail with the bytes already written, which
// is the one failure where a complete temp file exists — and it still must not
// be left behind.
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
