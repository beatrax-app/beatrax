<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\PeerCatchUpWatermarks;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// The catch-up exchange is symmetric and a sender answers with EVERY registered
// author's ops, which is how a third device's history reaches a phone that has
// never met it. On a first sync the desktop holds no cursor for the new phone,
// asks for everything, and the phone hands back the history it was just given —
// our own ops among it. A daemon holds no app-lock key, so the desktop
// quarantined 121 of its own entries under its own device id.

const OWN_HISTORY_LOCAL = 'this-mac';

const OWN_HISTORY_PEER = 'the-phone';

const OWN_HISTORY_EPOCH = 1450380582652434;

/**
 * @return array{0: int, 1: string, 2: string, 3: string, 4: string}
 */
function ownHistoryHousehold(DatabaseManager $db, string $suffix): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'own-history-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $localKx = sodium_crypto_kx_keypair();
    $localSig = sodium_crypto_sign_keypair();
    $peerKx = sodium_crypto_kx_keypair();

    $db->connection()->table('device_registry')->insert([
        [
            'user_id' => $userId, 'device_id' => OWN_HISTORY_LOCAL, 'name' => 'This Mac',
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($localSig)),
            'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_kx_publickey($localKx)),
            'safety_number_words' => 'abandon ability able about above absent', 'is_self' => 1,
            'paired_at' => '2026-09-01 00:00:00', 'confirmed_at' => '2026-09-01 00:00:00',
            'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
        ],
        [
            'user_id' => $userId, 'device_id' => OWN_HISTORY_PEER, 'name' => 'The phone',
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
            'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_kx_publickey($peerKx)),
            'safety_number_words' => 'absorb abstract absurd abuse access accident', 'is_self' => 0,
            'paired_at' => '2026-09-02 00:00:00', 'confirmed_at' => '2026-09-02 00:00:00',
            'created_at' => '2026-09-02 00:00:00', 'updated_at' => '2026-09-02 00:00:00',
        ],
    ]);

    return [
        $userId,
        sodium_bin2hex(sodium_crypto_sign_secretkey($localSig)),
        sodium_bin2hex(sodium_crypto_kx_secretkey($localKx)),
        sodium_bin2hex(sodium_crypto_kx_publickey($localKx)),
        sodium_bin2hex(sodium_crypto_kx_secretkey($peerKx)),
    ];
}

/**
 * @return array{0: SyncSession, 1: NoiseSession}
 */
function ownHistorySession(DatabaseManager $db, int $userId, string $localKxSecret, string $localPublicHex, string $peerKxSecret): array
{
    $localPublic = sodium_hex2bin($localPublicHex);
    $peerPublic = sodium_hex2bin((string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', OWN_HISTORY_PEER)->value('x25519_public_key_hex'));

    $initHs = NoiseHandshakeState::initIkInitiator(sodium_hex2bin($peerKxSecret), $peerPublic, $localPublic);
    $respHs = NoiseHandshakeState::initIkResponder(sodium_hex2bin($localKxSecret), $localPublic);

    $respHs->readMessage($initHs->writeMessage(''));
    $initHs->readMessage($respHs->writeMessage(''));

    [$initSend, $initRecv, $peerStaticToInit] = $initHs->split();
    [$respSend, $respRecv, $peerStaticToResp] = $respHs->split();

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    $session = new SyncSession(
        registryService: $registry,
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(
            db: $db,
            deviceKeys: $registry->signatureVerificationKeys($userId),
            rules: new MergeRulesRegistry,
        ),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
    );

    expect($session->authenticate(new NoiseSession($respSend, $respRecv, $peerStaticToResp), $userId, OWN_HISTORY_LOCAL))
        ->toBeTrue();

    return [$session, new NoiseSession($initSend, $initRecv, $peerStaticToInit)];
}

// A sealed field this device sealed itself, tagged with the epoch it was sealed
// under. The value never opens in the test process — which is the point: that
// is the state a keyless daemon is permanently in.
function ownHistoryEntry(string $localSigSecretHex, int $userId, int $hlcL): OpLogEntry
{
    $signer = new DeviceKeySigner;

    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'tax_transaction_tags',
        pk: 77,
        field: 'note',
        value: base64_encode(str_repeat("\x11", 64)),
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: OWN_HISTORY_LOCAL,
        opType: OpType::Set,
        signature: $signature,
        userId: $userId,
        gdkEpoch: OWN_HISTORY_EPOCH,
    );

    return $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($localSigSecretHex)));
}

