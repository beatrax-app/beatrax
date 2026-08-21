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

// Adding a device wraps every epoch the keyring holds, not just the current
// one: a device given only the newest key can read nothing written before it
// joined, and the op log has no path to resend what it could not decrypt.

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

    $keyring->generateAndPersist((int) $user->id, $session); // epoch 1

    // The acting device needs a real on-disk identity to sign each wrap, and
    // its is_self row is what excludes it from every fan-out.
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

    // Drains the rotation's own fan-out, so the assertions below see only what
    // the add-device path enqueued.
    $db->connection()->table('relay_mailbox')->delete();

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

        // Each wrap must open with the recipient's real secret key onto the
        // right raw key for that epoch.
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

    $afterCurrentEpoch = $keyring->currentEpoch((int) $user->id, $session);
    expect($afterCurrentEpoch->epochId)->toBe($beforeCurrentEpoch->epochId);

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

    // SQLite rowids are reused across the per-test rollback, so an earlier
    // test in this process can have left a keyring on disk under the same
    // numeric user id. This starts from a genuinely empty on-disk state.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // An identity but an empty keyring: sync on, encryption never enabled. The
    // fan-out loads the identity, finds no epochs, and enqueues nothing.
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
