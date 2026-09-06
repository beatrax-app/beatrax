<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Tests\Contracts\Support\RepoTree;

// An imported statement is copied to a stable location so the ledger can point
// at its source. Which location that is comes from the filesystem disk, and the
// framework's default root for 'local' is storage_path('app/private').
//
// The desktop shell remaps storage_path() to the writable data directory, so
// there the default is already right. Mobile does not remap it, and on a phone
// storage_path() names the unpacked bundle -- the half an app update replaces.
// The durable half is persisted_data/, which is what the path service answers.

/** Whether the file resolves a filesystem disk by name, in either spelling. */
function uploadedArtefactNamesADisk(string $source): bool
{
    return preg_match('/(?:Storage::disk|->disk)\s*\(/', $source) === 1;
}

/**
 * The roots come from RepoTree rather than from app/ and Modules/: the rule
 * says no caller anywhere has gone unaccounted for, and a disk named from
 * routes/, config/ or scripts/ was outside the walk that stated it.
 *
 * @return list<string> production files that resolve a filesystem disk by name
 */
function uploadedArtefactDiskCallers(): array
{
    $found = [];

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        if (uploadedArtefactNamesADisk((string) file_get_contents($path))) {
            $found[] = str_replace(RepoTree::root().'/', '', $path);
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
        expect(UserDataPathService::isMobileRuntime())
            ->toBeTrue('the mobile branch has to be reachable, or the two assertions below prove nothing about a phone')
            ->and(UserDataPathService::appPath('private'))->not->toBe(
                storage_path('app/private'),
                'on a phone storage_path() names the unpacked bundle, which an app update replaces, so the two must not be the same directory',
            )
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

    expect($callers)->toBe(['Modules/Import/Public/Actions/RunImport.php'], implode("\n  ", [
        'One place in the product resolves a filesystem disk by name, and the binding above',
        'is what decides where that disk is rooted. A second caller either inherits the same',
        'root — in which case it belongs in this list, with the reader having checked that a',
        'phone would put its file somewhere an app update does not replace — or names a disk',
        'of its own, which this rule then says nothing about. Compared in both directions:',
        'a caller that disappeared fails here as loudly as one that appeared.',
        'Found: '.implode(', ', $callers),
    ]));
});

it('reads a disk resolved by name in both spellings, and leaves a method that merely starts with one alone', function (): void {
    expect(uploadedArtefactNamesADisk("<?php Storage::disk('local')->put(\$path, \$body);"))
        ->toBeTrue('the facade spelling is one of the two ways a disk is named');

    expect(uploadedArtefactNamesADisk('<?php $this->storage->disk(self::STORAGE_DISK);'))
        ->toBeTrue('the injected-manager spelling is the one RunImport uses');

    expect(uploadedArtefactNamesADisk('<?php $report->diskUsageInBytes();'))
        ->toBeFalse('a method whose name merely starts with disk resolves nothing');
});
