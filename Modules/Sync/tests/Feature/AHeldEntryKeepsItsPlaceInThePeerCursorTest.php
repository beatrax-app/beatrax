<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Pairing\DeviceIntroductionService;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\PeerCatchUpWatermarks;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// A per-peer cursor is a claim to have consumed history. Spent on an entry this
// device refused, the refusal becomes permanent: nothing re-offers it, the
// quarantine reason is not recoverable, and HistoryReprojector replays out of
// op_log_entries, which a refused entry never reached.

/**
 * @return array{0: int, 1: string, 2: string, 3: string} [userId, macEdSecretHex, oldPhoneEdSecretHex, macX25519SecretHex]
 */
function cursorHouseholdOf(DatabaseManager $db, string $suffix, string $localPublicX25519): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'held-cursor-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $macKx = sodium_crypto_kx_keypair();
    $macSig = sodium_crypto_sign_keypair();
    $oldPhoneSig = sodium_crypto_sign_keypair();

    $db->connection()->table('device_registry')->insert([
        [
            'user_id' => $userId, 'device_id' => 'new-phone', 'name' => 'New phone',
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
            'x25519_public_key_hex' => sodium_bin2hex($localPublicX25519),
            'safety_number_words' => 'abandon ability able about above absent', 'is_self' => 1,
            'paired_at' => '2026-06-01 00:00:00', 'confirmed_at' => '2026-06-01 00:00:00',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ],
        [
            'user_id' => $userId, 'device_id' => 'the-mac', 'name' => 'The Mac',
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($macSig)),
            'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_kx_publickey($macKx)),
            'safety_number_words' => 'absorb abstract absurd abuse access accident', 'is_self' => 0,
            'paired_at' => '2026-06-01 00:00:00', 'confirmed_at' => '2026-06-01 00:00:00',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ],
        // Retained, not confirmed: this install removed it. The Mac still
        // confirms it and goes on forwarding what it signed.
        [
            'user_id' => $userId, 'device_id' => 'old-phone', 'name' => 'Old phone',
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($oldPhoneSig)),
            'x25519_public_key_hex' => sodium_bin2hex(random_bytes(32)),
            'safety_number_words' => 'account accuse achieve acid acoustic acquire', 'is_self' => 0,
            'paired_at' => '2026-06-01 00:00:00', 'confirmed_at' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ],
    ]);

    return [
        $userId,
        sodium_bin2hex(sodium_crypto_sign_secretkey($macSig)),
        sodium_bin2hex(sodium_crypto_sign_secretkey($oldPhoneSig)),
        sodium_bin2hex(sodium_crypto_kx_secretkey($macKx)),
    ];
}

function cursorSignedOp(DeviceKeySigner $signer, string $secretKeyHex, string $author, int $userId, int $hlcL): OpLogEntry
{
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'merchants',
        pk: 501,
        field: 'name',
        value: json_encode('Bakery', JSON_THROW_ON_ERROR),
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $author,
        opType: OpType::Set,
        signature: $signature,
        userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($secretKeyHex)));
}

// A whole create group, so the entry rescued by a confirmation is one that
// PROJECTS a row rather than one that merely persists. A Set naming a pk no row
// holds updates nothing and refuses nothing, which would leave the last leg of
// the sequence below proving only that the op log grew.
/**
 * @return list<OpLogEntry>
 */
function cursorCreateOps(DeviceKeySigner $signer, string $secretKeyHex, string $author, int $userId, int $hlcL): array
{
    $entries = [];

    foreach (['name' => 'Bakery', 'normalized_name' => 'bakery'] as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'merchants',
            pk: 777,
            field: $field,
            value: json_encode($value, JSON_THROW_ON_ERROR),
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: $author,
            opType: OpType::CreateRow,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($secretKeyHex)));
        $hlcL++;
    }

    return $entries;
}

// The household first, the session second, because the trust map is a
// CONNECT-TIME snapshot: both transports read it once and hand the same array to
// the replayer and to receiveOps. A confirmation made mid-session is picked up
// on the next connect, and a test that seeded one afterwards would be asking a
// question no device asks.
/**
 * @return array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}
 */
