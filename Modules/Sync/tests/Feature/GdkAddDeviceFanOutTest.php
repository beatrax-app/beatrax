<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

/*
 * GdkAddDeviceFanOutTest — Task 3 service half (Phase 15 import-join, B1):
 * fanOutAllEpochsToDevice() wraps EVERY epoch in the keyring (not just a
 * rotated one) sealed to a single newly-confirmed device — the ADD-device
 * analog of GdkRotationServiceTest's removal-fan-out coverage.
 *
 * 6a per 15-import-join-PLAN.md's test-plan.
 */

function fanOutUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return int device_registry.id
 */
function fanOutDeviceRow(
    DatabaseManager $db,
    int $userId,
    string $deviceId,
    string $x25519PublicKeyHex,
    bool $isSelf,
    bool $confirmed,
): int {
    return $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => $x25519PublicKeyHex,
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-07-11T10:00:00Z',
        'confirmed_at' => $confirmed ? '2026-07-11T10:05:00Z' : null,
        'last_seen_at' => null,
        'created_at' => '2026-07-11T10:00:00Z',
        'updated_at' => '2026-07-11T10:00:00Z',
    ]);
}

it('wraps ALL keyring epochs (not just the latest) to a single newly-confirmed device, with no rotation side effect', function (): void {
    $user = fanOutUser('fanout-all-epochs');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);

    // Enable encryption then rotate twice — keyring now holds epochs 1,2,3.
    $keyring->generateAndPersist((int) $user->id, $session); // epoch 1

    // The acting/self device needs a REAL on-disk identity: rotateAndRevoke()
    // and fanOutAllEpochsToDevice() both now load it to SIGN each wrap. Its
    // is_self=1 registry row is what excludes it from every fan-out.
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);
    $throwaway1 = fanOutDeviceRow($db, (int) $user->id, 'throwaway-1', bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())), isSelf: false, confirmed: true);
    $throwaway2 = fanOutDeviceRow($db, (int) $user->id, 'throwaway-2', bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())), isSelf: false, confirmed: true);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $rotation->rotateAndRevoke((int) $user->id, $throwaway1, $session); // epoch 2, revokes throwaway-1
    $rotation->rotateAndRevoke((int) $user->id, $throwaway2, $session); // epoch 3, revokes throwaway-2

    // Three distinct epochs are held; their ids are minted rather than
    // counted, so what matters is that the keyring has all three and points
    // at the newest — not that they happen to read 1, 2, 3.
    $beforeCurrentEpoch = $keyring->currentEpoch((int) $user->id, $session);
    expect($keyring->loadKeyring((int) $user->id, $session)->epochs())->toHaveCount(3);

    // Drain the rotation fan-out noise so this test's own assertions are
    // scoped to what fanOutAllEpochsToDevice() itself enqueues.
    $db->connection()->table('relay_mailbox')->delete();

    // A real confirmed recipient with a genuine X25519 identity.
    $recipientKeypair = sodium_crypto_box_keypair();
    $recipientSecret = sodium_crypto_box_secretkey($recipientKeypair);
    $recipientPublic = sodium_crypto_box_publickey($recipientKeypair);
    $recipientId = fanOutDeviceRow($db, (int) $user->id, 'device-b-phone', sodium_bin2hex($recipientPublic), isSelf: false, confirmed: true);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    $gateway->deliverAllEpochsToDevice((int) $user->id, $recipientId, $session);

    $wraps = $db->connection()->table('relay_mailbox')
        ->where('recipient_did', 'device-b-phone')
        ->whereNull('delivered_at')
        ->get();

    expect($wraps)->toHaveCount(3, 'every keyring epoch (1, 2, 3) must be wrapped for the new device');

    $seenEpochIds = [];
    foreach ($wraps as $row) {
        /** @var array<string, mixed> $wrap */
        $wrap = json_decode((string) $row->blob, true, 8, JSON_THROW_ON_ERROR);
        expect($wrap['type'])->toBe('GDK_EPOCH_WRAP');
        expect($wrap['recipient_device_id'])->toBe('device-b-phone');

        $epochId = (int) $wrap['epoch_id'];
        $seenEpochIds[] = $epochId;

        // Each wrap opens with device-b's real secret key to the CORRECT
        // raw key for that epoch (reuses GdkEpochDeliveryTest's open-and-
        // decrypt assertion shape).
        $sealed = base64_decode((string) $wrap['wrapped_key_b64'], true);
        expect($sealed)->not->toBeFalse();
        $recipientKp = sodium_crypto_box_keypair_from_secretkey_and_publickey($recipientSecret, $recipientPublic);
        $opened = sodium_crypto_box_seal_open((string) $sealed, $recipientKp);
        expect($opened)->not->toBeFalse();

        $expectedKeyHex = $keyring->loadKeyring((int) $user->id, $session)->keyFor($epochId);
        expect($expectedKeyHex)->not->toBeNull();
        expect(sodium_bin2hex((string) $opened))->toBe($expectedKeyHex);
    }

    // Every epoch the keyring holds must have been wrapped — compared against
    // the keyring itself rather than a literal, because ids are minted.
    $heldEpochIds = array_map(
        static fn (GdkEpoch $epoch): int => $epoch->epochId,
        $keyring->loadKeyring((int) $user->id, $session)->epochs(),
    );

    sort($seenEpochIds);
    sort($heldEpochIds);
    expect($seenEpochIds)->toBe($heldEpochIds);

    // current_epoch UNCHANGED — no rotation, no revoke, no new epoch minted.
    $afterCurrentEpoch = $keyring->currentEpoch((int) $user->id, $session);
    expect($afterCurrentEpoch->epochId)->toBe($beforeCurrentEpoch->epochId);

    // No confirmed_at cleared / no revoke side effect anywhere.
    $recipientRow = $db->connection()->table('device_registry')->where('id', $recipientId)->first();
    expect($recipientRow->confirmed_at)->not->toBeNull();
});

