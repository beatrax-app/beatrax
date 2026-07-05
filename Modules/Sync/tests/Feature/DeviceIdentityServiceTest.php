<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

/*
 * DeviceIdentityServiceTest — PAIR-01 key lifecycle + app-lock gate (D-01/D-02).
 *
 * RED until Plan 02 ships DeviceIdentityService. These tests reference the
 * planned production FQCN Modules\Sync\Internal\Identity\DeviceIdentityService,
 * which does not yet exist — the failure is "class not found", the correct
 * Wave 0 RED state.
 */

function identityUser(string $username = 'identity-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('identity-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('generates an Ed25519 + X25519 identity and writes an encrypted key-file under UserDataPathService', function (): void {
    $user = identityUser();

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dto = $service->generateAndPersist((int) $user->id, $session);

    // The DTO carries both keypairs in hex.
    expect($dto->ed25519PublicKeyHex)->toHaveLength(64);
    expect($dto->x25519PublicKeyHex)->toHaveLength(64);
    expect($dto->userId)->toBe((int) $user->id);

    // Key-file lands under the sanctioned UserDataPathService path, encrypted.
    $encPath = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    expect(file_exists($encPath))->toBeTrue();

    // The on-disk blob is NOT plaintext JSON (BackupEncryptor-encrypted).
    $blob = (string) file_get_contents($encPath);
    expect($blob)->not->toContain($dto->ed25519SecretKeyHex);
});

it('persists a device_registry self-row for the generated identity', function (): void {
    $user = identityUser('identity-selfrow');

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $dto = $service->generateAndPersist((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $self = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $dto->deviceId)
        ->first();

    expect($self)->not->toBeNull();
    expect((int) $self->is_self)->toBe(1);
    expect($self->ed25519_public_key_hex)->toBe($dto->ed25519PublicKeyHex);
});

it('never stages the plaintext key-file in sys_get_temp_dir(), and cleans it up after the call', function (): void {
    $user = identityUser('identity-no-systmp');

    $before = (array) glob(sys_get_temp_dir().'/beatrax_identity_*');

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    // Security fix (plaintext-key-in-sys_get_temp_dir finding): the staged
    // key-file must never appear under the system temp directory, before or
    // after the call — it is staged and cleaned up entirely inside the
    // sanctioned identity directory.
    $after = (array) glob(sys_get_temp_dir().'/beatrax_identity_*');
    expect($after)->toBe($before, 'No beatrax_identity_* files should ever appear in sys_get_temp_dir().');

    // The identity directory itself carries no leftover .tmp staging files —
    // the finally-unlink ran.
    $encPath = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    $identityDir = dirname($encPath);
    $leftoverTmp = (array) glob($identityDir.'/*.tmp');
    expect($leftoverTmp)->toBe([], 'The identity directory must not retain any staged .tmp file after generateAndPersist().');
});

it('creates the identity directory at 0700 (not world-traversable)', function (): void {
    $user = identityUser('identity-dir-perms');

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $service->generateAndPersist((int) $user->id, $session);

    $encPath = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    $identityDir = dirname($encPath);

    expect(fileperms($identityDir) & 0o777)->toBe(0o700, 'The sync/identity directory must be mode 0700.');
});

it('it_throws_without_app_lock_kek', function (): void {
    $user = identityUser('identity-nokek');

    // A fake AppLockKeyService whose release() returns null (locked / no app-lock).
    $this->app->bind(AppLockKeyService::class, fn () => new class extends AppLockKeyService
    {
        public function __construct() {}

        public function release(Session $session): ?string
        {
            return null;
        }
    });

    /** @var DeviceIdentityService $service */
    $service = $this->app->make(DeviceIdentityService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    expect(fn () => $service->generateAndPersist((int) $user->id, $session))
        ->toThrow(LogicException::class);
});