function cursorHousehold(DatabaseManager $db, string $suffix): array
{
    $localKx = sodium_crypto_kx_keypair();
    $localPublic = sodium_crypto_kx_publickey($localKx);

    [$userId, $macEdSecret, $oldPhoneEdSecret, $macKxSecret] = cursorHouseholdOf($db, $suffix, $localPublic);

    return [
        $userId,
        $macEdSecret,
        $oldPhoneEdSecret,
        $macKxSecret,
        sodium_bin2hex(sodium_crypto_kx_secretkey($localKx)),
        sodium_bin2hex($localPublic),
    ];
}

/**
 * @return array{0: SyncSession, 1: NoiseSession}
 */
function cursorSession(DatabaseManager $db, int $userId, string $macKxSecret, string $localKxSecret, string $localPublicHex): array
{
    $localPublic = sodium_hex2bin($localPublicHex);

    $macPublic = sodium_hex2bin((string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', 'the-mac')->value('x25519_public_key_hex'));

    $initHs = NoiseHandshakeState::initIkInitiator(sodium_hex2bin($macKxSecret), $macPublic, $localPublic);
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

    expect($session->authenticate(new NoiseSession($respSend, $respRecv, $peerStaticToResp), $userId, 'new-phone'))
        ->toBeTrue();

    return [$session, new NoiseSession($initSend, $initRecv, $peerStaticToInit)];
}

it('never advances a peer cursor over an entry whose author it cannot verify', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, , $oldPhoneEdSecret, $macKxSecret, $localKxSecret, $localPublicHex] = cursorHousehold($db, 'held');
    [$session, $macNoise] = cursorSession($db, $userId, $macKxSecret, $localKxSecret, $localPublicHex);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    $entry = cursorSignedOp(app(DeviceKeySigner::class), $oldPhoneEdSecret, 'old-phone', $userId, 1_800_000_000_000);
    $frame = new TransportFramer()->encode([$entry]);

    $session->receiveOps($macNoise->encrypt($frame), $userId, $registry->signatureVerificationKeys($userId));

    $cursors = new PeerCatchUpWatermarks($db)->for($userId, 'the-mac');

    expect($cursors->for('old-phone'))->toBe([0, 0])
        ->and($db->connection()->table('sync_peer_catch_up_state')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(0)
        // Held, not quarantined: an audit row per arrival would grow without
        // bound for an entry the peer re-offers on every reconnect.
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('merchants')->where('user_id', $userId)->count())->toBe(0);
});

it('advances the cursor over an entry it did admit', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $macEdSecret, , $macKxSecret, $localKxSecret, $localPublicHex] = cursorHousehold($db, 'admitted');
    [$session, $macNoise] = cursorSession($db, $userId, $macKxSecret, $localKxSecret, $localPublicHex);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    $entry = cursorSignedOp(app(DeviceKeySigner::class), $macEdSecret, 'the-mac', $userId, 1_800_000_000_001);
    $frame = new TransportFramer()->encode([$entry]);

    $session->receiveOps($macNoise->encrypt($frame), $userId, $registry->signatureVerificationKeys($userId));

    $cursors = new PeerCatchUpWatermarks($db)->for($userId, 'the-mac');

    expect($cursors->for('the-mac'))->toBe([1_800_000_000_001, 0])
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(1);
});

it('delivers the held entry once the reader confirms an introduction for its author', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, , $oldPhoneEdSecret, $macKxSecret, $localKxSecret, $localPublicHex] = cursorHousehold($db, 'rescued');

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    // The registry row this install removed is dropped first: an introduction
    // may not shadow a pairing decision, and this scenario is the OTHER one —
    // a device this install never had, whose key only a peer can vouch for.
    $oldPhoneKey = (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', 'old-phone')->value('ed25519_public_key_hex');

    $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', 'old-phone')->delete();

    $db->connection()->table('device_introductions')->insert([
        'user_id' => $userId,
        'device_id' => 'old-phone',
        'name' => 'Old phone',
        'ed25519_public_key_hex' => $oldPhoneKey,
        'safety_number_words' => 'account accuse achieve acid acoustic acquire',
        'introduced_by_device_id' => 'the-mac',
        'introduced_at' => '2026-09-05T10:00:00Z',
        'verification_confirmed_at' => '2026-09-05T10:05:00Z',
        'created_at' => '2026-09-05T10:00:00Z',
        'updated_at' => '2026-09-05T10:00:00Z',
    ]);

    // Connected AFTER the confirmation, which is the only order a device sees:
    // the map is read once per connect and handed to the replayer and to
    // receiveOps together, so a session that predates the reader's decision
    // carries a snapshot that predates it too.
    [$session, $macNoise] = cursorSession($db, $userId, $macKxSecret, $localKxSecret, $localPublicHex);

    $entry = cursorSignedOp(app(DeviceKeySigner::class), $oldPhoneEdSecret, 'old-phone', $userId, 1_800_000_000_002);
    $frame = new TransportFramer()->encode([$entry]);

    $session->receiveOps($macNoise->encrypt($frame), $userId, $registry->signatureVerificationKeys($userId));

    expect(new PeerCatchUpWatermarks($db)->for($userId, 'the-mac')->for('old-phone'))
        ->toBe([1_800_000_000_002, 0])
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(1);
});

