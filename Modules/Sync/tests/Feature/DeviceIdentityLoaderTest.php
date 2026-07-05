<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

/*
 * DeviceIdentityLoaderTest — decrypt + load the device identity key-file
 * (PAIR-01, D-01), including the security fix for the plaintext-key-in-
 * sys_get_temp_dir() finding: the decrypted plaintext is staged inside the
 * 0700 sync/identity directory and locked to 0600, never in
 * sys_get_temp_dir().
 */

function loaderIdentityUser(string $username = 'loader-identity-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('identity-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('round-trips: generateAndPersist() then load() returns the same identity', function (): void {
    $user = loaderIdentityUser('loader-roundtrip');

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $saved = $service->generateAndPersist((int) $user->id, $session);

    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    $loaded = $loader->load((int) $user->id, $session);

    expect($loaded)->not->toBeNull();
    expect($loaded->deviceId)->toBe($saved->deviceId);
    expect($loaded->userId)->toBe($saved->userId);
    expect($loaded->ed25519SecretKeyHex)->toBe($saved->ed25519SecretKeyHex);
    expect($loaded->ed25519PublicKeyHex)->toBe($saved->ed25519PublicKeyHex);
    expect($loaded->x25519SecretKeyHex)->toBe($saved->x25519SecretKeyHex);
    expect($loaded->x25519PublicKeyHex)->toBe($saved->x25519PublicKeyHex);
    expect($loaded->createdAt)->toBe($saved->createdAt);
});

it('never stages the decrypted plaintext in sys_get_temp_dir(), and cleans it up after load()', function (): void {
    $user = loaderIdentityUser('loader-no-systmp');

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    $before = (array) glob(sys_get_temp_dir().'/beatrax_identity_*');

    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);
    $loader->load((int) $user->id, $session);

    $after = (array) glob(sys_get_temp_dir().'/beatrax_identity_*');
    expect($after)->toBe($before, 'No beatrax_identity_* files should ever appear in sys_get_temp_dir().');

    $encPath = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    $identityDir = dirname($encPath);
    $leftoverTmp = (array) glob($identityDir.'/*.tmp');
    expect($leftoverTmp)->toBe([], 'The identity directory must not retain any staged .tmp file after load().');
});

it('returns null when sync was never enabled for the user (no key-file)', function (): void {
    $user = loaderIdentityUser('loader-never-enabled');

    // RefreshDatabase resets the DB but not real on-disk storage/app files —
    // an earlier test run may have left a key-file behind for this same
    // (reused) user id. Ensure the precondition this test actually cares
    // about — "no key-file exists for this user" — holds regardless of
    // leftover state from prior runs.
    @unlink(UserDataPathService::appPath("sync/identity/{$user->id}.enc"));

    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect($loader->load((int) $user->id, $session))->toBeNull();
});

it('returns null when the app-lock KEK is unavailable', function (): void {
    $user = loaderIdentityUser('loader-nokek');

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    // A fake AppLockKeyService whose release() returns null (locked / no app-lock).
    $this->app->bind(AppLockKeyService::class, fn () => new class extends AppLockKeyService
    {
        public function __construct() {}

        public function release(Session $session): ?string
        {
            return null;
        }
    });

    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    expect($loader->load((int) $user->id, $session))->toBeNull();
});
