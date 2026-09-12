<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// A rename does not join a SQLite transaction and does not roll back, so the
// rotation stages the keyring inside the transaction and renames it only after
// the commit — the seam epoch 1 already used. A rollback then leaves no file at
// all describing an epoch the database never named.

function keyringStageUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function keyringStageDevice(DatabaseManager $db, int $userId, string $deviceId, string $x25519Hex): int
{
    return $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => $x25519Hex,
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

function keyringStageRealX25519(): string
{
    return bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
}

/**
 * @return list<string>
 */
function keyringStageTmpFiles(int $userId): array
{
    return glob(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc').'.*.tmp') ?: [];
}

// RefreshDatabase resets the autoincrement but not the filesystem, so a
// keyring or a stage left by an earlier run against the same reused user id
// would make the file assertions below pass or fail for the wrong reason.
function keyringStageForget(int $userId): void
{
    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    foreach (keyringStageTmpFiles($userId) as $stale) {
        @unlink($stale);
    }
}

it('leaves the keyring file untouched until the rotation transaction is over, and renames it then', function (): void {
    $user = keyringStageUser('keyring-stage-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    keyringStageForget($userId);

    $initial = $keyring->generateAndPersist($userId, $session);

    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $identityService->generateAndPersist($userId, $session);

    $removedId = keyringStageDevice($db, $userId, 'removed-device', keyringStageRealX25519());
    keyringStageDevice($db, $userId, 'remaining-device', keyringStageRealX25519());

    // loadKeyring() re-writes a keyring encrypted at password-hardening cost,
    // which would move the fingerprint for a reason this test is not about.
    $keyring->loadKeyring($userId, $session);
    $before = $keyring->keyringFingerprint($userId);

    $atRevoke = null;
    $atEpochPointer = null;

    $db->connection()->listen(function (QueryExecuted $query) use (&$atRevoke, &$atEpochPointer, $keyring, $userId): void {
        if ($atRevoke === null && str_starts_with($query->sql, 'update "device_registry"')) {
            $atRevoke = $keyring->keyringFingerprint($userId);
        }

        if ($atEpochPointer === null && str_contains($query->sql, '"sync_encryption_state"')
            && ! str_starts_with($query->sql, 'select')) {
            $atEpochPointer = $keyring->keyringFingerprint($userId);
        }
    });

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);
    $rotation->rotateAndRevoke($userId, $removedId, $session);

    $after = $keyring->keyringFingerprint($userId);

    expect($before)->toBeString();
    expect($after)->not->toBe($before);

    // The revoke is the transaction's first write and the epoch pointer the
    // next. The real keyring path still holds the pre-rotation bytes at both.
    expect($atRevoke)->toBe($before);
    expect($atEpochPointer)->toBe($before);

    expect($keyring->currentEpoch($userId, $session)->epochId)->not->toBe($initial->epochId);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toHaveCount(2);
    expect(keyringStageTmpFiles($userId))->toBe([]);
});

it('leaves no keyring file naming the new epoch when the rotation rolls back', function (): void {
    $user = keyringStageUser('keyring-stage-rollback-user');
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    keyringStageForget($userId);

    $initial = $keyring->generateAndPersist($userId, $session);

    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $identityService->generateAndPersist($userId, $session);

    $removedId = keyringStageDevice($db, $userId, 'removed-device', keyringStageRealX25519());

    // An unparseable public key makes the fan-out throw inside the
    // transaction, which is the rollback this test is about.
    keyringStageDevice($db, $userId, 'corrupt-device', 'not-hexadecimal-at-all');

    $keyring->loadKeyring($userId, $session);
    $before = $keyring->keyringFingerprint($userId);
    $threw = false;

    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    try {
        $rotation->rotateAndRevoke($userId, $removedId, $session);
    } catch (CryptoOperationFailedException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $userId)->first();
    expect((int) $state->current_epoch)->toBe($initial->epochId);

    $removed = $db->connection()->table('device_registry')->where('id', $removedId)->first();
    expect($removed->confirmed_at)->not->toBeNull();

    // The residual this ordering removes: the keyring used to come out of a
    // rolled-back rotation holding an extra epoch nothing named. It holds one,
    // it is byte-identical to what went in, and no stage is left behind.
    expect($keyring->loadKeyring($userId, $session)->epochs())->toHaveCount(1);
    expect($keyring->currentEpoch($userId, $session)->keyHex)->toBe($initial->keyHex);
    expect($keyring->keyringFingerprint($userId))->toBe($before);
    expect(keyringStageTmpFiles($userId))->toBe([]);
});
