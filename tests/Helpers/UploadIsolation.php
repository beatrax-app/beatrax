<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Support\Facades\Storage;

final class UploadIsolation
{
    // Livewire stores a temporary uploaded file on the default disk, which is
    // the on-disk `local` disk. The database and cache are per-process under
    // `pest --parallel` (`:memory:` and `array`), so that directory is the one
    // piece of state four concurrent test processes genuinely share.
    /**
     * Points the `local` disk at a per-process root for the duration of a test
     * that uploads a file.
     *
     * `Storage::fake()` appends the parallel-testing token to the root, so each
     * process gets its own directory and the `cleanDirectory()` it performs on
     * entry cannot delete a file another process is midway through reading.
     */
    public static function isolate(): void
    {
        Storage::fake('local');
    }
}
