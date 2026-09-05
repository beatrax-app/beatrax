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
use Modules\Sync\Internal\Transport\CatchUpDelta;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\PeerCatchUpWatermarks;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// The household's only voucher is not its only holder. The Mac paired with the
// retired phone and with both current ones; the two current phones paired with
// the Mac and never with each other. A phone that confirms the Mac's
// introduction can read the retired phone's work — and held every byte of it.

// This device is the new phone. Its world is itself, the Mac that vouches, and
// a sibling phone that asks it for catch-up. The retired phone has no row of any
// kind here: the only key that can ever name it is one the Mac relays.
/**
 * @return array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}
 */
function courierHousehold(DatabaseManager $db, string $suffix): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'courier-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $localKx = sodium_crypto_kx_keypair();
    $localPublic = sodium_crypto_kx_publickey($localKx);

    $macKx = sodium_crypto_kx_keypair();
    $macSig = sodium_crypto_sign_keypair();
    $retiredSig = sodium_crypto_sign_keypair();
    $strangerSig = sodium_crypto_sign_keypair();

    $row = static fn (string $deviceId, string $name, string $edHex, string $kxHex, int $isSelf): array => [
        'user_id' => $userId, 'device_id' => $deviceId, 'name' => $name,
        'ed25519_public_key_hex' => $edHex, 'x25519_public_key_hex' => $kxHex,
        'safety_number_words' => 'abandon ability able about above absent', 'is_self' => $isSelf,
        'paired_at' => '2026-06-01 00:00:00', 'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ];

    $db->connection()->table('device_registry')->insert([
        $row('new-phone', 'New phone', sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())), sodium_bin2hex($localPublic), 1),
        $row('the-mac', 'The Mac', sodium_bin2hex(sodium_crypto_sign_publickey($macSig)), sodium_bin2hex(sodium_crypto_kx_publickey($macKx)), 0),
        $row('sibling-phone', 'Sibling phone', sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())), sodium_bin2hex(random_bytes(32)), 0),
    ]);

    return [
        $userId,
        sodium_bin2hex(sodium_crypto_sign_secretkey($macSig)),
        sodium_bin2hex(sodium_crypto_sign_secretkey($retiredSig)),
        sodium_bin2hex(sodium_crypto_sign_secretkey($strangerSig)),
        sodium_bin2hex(sodium_crypto_kx_secretkey($macKx)),
        sodium_bin2hex(sodium_crypto_kx_secretkey($localKx)),
    ];
}

/**
 * @return array{0: SyncSession, 1: NoiseSession}
 */
function courierSession(DatabaseManager $db, int $userId, string $macKxSecret, string $localKxSecret): array
{
    $localPublic = sodium_hex2bin((string) $db->connection()->table('device_registry')
        ->where('user_id', $userId)->where('device_id', 'new-phone')->value('x25519_public_key_hex'));

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

function courierOp(DeviceKeySigner $signer, string $secretKeyHex, string $author, int $userId, int $hlcL): OpLogEntry
{
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'merchants', pk: 501, field: 'name', value: json_encode('Bakery', JSON_THROW_ON_ERROR),
        hlcL: $hlcL, hlcC: 0, deviceId: $author, opType: OpType::Set,
        signature: $signature, userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($secretKeyHex)));
}

// A whole create group, so what a confirmation rescues is history that PROJECTS
// a row rather than one that merely persists — and so the delta the sibling
// phone is later served has more than one entry to be counted wrong by.
/**
 * @return list<OpLogEntry>
 */
function courierCreateOps(DeviceKeySigner $signer, string $secretKeyHex, string $author, int $userId, int $hlcL): array
{
    $entries = [];

    foreach (['name' => 'Bakery', 'normalized_name' => 'bakery'] as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'merchants', pk: 777, field: $field, value: json_encode($value, JSON_THROW_ON_ERROR),
            hlcL: $hlcL, hlcC: 0, deviceId: $author, opType: OpType::CreateRow,
            signature: $signature, userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($secretKeyHex)));
        $hlcL++;
    }

    return $entries;
}

/**
 * @return list<string>
 */