it('enqueues zero wraps for an unconfirmed recipient', function (): void {
    $user = fanOutUser('fanout-unconfirmed');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    $recipientId = fanOutDeviceRow(
        $db,
        (int) $user->id,
        'device-unconfirmed',
        bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        isSelf: false,
        confirmed: false,
    );

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    $gateway->deliverAllEpochsToDevice((int) $user->id, $recipientId, $session);

    expect(
        $db->connection()->table('relay_mailbox')->where('recipient_did', 'device-unconfirmed')->count()
    )->toBe(0, 'an unconfirmed recipient must receive zero wraps even if this method is called directly');
});

it('enqueues zero wraps for the acting self device (no wrap-to-self)', function (): void {
    $user = fanOutUser('fanout-self');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    $selfId = fanOutDeviceRow(
        $db,
        (int) $user->id,
        'self-device-2',
        bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        isSelf: true,
        confirmed: true,
    );

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    $gateway->deliverAllEpochsToDevice((int) $user->id, $selfId, $session);

    expect(
        $db->connection()->table('relay_mailbox')->where('recipient_did', 'self-device-2')->count()
    )->toBe(0, 'no wrap-to-self');
});

it('enqueues zero wraps for an empty keyring (encryption never enabled)', function (): void {
    $user = fanOutUser('fanout-empty-keyring');

    // A prior, unrelated test in this same process may have left a keyring
    // file on disk for this same reused numeric user id (SQLite rowids are
    // reused across RefreshDatabase transaction rollbacks —
    // GdkEpochDeliveryTest's own documented cross-test filesystem-isolation
    // gap). Start from a genuinely empty on-disk state.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The acting device has an identity (sync enabled) but an EMPTY keyring
    // (encryption never enabled): fanOutAllEpochsToDevice() loads the identity
    // to sign wraps, then finds zero epochs and enqueues nothing.
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    $recipientId = fanOutDeviceRow(
        $db,
        (int) $user->id,
        'device-empty-keyring',
        bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        isSelf: false,
        confirmed: true,
    );

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);
    $gateway->deliverAllEpochsToDevice((int) $user->id, $recipientId, $session);

    expect(
        $db->connection()->table('relay_mailbox')->where('recipient_did', 'device-empty-keyring')->count()
    )->toBe(0, 'an empty keyring (encryption never enabled) enqueues zero wraps — benign, synced-but-unencrypted');
});
