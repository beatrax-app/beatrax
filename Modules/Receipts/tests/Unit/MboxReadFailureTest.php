<?php

declare(strict_types=1);

use Modules\Receipts\Public\Exceptions\MboxReadException;
use Modules\Receipts\Public\Pipeline\MboxIterator;

it('throws MboxReadException naming the path when the mbox cannot be opened', function (): void {
    $iterator = new MboxIterator;
    $missing = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.mbox';

    expect(function () use ($iterator, $missing): void {
        foreach ($iterator->iterate($missing) as $_) {
            // iterate() is lazy, so the fopen only happens once it is advanced.
        }
    })->toThrow(MboxReadException::class, $missing);
});

it('exposes the couldNotOpen factory message shape', function (): void {
    $e = MboxReadException::couldNotOpen('/archives/broken.mbox');

    expect($e)->toBeInstanceOf(RuntimeException::class);
    expect($e->getMessage())->toContain('cannot open mbox');
    expect($e->getMessage())->toContain('/archives/broken.mbox');
});
