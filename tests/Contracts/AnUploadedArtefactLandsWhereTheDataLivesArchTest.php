<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// An imported statement is copied to a stable location so the ledger can point
// at its source. Which location that is comes from the filesystem disk, and the
// framework's default root for 'local' is storage_path('app/private').
//
// The desktop shell remaps storage_path() to the writable data directory, so
// there the default is already right. Mobile does not remap it, and on a phone
// storage_path() names the unpacked bundle -- the half an app update replaces.
// The durable half is persisted_data/, which is what the path service answers.

/** @return list<string> production files that resolve a filesystem disk by name */
function uploadedArtefactDiskCallers(): array
{
    $found = [];

    foreach (['app', 'Modules'] as $directory) {
        $root = base_path($directory);

        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (preg_match('/(?:Storage::disk|->disk)\s*\(/', $source) === 1) {
                $found[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    sort($found);

    return $found;
}

it('resolves the upload disk through the path service, not through storage_path', function (): void {
    expect(config('filesystems.disks.local.root'))
        ->toBe(UserDataPathService::appPath('private'));
});

// Without this the assertion above proves nothing off-device: in a plain
// checkout storage_path('app/private') and appPath('private') are the same
// directory, so a binding that had been deleted would still look correct.
it('binds a root that a phone would otherwise resolve somewhere else', function (): void {
    $platform = getenv('NATIVEPHP_PLATFORM');

    // The suite sets NATIVEPHP_STORAGE_PATH, which the path service honours
    // ahead of everything else -- so the mobile branch is unreachable in a
    // test until it is cleared. A phone has the platform and not the override.
    $storage = getenv('NATIVEPHP_STORAGE_PATH');

    putenv('NATIVEPHP_PLATFORM=ios');
    putenv('NATIVEPHP_STORAGE_PATH');

    try {
        expect(UserDataPathService::isMobileRuntime())->toBeTrue()
            ->and(UserDataPathService::appPath('private'))->not->toBe(storage_path('app/private'))
            ->and(UserDataPathService::appPath('private'))->toContain('persisted_data');
    } finally {
        $platform === false ? putenv('NATIVEPHP_PLATFORM') : putenv('NATIVEPHP_PLATFORM='.$platform);
        $storage === false ? putenv('NATIVEPHP_STORAGE_PATH') : putenv('NATIVEPHP_STORAGE_PATH='.$storage);
    }
});

// The binding lives in one place, and a deleted line is the failure this rule
// exists for -- the runtime assertion above cannot see it off-device.
it('keeps the binding where the container can apply it before a disk resolves', function (): void {
    $provider = (string) file_get_contents(base_path('Modules/Core/Providers/CoreServiceProvider.php'));

    expect($provider)->toContain("->set('filesystems.disks.local.root', UserDataPathService::appPath('private'));");
});

// A new caller naming a disk this rule has not considered is the way the
// defect returns, so the set is closed rather than sampled.
it('has no filesystem-disk caller that has not been accounted for', function (): void {
    $callers = uploadedArtefactDiskCallers();

    expect($callers)->toBe(['Modules/Import/Public/Actions/RunImport.php']);
});
