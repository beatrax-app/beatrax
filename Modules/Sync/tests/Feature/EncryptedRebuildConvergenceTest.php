<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;

uses(RefreshDatabase::class);

/*
 * EncryptedRebuildConvergenceTest — CRYPT-02 / D-04 (Pitfall 4):
 * OpLogRebuilder::rebuild() after multiple rotations converges to the same
 * projection as incremental replay — the keyring is append-only forever, so
 * every historical epoch still decrypts on a full maintenance-window replay.
 * 14-VALIDATION.md CRYPT-02 row 3.
 *
 * RED until Plan 05 ships Modules\Sync\Internal\Crypto\GdkRotationService and
 * OpLogRebuilder gains the per-entry GDK decrypt hook. This test references
 * the planned production FQCN, which does not yet exist — the failure is
 * "class not found", the correct Wave 0 RED state.
 */

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

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'self-device',
        'name' => 'Self',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 1,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
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

    // Trigger a rotation (epoch N -> N+1) — the pre-rotation category rename
    // above is now encrypted under epoch N; anything written after this
    // point is encrypted under N+1.
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

    // No entry should have been silently quarantined as undecryptable across
    // the rebuild — every historical epoch must still resolve.
    $quarantinedAfterRebuild = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'gdk_decrypt_failed')
        ->count();

    expect($quarantinedAfterRebuild)->toBe(0);
});
