<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;

uses(RefreshDatabase::class);

// A joining phone acknowledged both epoch wraps the desktop pushed at pairing
// and stored neither, seven seconds before it stamped the sender's
// confirmed_at. fanOutAllEpochsToDevice() fires once and RelayMailbox has no
// re-send, so that was the only copy: the device kept 4,223 op-log entries it
// could not read and sat on "Waiting for the encryption keys" for good.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
const WOR_EPOCH = 7788;

function worUser(): User
{
    return User::query()->create([
        'username' => 'wor-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  ?string  $confirmedAt  Null is the pairing instant this test is about:
 *                                the wrap is already on the wire and the row
 *                                naming its sender has not been stamped yet.
 * @return array{0: DeviceIdentityDto, 1: string, 2: string}
 */
function worSelfAndSender(User $user, Session $session, ?string $confirmedAt): array
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);

    $sigKp = sodium_crypto_sign_keypair();
    $boxKp = sodium_crypto_box_keypair();
    $senderId = 'wor-sender-'.bin2hex(random_bytes(4));

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $senderId,
        'name' => 'Mac',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-09-01T23:34:20Z',
        'confirmed_at' => $confirmedAt,
        'last_seen_at' => null,
        'created_at' => '2026-09-01T23:34:20Z',
        'updated_at' => '2026-09-01T23:34:20Z',
    ]);

    return [$self, $senderId, sodium_bin2hex(sodium_crypto_sign_secretkey($sigKp))];
}

function worWrapJson(DeviceIdentityDto $self, string $senderId, string $senderSecretHex): string
{
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    return json_encode($rotation->buildGdkEpochWrap(
        WOR_EPOCH,
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        $senderId,
        $senderSecretHex,
    ), JSON_THROW_ON_ERROR);
}

function worInboxCount(string $recipientDid): int
{
    return app(DatabaseManager::class)->connection()
        ->table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->count();
}

it('keeps a wrap that arrived before the sender was confirmed', function (): void {
    $user = worUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = worSelfAndSender($user, $session, confirmedAt: null);
    $json = worWrapJson($self, $senderId, $senderSecretHex);

    $accounted = app(GdkEpochDeliveryGateway::class)->receiveEpochWrap(
        $json,
        (int) $user->id,
        $senderId,
        $self->deviceId,
        $session,
    );

    // Acknowledging is only truthful if the blob was kept: the sender retires
    // its copy on the strength of this answer.
    expect($accounted)->toBeTrue();
    expect(worInboxCount($self->deviceId))->toBe(1);
});

it('installs the epoch once the sender is confirmed', function (): void {
    $user = worUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = worSelfAndSender($user, $session, confirmedAt: null);
    $json = worWrapJson($self, $senderId, $senderSecretHex);

    app(GdkEpochDeliveryGateway::class)->receiveEpochWrap(
        $json, (int) $user->id, $senderId, $self->deviceId, $session,
    );

    // The confirm the phone stamps seconds later, and the unlocked pass that
    // leg 3 exists to run.
    app(DatabaseManager::class)->connection()->table('device_registry')
        ->where('device_id', $senderId)
        ->update(['confirmed_at' => '2026-09-01T23:34:30Z']);

    app(GdkEpochDeliveryGateway::class)->drainInbox((int) $user->id, $self->deviceId, $session);

    expect(app(GdkKeyringService::class)->loadKeyring((int) $user->id, $session)->keyFor(WOR_EPOCH))
        ->not->toBeNull();
});