function courierAuthorsIn(CatchUpDelta $delta): array
{
    $framer = new TransportFramer;
    $authors = [];

    foreach ($delta as $frame) {
        foreach ($framer->decode($frame) as $entry) {
            $authors[] = $entry->deviceId;
        }
    }

    sort($authors);

    return $authors;
}

/**
 * @param  list<string>  $verifiable
 * @return array{0: list<string>, 1: array<string, mixed>}
 */
function courierAnswerTo(DatabaseManager $db, int $userId, array $verifiable, string $peerDeviceId): array
{
    [$delta, $control] = new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger)
        ->answer($userId, ['cursors' => [], 'verifiable' => $verifiable], $peerDeviceId);

    return [courierAuthorsIn($delta), $control];
}

function courierIntroduceRetiredPhone(DatabaseManager $db, int $userId, string $retiredEdSecret): int
{
    return new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'retired-phone', 'count' => 2]],
        'introductions' => [[
            'device_id' => 'retired-phone',
            'name' => 'Retired phone',
            'ed25519_public_key_hex' => sodium_bin2hex(
                sodium_crypto_sign_publickey_from_secretkey(sodium_hex2bin($retiredEdSecret)),
            ),
        ]],
    ], 'the-mac');
}

// The whole ruling in one run, because it is a sequence and not a set of states:
// ops arrive that nothing here can verify and are held; the Mac's introduction
// for their author is confirmed; the same ops land; and a sibling phone that can
// verify that author is then served them BY THIS DEVICE rather than told nothing.
it('serves a third device history onward once it confirms the introduction that named its author', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $macEdSecret, $retiredEdSecret, $strangerEdSecret, $macKxSecret, $localKxSecret]
        = courierHousehold($db, 'onward');

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $signer = app(DeviceKeySigner::class);

    expect(courierIntroduceRetiredPhone($db, $userId, $retiredEdSecret))->toBe(1);

    // One frame, three authors: the Mac's own op, the retired phone's create
    // group, and an op signed by a device no door here names. That last one is
    // the control — it must be held both before and after the confirmation.
    $held = courierCreateOps($signer, $retiredEdSecret, 'retired-phone', $userId, 1_800_000_000_004);
    $stranger = courierOp($signer, $strangerEdSecret, 'a-device-nobody-vouched-for', $userId, 1_800_000_000_009);

    $frame = new TransportFramer()->encode([
        courierOp($signer, $macEdSecret, 'the-mac', $userId, 1_800_000_000_003),
        ...$held,
        $stranger,
    ]);

    [$session, $macNoise] = courierSession($db, $userId, $macKxSecret, $localKxSecret);
    $session->receiveOps($macNoise->encrypt($frame), $userId, $registry->signatureVerificationKeys($userId));

    $afterHold = new PeerCatchUpWatermarks($db)->for($userId, 'the-mac');

    expect($afterHold->for('retired-phone'))->toBe([0, 0])
        ->and($afterHold->for('a-device-nobody-vouched-for'))->toBe([0, 0])
        ->and($afterHold->for('the-mac'))->toBe([1_800_000_000_003, 0])
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(1)
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);
    $row = $introductions->forUser($userId)[0];

    expect($row->verification_confirmed_at)->toBeNull()
        ->and($introductions->confirm($userId, (int) $row->id))->toBeTrue();

    // A new connect, because the trust map is a connect-time snapshot.
    [$next, $macAgain] = courierSession($db, $userId, $macKxSecret, $localKxSecret);
    $next->receiveOps(
        $macAgain->encrypt(new TransportFramer()->encode([...$held, $stranger])),
        $userId,
        $registry->signatureVerificationKeys($userId),
    );

    expect($db->connection()->table('merchants')->where('user_id', $userId)->pluck('name')->all())->toBe(['Bakery'])
        ->and(new PeerCatchUpWatermarks($db)->for($userId, 'the-mac')->for('retired-phone'))
        ->toBe([1_800_000_000_005, 0])
        // Still held, and by the same gate: confirming one author widened what
        // this device can verify by exactly one author.
        ->and(new PeerCatchUpWatermarks($db)->for($userId, 'the-mac')->for('a-device-nobody-vouched-for'))
        ->toBe([0, 0]);

    // The leg that did not exist. The sibling phone paired with the Mac too and
    // confirmed the same introduction, so it can read what this device now holds.
    [$authors, $control] = courierAnswerTo(
        $db,
        $userId,
        ['sibling-phone', 'new-phone', 'the-mac', 'retired-phone'],
        'sibling-phone',
    );

    expect($authors)->toBe(['retired-phone', 'retired-phone', 'the-mac'])
        ->and($control['withheld'])->toBe([])
        ->and($control['introductions'])->toBe([]);
});

