<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Core\Public\Services\UserDataPathService;

// The local disk is rooted at the private data tree, and imports land in it as
// imports/{userId}/{sha256} -- the only plaintext user financial data on disk.
// Asserted as behaviour rather than as config keys, because the mode is what a
// copy carries and what an export archive preserves.
it('writes a file on the private disk owner-only', function (): void {
    $relative = 'imports/999/'.bin2hex(random_bytes(8)).'.csv';

    Storage::disk('local')->put($relative, 'date,amount,description');

    try {
        $path = UserDataPathService::appPath('private/'.$relative);

        expect(is_file($path))->toBeTrue('The probe file was not written where the disk is rooted.');
        expect(fileperms($path) & 0o777)->toBe(0o600, 'A file on the private disk must be mode 0600.');
        expect(fileperms(dirname($path)) & 0o777)->toBe(0o700, 'A directory on the private disk must be mode 0700.');
    } finally {
        Storage::disk('local')->delete($relative);
    }
});
