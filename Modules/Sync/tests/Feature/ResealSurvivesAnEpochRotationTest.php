<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SealedLedgerRecovery;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\EncryptionMigrationSupport;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// Removing a device appends an epoch and advances current_epoch, and nothing
// re-encrypts the projection columns behind it. So on any device that has ever
// revoked a peer, correctly sealed rows sit under an epoch that is no longer
// the current one — which is what a re-seal pass has to survive.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee
 */
function resealRotationUser(): User
{
    return User::query()->create([
        'username' => 'rotation-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function resealRotationRevokeAPeer(User $user, Session $session): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $peerId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $user->id,
        'device_id' => 'rotation-peer',
        'name' => 'Retired phone',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);

    app(GdkRotationService::class)->rotateAndRevoke((int) $user->id, $peerId, $session);
}

it('leaves a value sealed under a superseded epoch exactly as it found it', function (): void {
    $user = resealRotationUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('notifications')->insert([
        'id' => str_repeat('e', 64),
        'user_id' => $user->id,
        'state' => 'open',
        'title' => 'Zilveren Kruis premium is due',
        'body' => 'Your health insurer takes EUR 142.10 on the 24th.',
        'params' => null,
        'trigger_type' => 'bill_due',
        'created_at' => '2026-07-01 09:00:00',
        'updated_at' => '2026-07-01 09:00:00',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);

    $sealedUnderEpochOne = (string) $db->connection()
        ->table('notifications')->where('id', str_repeat('e', 64))->value('title');

    resealRotationRevokeAPeer($user, $session);

    // The predicate the enable-time sweep skips an already-encrypted value
    // with asks the CURRENT epoch alone, so past a rotation it reports a
    // correctly sealed value as plaintext. A pass driven off it would wrap
    // this ciphertext a second time.
    /** @var EncryptionMigrationSupport $support */
    $support = app(EncryptionMigrationSupport::class);
    $support->primeCurrentEpoch((int) $user->id, $session);
    expect($support->alreadyEncryptedProjectionValue('notifications', 'title', $sealedUnderEpochOne))->toBeFalse();

    $db->connection()->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->update(['resealed_columns_digest' => null]);

    app(SealedLedgerRecovery::class)->recover((int) $user->id, $session);

    $afterReseal = (string) $db->connection()
        ->table('notifications')->where('id', str_repeat('e', 64))->value('title');

    expect($afterReseal)->toBe($sealedUnderEpochOne);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('notifications', 'title', $afterReseal, (int) $user->id, $session))
        ->toBe(['value' => 'Zilveren Kruis premium is due', 'decrypted' => true]);
});