// The other half of the ruling, and the half that is a refusal: this device may
// carry what the retired phone signed, and may not say who the retired phone is.
it('reports what it holds for an author the asker cannot verify, and vouches for nobody', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, $macEdSecret, $retiredEdSecret, , $macKxSecret, $localKxSecret] = courierHousehold($db, 'reports');

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    $signer = app(DeviceKeySigner::class);

    courierIntroduceRetiredPhone($db, $userId, $retiredEdSecret);

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);
    $introductions->confirm($userId, (int) $introductions->forUser($userId)[0]->id);

    [$session, $macNoise] = courierSession($db, $userId, $macKxSecret, $localKxSecret);
    $session->receiveOps(
        $macNoise->encrypt(new TransportFramer()->encode([
            courierOp($signer, $macEdSecret, 'the-mac', $userId, 1_800_000_000_003),
            ...courierCreateOps($signer, $retiredEdSecret, 'retired-phone', $userId, 1_800_000_000_004),
        ])),
        $userId,
        $registry->signatureVerificationKeys($userId),
    );

    [$authors, $control] = courierAnswerTo($db, $userId, ['sibling-phone', 'new-phone', 'the-mac'], 'sibling-phone');

    expect($authors)->toBe(['the-mac'])
        // Counted, where before the widening it was neither sent nor mentioned:
        // the asker was told a clean sync and quietly ended up with less.
        ->and($control['withheld'])->toBe([['device_id' => 'retired-phone', 'count' => 2]])
        // And no identity beside it. A vouch on the strength of a vouch is the
        // one hop that launders trust, and it is not this device's to make.
        ->and($control['introductions'])->toBe([]);
});

it('carries nothing for an author no door here names, and nothing for one still unconfirmed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$userId, , $retiredEdSecret] = courierHousehold($db, 'bounded');

    $insert = static function (string $author, int $hlcL) use ($db, $userId): void {
        $db->connection()->table('op_log_entries')->insert([
            'user_id' => $userId, 'device_id' => $author, 'table_name' => 'merchants',
            'pk' => '9', 'field' => 'name', 'op_type' => OpType::Set->value,
            'value' => json_encode($author, JSON_THROW_ON_ERROR), 'hlc_l' => $hlcL, 'hlc_c' => 0,
            'signature' => str_repeat('a', 128), 'recorded_at' => '2026-06-14 10:00:00',
        ]);
    };

    $insert('the-mac', 1_000);
    $insert('retired-phone', 1_001);
    $insert('a-device-nobody-vouched-for', 1_002);

    $asks = ['sibling-phone', 'new-phone', 'the-mac', 'retired-phone', 'a-device-nobody-vouched-for'];

    courierIntroduceRetiredPhone($db, $userId, $retiredEdSecret);

    // An introduction the reader has not acted on verifies nothing, so it may
    // carry nothing: a key this device declined to accept is not one it may
    // relay signed work against, in either direction.
    [$unconfirmed, $beforeControl] = courierAnswerTo($db, $userId, $asks, 'sibling-phone');

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);
    $introductions->confirm($userId, (int) $introductions->forUser($userId)[0]->id);

    [$confirmed, $afterControl] = courierAnswerTo($db, $userId, $asks, 'sibling-phone');

    expect($unconfirmed)->toBe(['the-mac'])
        ->and($confirmed)->toBe(['retired-phone', 'the-mac'])
        ->and($beforeControl['withheld'])->toBe([])
        ->and($afterControl['withheld'])->toBe([]);
});
