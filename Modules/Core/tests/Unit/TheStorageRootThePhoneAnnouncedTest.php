<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;

// Each shell tells PHP where storage is, and each uses its own name for it.
// The desktop passes NATIVEPHP_STORAGE_PATH in the spawned process
// environment; both mobile shells announce LARAVEL_STORAGE_PATH, which the
// framework's own storage_path() honours. This class read only the desktop's
// name, so on a phone it answered base_path()/storage while the framework
// answered the announced root — two storage trees in one installation.
//
// Measured on an iPhone 12 mini, iOS 26.5.2, five launches:
// Documents/app/storage/logs/laravel.log held 250 lines while
// Library/Application Support/storage/logs — the directory the app's own
// booted() hook creates through storage_path() — held none of them. The
// bundle half is deleted whole by AppUpdateManager on every version change,
// and it is the one data tree on the device that nothing excludes from iCloud.

/** Save and clear both spellings, run $probe, restore whatever was there. */
function withAnnouncedStorageRoot(?string $announced, Closure $probe, ?string $where = null): mixed
{
    $desktop = getenv('NATIVEPHP_STORAGE_PATH');
    $server = $_SERVER['LARAVEL_STORAGE_PATH'] ?? null;
    $env = $_ENV['LARAVEL_STORAGE_PATH'] ?? null;
    $process = getenv('LARAVEL_STORAGE_PATH');

    putenv('NATIVEPHP_STORAGE_PATH');
    unset($_SERVER['LARAVEL_STORAGE_PATH'], $_ENV['LARAVEL_STORAGE_PATH']);
    putenv('LARAVEL_STORAGE_PATH');

    if ($announced !== null) {
        match ($where ?? 'server') {
            'env' => $_ENV['LARAVEL_STORAGE_PATH'] = $announced,
            'process' => putenv('LARAVEL_STORAGE_PATH='.$announced),
            default => $_SERVER['LARAVEL_STORAGE_PATH'] = $announced,
        };
    }

    try {
        return $probe();
    } finally {
        putenv('LARAVEL_STORAGE_PATH');
        unset($_SERVER['LARAVEL_STORAGE_PATH'], $_ENV['LARAVEL_STORAGE_PATH']);

        if ($server !== null) {
            $_SERVER['LARAVEL_STORAGE_PATH'] = $server;
        }

        if ($env !== null) {
            $_ENV['LARAVEL_STORAGE_PATH'] = $env;
        }

        if ($process !== false) {
            putenv('LARAVEL_STORAGE_PATH='.$process);
        }

        $desktop === false
            ? putenv('NATIVEPHP_STORAGE_PATH')
            : putenv('NATIVEPHP_STORAGE_PATH='.$desktop);
    }
}

it('resolves the storage root the phone announced, rather than the bundle it happens to be unpacked into', function (): void {
    $announced = withAnnouncedStorageRoot('/var/mobile/Containers/Data/Application/X/Library/Application Support/storage',
        static fn (): string => UserDataPathService::storageBase());

    expect($announced)
        ->toBe('/var/mobile/Containers/Data/Application/X/Library/Application Support/storage')
        ->not->toBe(base_path('storage'));
});

// Android hands its environment to PHP as server consts rather than through
// putenv(), which is why platformSignal() reads three sources. The same is
// true of this variable, and a bare getenv() would be blind on that half of
// the phones while looking perfectly correct on the other.
it('reads the announcement from whichever of the three places the shell put it', function (string $where): void {
    $root = withAnnouncedStorageRoot('/data/user/0/com.beatrax.mobile/files/persisted_data/storage',
        static fn (): string => UserDataPathService::storageBase(), $where);

    expect($root)->toBe('/data/user/0/com.beatrax.mobile/files/persisted_data/storage');
})->with(['server', 'env', 'process']);

// The desktop shell is checked first for the same reason appRoot() checks it
// first: a packaged desktop build must never fall through into a branch meant
// for a phone. Nothing sets both today, and the order is what keeps that true
// if something ever does.
it('lets the packaged desktop root win over an announcement', function (): void {
    $desktop = getenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_STORAGE_PATH=/Users/x/Library/Application Support/Beatrax');

    try {
        $_SERVER['LARAVEL_STORAGE_PATH'] = '/somewhere/else/storage';

        expect(UserDataPathService::storageBase())
            ->toBe('/Users/x/Library/Application Support/Beatrax');
    } finally {
        unset($_SERVER['LARAVEL_STORAGE_PATH']);
        $desktop === false
            ? putenv('NATIVEPHP_STORAGE_PATH')
            : putenv('NATIVEPHP_STORAGE_PATH='.$desktop);
    }
});

it('falls back to the bundle when no shell announced anything, which is a checkout and the test suite', function (): void {
    $root = withAnnouncedStorageRoot(null, static fn (): string => UserDataPathService::storageBase());

    expect($root)->toBe(base_path('storage'));
});

it('trims a trailing separator off the announcement, exactly as it does off the desktop root', function (): void {
    $root = withAnnouncedStorageRoot('/data/user/0/com.beatrax.mobile/files/persisted_data/storage/',
        static fn (): string => UserDataPathService::storageBase());

    expect($root)->toBe('/data/user/0/com.beatrax.mobile/files/persisted_data/storage');
});

// The whole point of the fix, stated as the thing that was wrong: the log
// file, and the framework tree beside it, follow the announced root. Before
// this they stayed in base_path(), which on iOS an update deletes.
it('takes the log file and the framework tree with it', function (): void {
    [$logs, $framework] = withAnnouncedStorageRoot('/announced/storage', static fn (): array => [
        UserDataPathService::logsFile(),
        UserDataPathService::frameworkPath('sessions'),
    ]);

    expect($logs)->toBe('/announced/storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log')
        ->and($framework)->toBe('/announced/storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions');
});

// And the half that must NOT move with it. On iOS the announced root is
// Application Support while the durable store is the sibling of base_path()
// under Documents — created and flagged out of iCloud by the shell before PHP
// starts. Collapsing appRoot() onto the announcement would put the keyring and
// the secrets in a tree that was never flagged.
it('leaves the durable half on the sibling store, which is a different tree on iOS', function (): void {
    $platform = getenv('NATIVEPHP_PLATFORM');
    putenv('NATIVEPHP_PLATFORM=ios');

    try {
        [$app, $secrets] = withAnnouncedStorageRoot('/announced/storage', static fn (): array => [
            UserDataPathService::appPath(),
            UserDataPathService::secretsPath(),
        ]);

        $store = dirname(base_path()).DIRECTORY_SEPARATOR.'persisted_data'
            .DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app';

        expect($app)->toBe($store)
            ->and($secrets)->toBe($store.DIRECTORY_SEPARATOR.'secrets')
            ->and($app)->not->toStartWith('/announced/storage');
    } finally {
        $platform === false
            ? putenv('NATIVEPHP_PLATFORM')
            : putenv('NATIVEPHP_PLATFORM='.$platform);
    }
});
