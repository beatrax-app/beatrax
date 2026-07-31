<?php

declare(strict_types=1);

use Modules\Receipts\Public\Exceptions\MboxReadException;
use Modules\Receipts\Public\Pipeline\MboxIterator;

/*
 * A missing or unopenable archive is a read-side fault on the source —
 * distinct from the blob-write failures — so the iterator surfaces it as
 * MboxReadException before any message is yielded. The iterator is lazy:
 * iterate() returns a generator, so the open (and the throw) only fire
 * once the generator is advanced.
 */

it('throws MboxReadException naming the path when the mbox cannot be opened', function (): void {
    $iterator = new MboxIterator;
    $missing = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.mbox';

    expect(function () use ($iterator, $missing): void {
        foreach ($iterator->iterate($missing) as $_) {
            // Advancing the generator triggers the fopen() attempt.
        }
    })->toThrow(MboxReadException::class, $missing);
});

it('exposes the couldNotOpen factory message shape', function (): void {
    $e = MboxReadException::couldNotOpen('/archives/broken.mbox');

    expect($e)->toBeInstanceOf(RuntimeException::class);
    expect($e->getMessage())->toContain('cannot open mbox');
    expect($e->getMessage())->toContain('/archives/broken.mbox');
});
