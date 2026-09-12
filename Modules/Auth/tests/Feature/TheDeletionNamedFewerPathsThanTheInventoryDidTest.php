<?php

declare(strict_types=1);

use Modules\Auth\Internal\Account\UserScopedFilePurge;
use Modules\Core\Public\Services\UserDataPathService;

// The deletion carried three lists of its own rather than reading Core's
// inventory of where this install keeps the reader's data, and what the two
// disagreed about was every statement the reader had ever imported.

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-purge-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    if (is_dir($storageRoot)) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($storageRoot);
        @rmdir(dirname($storageRoot));
    }

    putenv('NATIVEPHP_STORAGE_PATH');
});

function purgePlant(string $relative): string
{
    $path = UserDataPathService::appPath($relative);
    @mkdir(dirname($path), 0o700, true);
    file_put_contents($path, 'x');

    return $path;
}

// The statements a reader imported are their own bank's documents, and they sit
// under a directory the inventory has always named. The deletion named nothing
// inside it.
//
// The account leaving is NOT the last one on the device, which is the case that
// matters: a household shares one install, so the device-wide sweep never runs
// and the only thing that could have removed these is a path named for the
// account. Another reader's statements beside them have to stay.
it('removes the statements an account imported', function (): void {
    $mine = purgePlant('private/imports/7/9f86d081884c7d65.csv');
    $theirs = purgePlant('private/imports/8/60303ae22b998861.csv');

    expect(is_file($mine))->toBeTrue()
        ->and(is_file($theirs))->toBeTrue();

    $purge = app(UserScopedFilePurge::class);
    $purge->keyedToTheAccount(7);
    $survivors = $purge->residue(7, lastAccountOnDevice: false);

    expect(is_file($mine))->toBeFalse('Every statement the reader imported outlived their account.')
        ->and(is_file($theirs))->toBeTrue('The deletion took the other account\'s statements with it.')
        ->and($survivors)->toBe([]);
});
