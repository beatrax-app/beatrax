<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\DeviceIdentityFile;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// hasLocalDevice() decides whether the desktop binds a sync port and a relay
// port at boot, and its own callers say what it is for: without a sync identity
// no peer can dial in. It read the registry row. A restored database carries
// that row and never the key-file, so the daemon came up, answered, and refused
// every handshake it was offered — which the phone reports as a network fault.

function aBootGateUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('boot-gate-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    foreach (['identity', 'gdk'] as $directory) {
        foreach ((array) glob(UserDataPathService::appPath(sprintf('sync/%s/%s.enc*', $directory, $user->id))) as $stale) {
            @unlink((string) $stale);
        }
    }

    return $user;
}

it('is not worth a port on a device whose key-file never travelled', function (): void {
    $user = aBootGateUser('boot-gate-restored');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $this->app->make(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    expect($registry->hasLocalDevice())->toBeTrue();

    // The restore: the database arrives whole and the key-file cannot come
    // with it, because the private halves never leave the device that made them.
    @unlink(DeviceIdentityFile::path((int) $user->id));

    expect($registry->hasLocalDevice())->toBeFalse()
        ->and($db->connection()->table('device_registry')->where('is_self', 1)->exists())->toBeTrue();
});

// The ordinary desktop, and the reason this is not simply "is the app unlocked":
// a locked device holds a key-file it cannot open, the daemon is spawned keyless
// on purpose, and the unlock is what credentials it. That has to keep working.
it('still binds a port for a locked device that holds an identity it cannot open yet', function (): void {
    $user = aBootGateUser('boot-gate-locked');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    $this->app->make(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    expect($registry->hasLocalDevice())->toBeTrue();
});

it('is not worth a port on a device that never enabled sync', function (): void {
    aBootGateUser('boot-gate-never');

    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    expect($registry->hasLocalDevice())->toBeFalse();
});
