<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;

uses(RefreshDatabase::class);

// A rebuild replays the whole history at once, so it decrypts entries written
// under every epoch the device has ever held. That only works because the
// keyring is append-only: drop an old epoch and a rebuild silently quarantines
// everything written under it.

function convergenceUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('a full OpLogRebuilder rebuild after multiple GDK rotations converges to the same projection as incremental replay', function (): void {
    $user = convergenceUser('convergence-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $keyring->generateAndPersist($userId, $session);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Pre-rotation category',
        'slug' => 'pre-rotation-category',
        'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    // The acting device needs a real on-disk identity, because the rotation
    // loads it to sign each fan-out wrap.
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $identityService->generateAndPersist($userId, $session);
    $removedDeviceId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => 'removed-device',
        'name' => 'Removed',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);

    // After this the rename above sits under the old epoch and everything
    // later sits under the new one.
    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);
    $rotation->rotateAndRevoke($userId, $removedDeviceId, $session);

    $beforeRebuild = $db->connection()->table('categories')->where('id', $categoryId)->value('name');

    /** @var OpLogRebuilder $rebuilder */
    $rebuilder = $this->app->make(OpLogRebuilder::class);
    $rebuilder->rebuild($userId);

    $afterRebuild = $db->connection()->table('categories')->where('id', $categoryId)->value('name');

    expect($afterRebuild)->toBe($beforeRebuild);
    expect($afterRebuild)->toBe('Pre-rotation category');

    // Nothing may quarantine as undecryptable: every historical epoch has to
    // still resolve after the rebuild.
    $quarantinedAfterRebuild = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();

    expect($quarantinedAfterRebuild)->toBe(0);
});