function ownHistoryPersist(DatabaseManager $db, OpLogEntry $entry, int $userId): void
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => $entry->deviceId,
        'table_name' => $entry->table,
        'pk' => (string) $entry->pk,
        'field' => $entry->field,
        'op_type' => $entry->opType->value,
        'value' => $entry->value,
        'hlc_l' => $entry->hlcL,
        'hlc_c' => $entry->hlcC,
        'signature' => $entry->signature,
        'gdk_epoch' => $entry->gdkEpoch,
        'recorded_at' => '2026-09-02 21:00:00',
    ]);
}

it('does not quarantine its own sealed entries when a peer offers them back', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $localSigSecret, $localKxSecret, $localPublicHex, $peerKxSecret] = ownHistoryHousehold($db, 'echo');
    [$session, $peerNoise] = ownHistorySession($db, $userId, $localKxSecret, $localPublicHex, $peerKxSecret);

    $entry = ownHistoryEntry($localSigSecret, $userId, 1_800_000_000_000);
    ownHistoryPersist($db, $entry, $userId);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $session->receiveOps(
        $peerNoise->encrypt(new TransportFramer()->encode([$entry])),
        $userId,
        $registry->signatureVerificationKeys($userId),
    );

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())
        ->toBe(0)
        // Accounted for, not held: without the advance the peer offers the same
        // echo on every reconnect and the burst repeats for good.
        ->and(new PeerCatchUpWatermarks($db)->for($userId, OWN_HISTORY_PEER)->for(OWN_HISTORY_LOCAL))
        ->toBe([1_800_000_000_000, 0])
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())
        ->toBe(1);
});

it('still takes an entry it signed that its own log does not hold', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $localSigSecret, $localKxSecret, $localPublicHex, $peerKxSecret] = ownHistoryHousehold($db, 'restored');
    [$session, $peerNoise] = ownHistorySession($db, $userId, $localKxSecret, $localPublicHex, $peerKxSecret);

    $entry = ownHistoryEntry($localSigSecret, $userId, 1_800_000_000_001);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $session->receiveOps(
        $peerNoise->encrypt(new TransportFramer()->encode([$entry])),
        $userId,
        $registry->signatureVerificationKeys($userId),
    );

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(1);
});

it('retires a hold naming itself as the author, however far the watermark has moved past it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId] = ownHistoryHousehold($db, 'stranded');

    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'table_name' => 'tax_transaction_tags',
        'pk' => '77',
        'device_id' => OWN_HISTORY_LOCAL,
        'reason' => QuarantineReason::GdkDecryptFailed->value,
        'hlc_l' => 1_800_000_000_000,
        'hlc_c' => 0,
        'gdk_epoch' => OWN_HISTORY_EPOCH,
        'created_at' => '2026-09-02 21:06:28',
    ]);

    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);

    // The live shape: the pass stamped its watermark an hour and forty-eight
    // minutes AFTER the burst, so every later window filtered these out and
    // the rows were still there three days on.
    $watermark = '2026-09-02 22:54:33';

    expect($reprojector->hasUnexaminedQuarantine($userId, $watermark))->toBeTrue();

    /** @var Session $session */
    $session = app(Session::class);
    $reprojector->replayQuarantined($userId, $session, $watermark, null);

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0)
        ->and($reprojector->hasUnexaminedQuarantine($userId, $watermark))->toBeFalse();
});
