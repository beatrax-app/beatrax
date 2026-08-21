<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkEpochWrapSignature;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
function bikUser(string $username): User
{
    return User::query()->create([
        'username' => $username.'-'.bin2hex(random_bytes(3)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: string, 1: string} [senderDeviceId, ed25519SecretKeyHex]
 */
function bikConfirmedSender(int $userId): array
{
    $sigKp = sodium_crypto_sign_keypair();
    $boxKp = sodium_crypto_box_keypair();
    $deviceId = 'bik-sender-'.bin2hex(random_bytes(4));

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
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

    return [$deviceId, sodium_bin2hex(sodium_crypto_sign_secretkey($sigKp))];
}

/**
 * @return array{0: DeviceIdentityDto, 1: string, 2: string, 3: string} [self identity, senderId, senderSecretHex, peerKeyHex]
 */
function bikInboundWrapParts(User $user, Session $session): array
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);

    [$senderId, $senderSecretHex] = bikConfirmedSender((int) $user->id);

    return [$self, $senderId, $senderSecretHex, bin2hex(random_bytes(32))];
}

function bikDeliver(User $user, Session $session, DeviceIdentityDto $self, string $senderId, string $senderSecretHex, string $keyHex): void
{
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $wrap = $rotation->buildGdkEpochWrap(
        0,
        sodium_hex2bin($keyHex),
        sodium_hex2bin($self->x25519PublicKeyHex),
        $self->deviceId,
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
    );

    app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);
}

it('mints a blind-index key alongside the first epoch', function (): void {
    $user = bikUser('bik-mint');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBeString();
});

it('tags the fan-out wrap with its role so an epoch and a blind-index key cannot be confused', function (): void {
    $user = bikUser('bik-role');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);
    [$senderId, $senderSecretHex] = bikConfirmedSender((int) $user->id);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $raw = random_bytes(32);
    $pub = sodium_hex2bin($self->x25519PublicKeyHex);

    $epochWrap = $rotation->buildGdkEpochWrap(7, $raw, $pub, $self->deviceId, $senderId, $senderSecretHex);
    $blindWrap = $rotation->buildGdkEpochWrap(0, $raw, $pub, $self->deviceId, $senderId, $senderSecretHex, GdkEpochWrapSignature::ROLE_BLIND_INDEX);

    expect($epochWrap)->not->toHaveKey('key_role');
    expect($blindWrap['key_role'])->toBe(GdkEpochWrapSignature::ROLE_BLIND_INDEX);
    expect($blindWrap['sig_hex'])->not->toBe($epochWrap['sig_hex']);
});

it('adopts a peer blind-index key on a device that has derived nothing yet', function (): void {
    $user = bikUser('bik-adopt');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($peerKeyHex);
});

// Adopting here would leave every stored digest unmatchable by the value a
// re-import computes, which is exactly how a ledger doubles.
it('keeps the local blind-index key once this device has derived its counterparty keys under it', function (): void {
    $user = bikUser('bik-keep');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);
    $localKeyHex = $keyring->blindIndexKeyHex((int) $user->id, $session);

    app(DatabaseManager::class)->connection()->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->update(['counterparty_key_backfilled_at' => '2026-08-21 09:00:00']);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});

it('never adopts a blind-index key as an epoch', function (): void {
    $user = bikUser('bik-not-epoch');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);
    bikDeliver($user, $session, $self, $senderId, $senderSecretHex, $peerKeyHex);

    expect($keyring->loadKeyring((int) $user->id, $session)->keyFor(0))->toBeNull();
});

// The signature covers the role, so a wrap re-labelled as an epoch key no
// longer verifies and is refused before any seal is opened.
it('refuses a blind-index wrap whose role was stripped in transit', function (): void {
    $user = bikUser('bik-strip');
    /** @var Session $session */
    $session = app(Session::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);
    $localKeyHex = $keyring->blindIndexKeyHex((int) $user->id, $session);

    [$self, $senderId, $senderSecretHex, $peerKeyHex] = bikInboundWrapParts($user, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $wrap = $rotation->buildGdkEpochWrap(
        0,
        sodium_hex2bin($peerKeyHex),
        sodium_hex2bin($self->x25519PublicKeyHex),
        $self->deviceId,
        $senderId,
        $senderSecretHex,
        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
    );
    unset($wrap['key_role']);

    app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    expect($keyring->loadKeyring((int) $user->id, $session)->keyFor(0))->toBeNull();
    expect($keyring->blindIndexKeyHex((int) $user->id, $session))->toBe($localKeyHex);
});
