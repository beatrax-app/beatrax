<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapOutcome;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// `sync:serve` resolves a Session that no middleware ever started, so
// AppLockKeyService::release() returns null unconditionally. A wrap it cannot
// open must stay in the mailbox: nothing re-sends one, and the fan-out that
// produced it fires once, at pairing.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
function kdwUser(): User
{
    return User::query()->create([
        'username' => 'kdw-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: DeviceIdentityDto, 1: string, 2: string}
 */
function kdwSelfAndSender(User $user, Session $session): array
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);

    $sigKp = sodium_crypto_sign_keypair();
    $boxKp = sodium_crypto_box_keypair();
    $senderId = 'kdw-sender-'.bin2hex(random_bytes(4));

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $senderId,
        'name' => $senderId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    return [$self, $senderId, sodium_bin2hex(sodium_crypto_sign_secretkey($sigKp))];
}

function kdwEpochWrapJson(DeviceIdentityDto $self, string $senderId, string $senderSecretHex): string
{
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    return json_encode($rotation->buildGdkEpochWrap(
        4242,
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        sodium_hex2bin($self->x25519PublicKeyHex),
        $self->deviceId,
        $senderId,
        $senderSecretHex,
    ), JSON_THROW_ON_ERROR);
}

function kdwHandler(User $user, string $localDeviceId): SyncWebSocketHandler
{
    return new SyncWebSocketHandler(
        registryService: app(DeviceRegistryService::class),
        signer: app(DeviceKeySigner::class),
        framer: app(TransportFramer::class),
        catchUp: app(PeerCatchUpExchanger::class),
        db: app(DatabaseManager::class),
        clock: app(Clock::class),
        logger: app(LoggerInterface::class),
        localStaticSecret: str_repeat("\x01", 32),
        localStaticPublic: str_repeat("\x02", 32),
        localDeviceId: $localDeviceId,
        userId: (int) $user->id,
    );
}

function kdwUndeliveredCount(User $user, string $recipientDid): int
{
    return app(DatabaseManager::class)->connection()
        ->table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->whereNull('delivered_at')
        ->count();
}

it('defers rather than refuses when the identity file exists but no key is held', function (): void {
    $user = kdwUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = kdwSelfAndSender($user, $session);
    $json = kdwEpochWrapJson($self, $senderId, $senderSecretHex);

    app(AppLockKeyService::class)->withhold($session);

    expect(app(GdkEpochControlHandler::class)->handle($json, (int) $user->id, $session))
        ->toBe(GdkWrapOutcome::Deferred);
});

it('refuses, not defers, when sync was never enabled for this user', function (): void {
    $user = kdwUser();
    $other = kdwUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = kdwSelfAndSender($user, $session);
    $json = kdwEpochWrapJson($self, $senderId, $senderSecretHex);

    // No identity file was ever written for this user, so there is nothing to
    // wait for and redelivering would reach the same answer.
    expect(app(GdkEpochControlHandler::class)->handle($json, (int) $other->id, $session))
        ->toBe(GdkWrapOutcome::Refused);
});

// The whole point: the daemon's drain must not consume what it cannot open.
it('leaves an unopenable wrap in the mailbox for a later unlocked drain', function (): void {
    $user = kdwUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self, $senderId, $senderSecretHex] = kdwSelfAndSender($user, $session);
    $json = kdwEpochWrapJson($self, $senderId, $senderSecretHex);

    /** @var RelayMailbox $mailbox */
    $mailbox = app(RelayMailbox::class);
    $mailbox->deliver(senderDid: $senderId, recipientDid: $self->deviceId, blob: $json);

    expect(kdwUndeliveredCount($user, $self->deviceId))->toBe(1);

    app(AppLockKeyService::class)->withhold($session);

    kdwHandler($user, $self->deviceId)->drainInboundEpochWraps();

    expect(kdwUndeliveredCount($user, $self->deviceId))->toBe(1);

    // And nothing was half-applied: the epoch it carried is still absent once
    // a key is held again, so the deferred row is the only copy there is.
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    expect(app(GdkKeyringService::class)->loadKeyring((int) $user->id, $session)->keyFor(4242))->toBeNull();
});

// A pairing frame sharing the mailbox must not be eaten by the wrap drain —
// the guard pendingWrapsForPeer() already applies in the other direction.
it('leaves a foreign pairing frame in the mailbox instead of confirming it away', function (): void {
    $user = kdwUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    [$self] = kdwSelfAndSender($user, $session);

    /** @var RelayMailbox $mailbox */
    $mailbox = app(RelayMailbox::class);
    $mailbox->deliver(
        senderDid: 'kdw-peer',
        recipientDid: $self->deviceId,
        blob: json_encode(['type' => 'PAIR_CONFIRM', 'payload' => 'x'], JSON_THROW_ON_ERROR),
    );

    kdwHandler($user, $self->deviceId)->drainInboundEpochWraps();

    expect(kdwUndeliveredCount($user, $self->deviceId))->toBe(1);
});
