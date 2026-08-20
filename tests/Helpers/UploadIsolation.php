<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Support\Facades\Storage;

final class UploadIsolation
{
    // Livewire stages temporary uploads on the `local` disk, the one piece of
    // state concurrent `pest --parallel` processes genuinely share (database
    // and cache are per-process). Storage::fake() appends the parallel token
    // to the root, so its entry cleanDirectory() cannot hit another worker.
    public static function isolate(): void
    {
        Storage::fake('local');
    }
}
