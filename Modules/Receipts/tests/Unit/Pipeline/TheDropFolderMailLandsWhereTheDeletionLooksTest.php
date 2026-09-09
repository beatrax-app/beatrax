<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;

// UserDataLocations is one inventory with three readers: the page that shows
// the reader where their data is, the deletion procedure that has to name
// every path, and the export that bundles them. It answers appPath() for the
// dropped mail and the drop folder.
//
// This store asked the container instead — $app->storagePath('app/inbox/…').
// On a checkout, on the desktop and on Android those are the same directory,
// so it read as a spelling difference for as long as anyone looked. On iOS
// they are not: the shell announces Application Support as the storage root
// while the durable store is base_path()'s sibling under Documents. A file
// written to the first is a file the deletion walks past and the export ships
// without, and the delete still reports success.
//
// The sibling store in EmailScan resolves the same shape through
// $this->paths->appRelative(). This one was the sibling that did not.

/** The iOS shape: a storage root announced somewhere other than the store. */
function withAnnouncedStorageRootUnlikeTheStore(Closure $probe): mixed
{
    $platform = getenv('NATIVEPHP_PLATFORM');
    $desktop = getenv('NATIVEPHP_STORAGE_PATH');
    $announced = sys_get_temp_dir().DIRECTORY_SEPARATOR
        .'beatrax-appsupport-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';

    putenv('NATIVEPHP_PLATFORM=ios');
    putenv('NATIVEPHP_STORAGE_PATH');
    $_SERVER['LARAVEL_STORAGE_PATH'] = $announced;
    app()->useStoragePath($announced);

    try {
        return $probe($announced);
    } finally {
        unset($_SERVER['LARAVEL_STORAGE_PATH']);
        $desktop === false
            ? putenv('NATIVEPHP_STORAGE_PATH')
            : putenv('NATIVEPHP_STORAGE_PATH='.$desktop);
        $platform === false
            ? putenv('NATIVEPHP_PLATFORM')
            : putenv('NATIVEPHP_PLATFORM='.$platform);
        app()->useStoragePath($desktop === false ? base_path('storage') : $desktop);
    }
}

it('writes a dropped .eml inside the tree the deletion procedure and the export walk', function (): void {
    $store = new FileDropEmlBlobStore(new Filesystem);

    withAnnouncedStorageRootUnlikeTheStore(function (string $announced) use ($store): void {
        $path = $store->pathFor(7, new DateTimeImmutable('2026-05-04 10:00:00'), 'abc123');

        expect($path)
            ->toStartWith(UserDataPathService::appPath('inbox').DIRECTORY_SEPARATOR)
            ->and($path)->toContain('persisted_data')
            ->and($path)->not->toStartWith($announced);
    });
});

// Without this the assertion above proves nothing: if the two trees were the
// same directory, a store that had gone back to asking the container would
// still satisfy it.
it('is measured on a shape where the two trees genuinely differ', function (): void {
    withAnnouncedStorageRootUnlikeTheStore(static function (string $announced): void {
        expect(UserDataPathService::storageBase())->toBe($announced)
            ->and(storage_path('app/inbox'))->not->toBe(UserDataPathService::appPath('inbox'))
            ->and(UserDataPathService::appPath('inbox'))->toContain('persisted_data');
    });
});
