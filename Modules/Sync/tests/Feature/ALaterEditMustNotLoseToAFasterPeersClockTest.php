<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// HybridLogicalClock::receive() existed and was only ever fed this device's own
// persisted state, so a remote entry never moved the local clock. A peer whose
// wall clock ran an hour fast therefore won every subsequent merge on this
// device: each later edit made here still carried a lower hlc_l and lost.

const FAST_PEER_DEVICE_ID = 'peer-one-hour-fast';

/**
 * @return array{0: int, 1: string, 2: string, 3: string}
 */
function seedSkewedPair(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'clock-skew-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $selfKeypair = sodium_crypto_sign_keypair();
    $peerKeypair = sodium_crypto_sign_keypair();
    $selfDeviceId = 'this-desktop';

    foreach ([
        [$selfDeviceId, sodium_crypto_sign_publickey($selfKeypair), 1],
        [FAST_PEER_DEVICE_ID, sodium_crypto_sign_publickey($peerKeypair), 0],
    ] as [$deviceId, $publicKey, $isSelf]) {
        $db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => bin2hex($publicKey),
            'x25519_public_key_hex' => bin2hex(random_bytes(32)),
            'safety_number_words' => 'one two three four five six',
            'is_self' => $isSelf,
            'paired_at' => '2026-06-01 00:00:00',
            'confirmed_at' => '2026-06-01 00:00:00',
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
    }

    return [
        $userId,
        $selfDeviceId,
        bin2hex(sodium_crypto_sign_secretkey($selfKeypair)),
        bin2hex(sodium_crypto_sign_secretkey($peerKeypair)),
    ];
}

function fastPeerSetOp(DeviceKeySigner $signer, string $peerSecretHex, int $userId, int $hlcL): OpLogEntry
{
    $secretKey = sodium_hex2bin($peerSecretHex);

    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'merchants',
        pk: 55,
        field: 'name',
        value: json_encode('Named by the fast peer', JSON_THROW_ON_ERROR),
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: FAST_PEER_DEVICE_ID,
        opType: OpType::Set,
        signature: $signature,
        userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), $secretKey));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('carries this device past a peer whose clock runs an hour fast, so its own later edit wins', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $selfDeviceId, $selfSecretHex, $peerSecretHex] = seedSkewedPair($db);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    $peerKeys = [FAST_PEER_DEVICE_ID => (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', FAST_PEER_DEVICE_ID)->value('ed25519_public_key_hex')];

    // An hour ahead of this device's wall clock, in HLC milliseconds.
    $peerHlcL = (int) (microtime(true) * 1000) + 3_600_000;

    $replayer = new OpLogReplayer(db: $db, deviceKeys: $peerKeys);
    $replayer->replay([fastPeerSetOp($signer, $peerSecretHex, $userId, $peerHlcL)], $userId);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $selfDeviceId,
        'userId' => $userId,
        'secretKey' => sodium_hex2bin($selfSecretHex),
        'publicKey' => sodium_hex2bin((string) $db->connection()->table('device_registry')
            ->where('user_id', $userId)->where('device_id', $selfDeviceId)->value('ed25519_public_key_hex')),
    ]);

    $writer->writeSet('merchants', 55, 'name', 'Renamed here afterwards');

    $ownEntry = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('device_id', $selfDeviceId)
        ->orderByDesc('hlc_l')
        ->orderByDesc('hlc_c')
        ->first();

    expect($ownEntry)->not->toBeNull()
        ->and((int) $ownEntry->hlc_l)->toBeGreaterThanOrEqual($peerHlcL);

    // The point of the higher HLC, spelled out: merge the two ops and the
    // later edit is the one the row keeps.
    $db->connection()->table('merchants')->insert([
        'id' => 55,
        'user_id' => $userId,
        'name' => 'placeholder',
        'normalized_name' => 'placeholder',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $merged = new OpLogReplayer(db: $db, deviceKeys: [
        ...$peerKeys,
        $selfDeviceId => (string) $db->connection()->table('device_registry')
            ->where('user_id', $userId)->where('device_id', $selfDeviceId)->value('ed25519_public_key_hex'),
    ]);

    $merged->replay([
        fastPeerSetOp($signer, $peerSecretHex, $userId, $peerHlcL),
        new OpLogEntry(
            table: 'merchants',
            pk: 55,
            field: 'name',
            value: is_string($ownEntry->value) ? $ownEntry->value : null,
            hlcL: (int) $ownEntry->hlc_l,
            hlcC: (int) $ownEntry->hlc_c,
            deviceId: $selfDeviceId,
            opType: OpType::Set,
            signature: is_string($ownEntry->signature) ? $ownEntry->signature : '',
            userId: $userId,
        ),
    ], $userId);

    expect($db->connection()->table('merchants')->where('id', 55)->value('name'))
        ->toBe('Renamed here afterwards');
});

it('leaves the local clock alone when the remote entry it just applied is older than what this device already wrote', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $selfDeviceId, , $peerSecretHex] = seedSkewedPair($db);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);

    $db->connection()->table('hlc_clock_state')->insert([
        'user_id' => $userId,
        'device_id' => $selfDeviceId,
        'last_l' => 9_000_000_000_000,
        'last_c' => 3,
        'updated_at' => '2026-06-14 10:00:00',
    ]);

    $peerKeys = [FAST_PEER_DEVICE_ID => (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', FAST_PEER_DEVICE_ID)->value('ed25519_public_key_hex')];

    $replayer = new OpLogReplayer(db: $db, deviceKeys: $peerKeys);
    $replayer->replay([fastPeerSetOp($signer, $peerSecretHex, $userId, 1_000)], $userId);

    $state = $db->connection()->table('hlc_clock_state')
        ->where('user_id', $userId)->where('device_id', $selfDeviceId)->first();

    expect((int) $state->last_l)->toBe(9_000_000_000_000)
        ->and((int) $state->last_c)->toBe(3);
});