// The whole of the requirement in one run, because the property is a sequence
// and not three states: an entry arrives that nothing here can verify, the
// reader confirms the introduction that names its author, and the SAME entry
// then lands. Split across separate cases each half passes over a cursor the
// other half never moved.
it('holds an entry, and delivers that same entry once the reader confirms its author', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $macEdSecret, $oldPhoneEdSecret, $macKxSecret, $localKxSecret, $localPublicHex]
        = cursorHousehold($db, 'withheld-then-confirmed');

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    // A device this install never paired with, so the only key that could ever
    // verify it is one the Mac vouches for. Its seeded registry row goes first:
    // an introduction may not shadow a pairing decision, in either direction.
    $oldPhoneKeyHex = (string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', 'old-phone')->value('ed25519_public_key_hex');

    $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', 'old-phone')->delete();

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);

    $offered = [['device_id' => 'old-phone', 'name' => 'Old phone', 'ed25519_public_key_hex' => $oldPhoneKeyHex]];

    expect($introductions->record($userId, 'the-mac', $offered))->toBe(1)
        ->and($registry->signatureVerificationKeys($userId))->not->toHaveKey('old-phone');

    $signer = app(DeviceKeySigner::class);

    // One frame, two authors — the shape a catch-up actually delivers. A hold
    // that merely stopped an all-held frame short would let the verifiable half
    // of this one carry the other half's cursor past it.
    $held = cursorCreateOps($signer, $oldPhoneEdSecret, 'old-phone', $userId, 1_800_000_000_004);
    $frame = new TransportFramer()->encode([
        cursorSignedOp($signer, $macEdSecret, 'the-mac', $userId, 1_800_000_000_003),
        ...$held,
    ]);

    [$session, $macNoise] = cursorSession($db, $userId, $macKxSecret, $localKxSecret, $localPublicHex);
    $session->receiveOps($macNoise->encrypt($frame), $userId, $registry->signatureVerificationKeys($userId));

    $afterHold = new PeerCatchUpWatermarks($db)->for($userId, 'the-mac');

    expect($afterHold->for('old-phone'))->toBe([0, 0])
        ->and($afterHold->for('the-mac'))->toBe([1_800_000_000_003, 0])
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(1)
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('merchants')->where('user_id', $userId)->count())->toBe(0);

    $row = $introductions->forUser($userId)[0];

    expect($row->introduced_by_device_id)->toBe('the-mac')
        ->and($row->verification_confirmed_at)->toBeNull()
        ->and($introductions->confirm($userId, (int) $row->id))->toBeTrue();

    // What makes the peer send it again: the author was absent from this list
    // while it was being withheld, and naming it is the whole of the ask.
    $request = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger)
        ->buildRequest($userId, 'new-phone', 'the-mac');

    // Named as verifiable, and named by NO cursor: the peer is being asked for
    // that author's whole history, which is what the hold above preserved.
    expect($request['verifiable'])->toContain('old-phone')
        ->and(array_column($request['cursors'], 'device_id'))->toBe(['the-mac']);

    // A new connect, because the trust map is a connect-time snapshot.
    [$next, $macNoiseAgain] = cursorSession($db, $userId, $macKxSecret, $localKxSecret, $localPublicHex);
    $next->receiveOps(
        $macNoiseAgain->encrypt(new TransportFramer()->encode($held)),
        $userId,
        $registry->signatureVerificationKeys($userId),
    );

    // The row itself, not just the log: what a reader confirmed an introduction
    // to get back is the history, and history that never projects is not it.
    expect($db->connection()->table('merchants')->where('user_id', $userId)->pluck('name')->all())->toBe(['Bakery'])
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(3)
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0)
        ->and(new PeerCatchUpWatermarks($db)->for($userId, 'the-mac')->for('old-phone'))
        ->toBe([1_800_000_000_005, 0]);
});
