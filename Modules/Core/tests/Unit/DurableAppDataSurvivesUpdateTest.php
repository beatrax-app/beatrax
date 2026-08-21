<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// On mobile, base_path() is the app bundle: wiped and re-shipped on every
// install. A real device carried 124 synced transactions rendering as raw
// base64 because the keyring at storage/app/sync/gdk/1.enc sat in the bundle
// and the reinstall wiped it. Keys, secrets and backups must resolve outside.
it('keeps keys, secrets and backups out of the wiped-on-update bundle', function (): void {
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-durable-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700, true);

    $originalBasePath = $this->app->basePath();
    // NATIVEPHP_STORAGE_PATH stays unset on a real device, which is exactly why
    // the fallback below has to be right; the suite sets it for isolation, so
    // the device reality only appears once it is cleared.
    $originalStoragePath = getenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_PLATFORM=android');

    try {
        $this->app->setBasePath($sandbox);

        $durable = [
            'gdk keyring' => UserDataPathService::appPath('sync/gdk/1.enc'),
            'sync identity' => UserDataPathService::appPath('sync/identity/1.enc'),
            'secrets' => UserDataPathService::secretsPath(),
            'backups' => UserDataPathService::backupsPath(),
        ];

        foreach ($durable as $what => $path) {
            expect($path)->not->toStartWith($sandbox.DIRECTORY_SEPARATOR, "{$what} must not live inside the bundle")
                ->and($path)->toContain('persisted_data');
        }
    } finally {
        $this->app->setBasePath($originalBasePath);
        putenv('NATIVEPHP_PLATFORM');
        if (is_string($originalStoragePath) && $originalStoragePath !== '') {
            putenv('NATIVEPHP_STORAGE_PATH='.$originalStoragePath);
        }
    }
});

it('leaves desktop paths inside the project storage tree', function (): void {
    // No mobile signal: nothing relocates, because on desktop the project
    // directory is not replaced underneath a running install.
    putenv('NATIVEPHP_PLATFORM');

    expect(UserDataPathService::appPath('sync/gdk/1.enc'))
        ->not->toContain('persisted_data')
        ->and(UserDataPathService::appPath('sync/gdk/1.enc'))->toContain('storage');
});
