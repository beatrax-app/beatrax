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

// The desktop runs the app server and sync:serve against one SQLite file, and
// both write hlc_clock_state. The writer read that row when it was built and
// wrote its stamp back when the entry landed, so anything the daemon absorbed
// in between was overwritten by a stamp derived from the older value.

const CLOBBER_PEER_DEVICE_ID = 'peer-that-runs-fast';

/**
 * @return array{0: int, 1: string, 2: string, 3: string}
 */
function clobberHousehold(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'clock-clobber-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $selfKeypair = sodium_crypto_sign_keypair();
    $peerKeypair = sodium_crypto_sign_keypair();
    $selfDeviceId = 'this-desktop';

    foreach ([
        [$selfDeviceId, sodium_crypto_sign_publickey($selfKeypair), 1],
        [CLOBBER_PEER_DEVICE_ID, sodium_crypto_sign_publickey($peerKeypair), 0],
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

function clobberPeerOp(DeviceKeySigner $signer, string $peerSecretHex, int $userId, int $hlcL): OpLogEntry
{
    $secretKey = sodium_hex2bin($peerSecretHex);

    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'merchants',
        pk: 77,
        field: 'name',
        value: json_encode('Named on the fast peer', JSON_THROW_ON_ERROR),
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: CLOBBER_PEER_DEVICE_ID,
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

it('stamps a local edit above a peer clock the daemon absorbed after the writer was built', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $selfDeviceId, $selfSecretHex, $peerSecretHex] = clobberHousehold($db);

    // The app server resolves a writer while serving a request. Nothing is
    // written yet; this is only the moment the clock used to be read.
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $selfDeviceId,
        'userId' => $userId,
        'secretKey' => sodium_hex2bin($selfSecretHex),
        'publicKey' => sodium_hex2bin((string) $db->connection()->table('device_registry')
            ->where('user_id', $userId)->where('device_id', $selfDeviceId)->value('ed25519_public_key_hex')),
    ]);

    // sync:serve, a separate process, applies a frame from a peer an hour ahead
    // and absorbs its clock into the same row.
    $peerHlcL = (int) (microtime(true) * 1000) + 3_600_000;
    $peerKeys = [CLOBBER_PEER_DEVICE_ID => (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', CLOBBER_PEER_DEVICE_ID)->value('ed25519_public_key_hex')];

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    new OpLogReplayer(db: $db, deviceKeys: $peerKeys)
        ->replay([clobberPeerOp($signer, $peerSecretHex, $userId, $peerHlcL)], $userId);

    $writer->writeSet('merchants', 77, 'name', 'Renamed here afterwards');

    $entry = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('device_id', $selfDeviceId)
        ->first();

    // Stamped from the absorbed value, not from the one read at build time.
    expect($entry)->not->toBeNull()
        ->and((int) $entry->hlc_l)->toBe($peerHlcL)
        ->and((int) $entry->hlc_c)->toBeGreaterThan(0);

    // And the row the daemon wrote is carried forward rather than rewound: the
    // next local write starts above the peer too.
    $state = $db->connection()->table('hlc_clock_state')
        ->where('user_id', $userId)->where('device_id', $selfDeviceId)->first();

    expect((int) $state->last_l)->toBe($peerHlcL);
});

it('keeps the later local edit as the merged value even though the peer was heard from first', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$userId, $selfDeviceId, $selfSecretHex, $peerSecretHex] = clobberHousehold($db);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $selfDeviceId,
        'userId' => $userId,
        'secretKey' => sodium_hex2bin($selfSecretHex),
        'publicKey' => sodium_hex2bin((string) $db->connection()->table('device_registry')
            ->where('user_id', $userId)->where('device_id', $selfDeviceId)->value('ed25519_public_key_hex')),
    ]);

    $peerHlcL = (int) (microtime(true) * 1000) + 3_600_000;
    $selfPublicHex = (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', $selfDeviceId)->value('ed25519_public_key_hex');
    $peerKeys = [CLOBBER_PEER_DEVICE_ID => (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', CLOBBER_PEER_DEVICE_ID)->value('ed25519_public_key_hex')];

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $peerOp = clobberPeerOp($signer, $peerSecretHex, $userId, $peerHlcL);

    new OpLogReplayer(db: $db, deviceKeys: $peerKeys)->replay([$peerOp], $userId);

    $writer->writeSet('merchants', 77, 'name', 'Renamed here afterwards');

    $ownEntry = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('device_id', $selfDeviceId)
        ->first();

    $db->connection()->table('merchants')->insert([
        'id' => 77,
        'user_id' => $userId,
        'name' => 'placeholder',
        'normalized_name' => 'placeholder',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    new OpLogReplayer(db: $db, deviceKeys: [...$peerKeys, $selfDeviceId => $selfPublicHex])->replay([
        $peerOp,
        new OpLogEntry(
            table: 'merchants',
            pk: 77,
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

    expect($db->connection()->table('merchants')->where('id', 77)->value('name'))
        ->toBe('Renamed here afterwards');
});
