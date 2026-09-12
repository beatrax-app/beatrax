<?php

declare(strict_types=1);

use Modules\Core\Internal\Storage\UserDataLocations;
use Modules\Core\Public\Services\UserDataPathService;

// `UserDataLocations` is described as one inventory with three readers: the
// page that shows where the data is, the deletion that has to name every path,
// and the export. It had two. The deletion carried its own lists, and what the
// two disagreed about was every statement the reader had ever imported.

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

// The page that tells a reader how to remove every trace by hand renders this
// inventory. A path missing from it is a path they are told they do not have.
it('names the key material on the page that tells the reader what to delete', function (): void {
    $paths = UserDataLocations::all();

    $keyMaterial = UserDataPathService::appPath('sync');

    expect($paths)->toContain($keyMaterial);
});

// The guard the two lists never had. Every location the inventory names either
// says which of its files one account owns, or declines an account scope by
// name, or is declined from a file purge altogether. A location in none of the
// three fails this rather than being quietly left on disk.
//
// Deliberately NOT satisfied by the device-wide tree: that one is only swept
// when the last account leaves, so a location reachable only through it
// survives every deletion on a household device — which is exactly how the
// statements a reader imported came to outlive their account.
it('classifies every location in the inventory as one a deletion reaches', function (): void {
    $all = array_keys(UserDataLocations::all());
    $reached = array_merge(
        array_keys(UserDataLocations::accountScoped(1)),
        array_keys(UserDataLocations::withoutAnAccountScope()),
        array_keys(UserDataLocations::notRemovedByAFilePurge()),
    );

    $all = array_unique($all);
    $reached = array_unique($reached);
    sort($all);
    sort($reached);

    expect($reached)->toBe($all);
});

// Both directions. A purge path outside every location the inventory names is a
// tree the "where is my data" page does not show and the reader cannot find.
it('names every path a deletion removes inside a location the inventory shows', function (): void {
    $roots = array_values(UserDataLocations::all());

    $named = [];
    foreach ([UserDataLocations::accountScoped(7), UserDataLocations::deviceWide()] as $partition) {
        foreach ($partition as $paths) {
            $named = [...$named, ...$paths];
        }
    }

    $orphans = [];
    foreach ($named as $path) {
        $inside = false;
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, rtrim($root, '/').'/')) {
                $inside = true;

                break;
            }
        }
        if (! $inside) {
            $orphans[] = $path;
        }
    }

    expect($orphans)->toBe([]);
});
