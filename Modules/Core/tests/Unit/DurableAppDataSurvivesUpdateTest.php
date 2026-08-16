<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

/*
 * On mobile, base_path() is the app BUNDLE: wiped and re-shipped on every
 * install. Anything durable written under it is destroyed by an app update.
 *
 * That is not theoretical. A real device carried 124 synced transactions whose
 * descriptions rendered as raw base64, because the GDK keyring that decrypts
 * them lived at storage/app/sync/gdk/1.enc inside the bundle and the reinstall
 * had wiped it. The rows survived — they are in the persisted store — and the
 * key did not.
 *
 * So: every path that holds a key, a secret or a backup must resolve OUTSIDE
 * the bundle on the mobile runtime.
 */

it('keeps keys, secrets and backups out of the wiped-on-update bundle', function (): void {
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-durable-'.bin2hex(random_bytes(8));
    mkdir($sandbox, 0700, true);

    $originalBasePath = $this->app->basePath();
    // Unset alongside the platform signal: Spike B found NATIVEPHP_STORAGE_PATH
    // stays UNSET on a real device, which is precisely why the fallback below
    // has to be right. The suite sets it for isolation, so the device reality
    // only appears once it is cleared.
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
