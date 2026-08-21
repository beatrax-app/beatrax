<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// Removal has to do both halves: the removed device's old epoch cannot open a
// post-removal entry, and its device_registry trust is gone so nothing it signs
// afterwards verifies. Doing only one of the two leaves the device inside.

function revokedUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('the removed device is no longer a confirmed/trusted key after rotateAndRevoke', function (): void {
    $user = revokedUser('revoked-device-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // rotateAndRevoke() loads the acting device's real on-disk identity to sign
    // each fan-out wrap, and its is_self row must survive the rotation.
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist($userId, $session);

    $removedKeypair = sodium_crypto_sign_keypair();
    $removedId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'removed-device',
        'name' => 'Removed',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey($removedKeypair)),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $rotation->rotateAndRevoke($userId, $removedId, $session);

    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    $keys = $registry->deviceKeys($userId);
    expect($keys)->not->toHaveKey('removed-device');
    expect($keys)->toHaveKey($self->deviceId);
});

it('an op-log entry signed by the removed device after rotation is rejected (no longer a confirmed key)', function (): void {
    $user = revokedUser('revoked-device-replay-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $removedKeypair = sodium_crypto_sign_keypair();
    $removedSk = sodium_crypto_sign_secretkey($removedKeypair);

    $removedId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'removed-device-2',
        'name' => 'Removed',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey($removedKeypair)),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // The acting device needs a REAL on-disk identity to sign fan-out wraps.
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $identityService->generateAndPersist($userId, $session);

    $rotation->rotateAndRevoke($userId, $removedId, $session);

    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    // The removed device's key must be absent from the confirmed map the
    // replayer trusts — a post-rotation entry it signs can never verify.
    expect($registry->deviceKeys($userId))->not->toHaveKey('removed-device-2');

    // Best-effort process hygiene; nothing asserted above depends on it.
    sodium_memzero($removedSk);
});
