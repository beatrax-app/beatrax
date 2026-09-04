<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// A completed handshake proves the peer holds the key it presented and nothing
// more. The identities a session may run on are the ones pairing confirmed, so
// the admission decision is a lookup in that registry and there is no second
// place a key can be trusted from.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
function admissionUser(): User
{
    return User::query()->create([
        'username' => 'admission-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: NoiseSession, 1: string}
 */
function admissionHandshakeAgainstAStranger(): array
{
    $strangerKeypair = sodium_crypto_kx_keypair();
    $strangerSecret = sodium_crypto_kx_secretkey($strangerKeypair);
    $strangerPublic = sodium_crypto_kx_publickey($strangerKeypair);

    $localKeypair = sodium_crypto_kx_keypair();
    $localSecret = sodium_crypto_kx_secretkey($localKeypair);
    $localPublic = sodium_crypto_kx_publickey($localKeypair);

    $initiator = NoiseHandshakeState::initIkInitiator($strangerSecret, $strangerPublic, $localPublic);
    $responder = NoiseHandshakeState::initIkResponder($localSecret, $localPublic);

    $responder->readMessage($initiator->writeMessage(''));
    $initiator->readMessage($responder->writeMessage(''));

    [$send, $recv, $peerStatic] = $responder->split();

    return [new NoiseSession($send, $recv, $peerStatic), sodium_bin2hex($peerStatic)];
}

function admissionSyncSession(): SyncSession
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: []),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
    );
}

function admissionConfirmPeer(User $user, string $x25519PublicKeyHex): string
{
    $deviceId = 'admission-peer-'.bin2hex(random_bytes(4));

    DB::table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $deviceId,
        'name' => 'Admission Peer',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => $x25519PublicKeyHex,
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    return $deviceId;
}

it('refuses a handshaken peer whose static key pairing never put in the registry', function (): void {
    $user = admissionUser();
    [$noiseSession] = admissionHandshakeAgainstAStranger();

    $syncSession = admissionSyncSession();
    $admitted = $syncSession->authenticate($noiseSession, (int) $user->id, 'admission-local-device');

    expect($admitted)->toBeFalse(
        'a key nobody paired with is a key nothing may admit: the handshake proves possession, and possession is not trust',
    );

    expect($syncSession->status())->toBe('failed');
    expect($syncSession->peerDeviceId())->toBeNull(
        'a refused session must name no peer, or the frames behind it would be attributed to one',
    );

    $row = DB::table('sync_sessions')->where('user_id', $user->id)->first();

    expect($row)->not->toBeNull('a refusal is recorded, so a device that keeps dialling is visible')
        ->and($row?->status)->toBe('failed')
        ->and((string) $row?->error_message)->toContain('device_registry');
});

it('admits the same handshake once pairing has confirmed that key, and no sooner', function (): void {
    $user = admissionUser();
    [$noiseSession, $peerKeyHex] = admissionHandshakeAgainstAStranger();

    // The control for the refusal above: the only thing that changes between
    // the two is the confirmed registry row, which is what makes the registry
    // the thing being tested rather than the handshake.
    $deviceId = admissionConfirmPeer($user, $peerKeyHex);

    $syncSession = admissionSyncSession();

    expect($syncSession->authenticate($noiseSession, (int) $user->id, 'admission-local-device'))->toBeTrue();
    expect($syncSession->status())->toBe('active');
    expect($syncSession->peerDeviceId())->toBe($deviceId);
});

it('refuses a peer another user confirmed, because the registry the session reads is scoped to its owner', function (): void {
    $owner = admissionUser();
    $stranger = admissionUser();
    [$noiseSession, $peerKeyHex] = admissionHandshakeAgainstAStranger();

    admissionConfirmPeer($stranger, $peerKeyHex);

    $syncSession = admissionSyncSession();

    expect($syncSession->authenticate($noiseSession, (int) $owner->id, 'admission-local-device'))->toBeFalse(
        'one household confirming a device says nothing about another household, so the lookup carries the owner',
    );
    expect($syncSession->status())->toBe('failed');
});

it('refuses a peer whose confirmation was withdrawn, on the next session it opens', function (): void {
    $user = admissionUser();
    [$noiseSession, $peerKeyHex] = admissionHandshakeAgainstAStranger();

    $deviceId = admissionConfirmPeer($user, $peerKeyHex);
    DB::table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $deviceId)
        ->update(['confirmed_at' => null]);

    $syncSession = admissionSyncSession();

    expect($syncSession->authenticate($noiseSession, (int) $user->id, 'admission-local-device'))->toBeFalse(
        'a row left in the registry with its confirmation cleared is a device that was removed, not one that is trusted',
    );
    expect($syncSession->status())->toBe('failed');
});
