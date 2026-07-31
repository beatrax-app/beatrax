<?php

declare(strict_types=1);

use Modules\Receipts\Public\Exceptions\FileDropBlobWriteException;

/*
 * Each named factory carries the failure point in its message so an
 * operator reading the queue-worker log can tell a refused chmod from a
 * short write from a rename that could not land — the whole reason the
 * write path names its faults rather than throwing a bare
 * RuntimeException.
 */

it('names the newly-created-directory chmod failure', function (): void {
    $e = FileDropBlobWriteException::chmodDirectoryFailed('/var/inbox/9/2026/05');

    expect($e)->toBeInstanceOf(FileDropBlobWriteException::class);
    expect($e)->toBeInstanceOf(RuntimeException::class);
    expect($e->getMessage())->toContain('chmod 0700');
    expect($e->getMessage())->toContain('/var/inbox/9/2026/05');
});

it('names the temp-file open failure', function (): void {
    $e = FileDropBlobWriteException::couldNotOpenTempFile('/var/inbox/x.eml.tmp');

    expect($e->getMessage())->toContain('could not open temp file');
    expect($e->getMessage())->toContain('/var/inbox/x.eml.tmp');
});

it('names the short-write failure', function (): void {
    $e = FileDropBlobWriteException::shortWrite('/var/inbox/x.eml.tmp');

    expect($e->getMessage())->toContain('short write');
    expect($e->getMessage())->toContain('/var/inbox/x.eml.tmp');
});

it('names the temp-file chmod failure', function (): void {
    $e = FileDropBlobWriteException::chmodTempFileFailed('/var/inbox/x.eml.tmp');

    expect($e->getMessage())->toContain('failed to chmod temp file');
    expect($e->getMessage())->toContain('/var/inbox/x.eml.tmp');
});

it('names the atomic-rename failure with both paths', function (): void {
    $e = FileDropBlobWriteException::atomicRenameFailed('/var/inbox/x.eml.tmp', '/var/inbox/x.eml');

    expect($e->getMessage())->toContain('atomic rename failed');
    expect($e->getMessage())->toContain('/var/inbox/x.eml.tmp');
    expect($e->getMessage())->toContain('/var/inbox/x.eml');
});

it('names the unexpected catch-all failure', function (): void {
    $e = FileDropBlobWriteException::unexpectedFailure('/var/inbox/x.eml');

    expect($e->getMessage())->toContain('unexpected failure');
    expect($e->getMessage())->toContain('/var/inbox/x.eml');
});
