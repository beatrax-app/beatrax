<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\DialingSyncPeer;
use Modules\Sync\Tests\Support\RecordingLogger;

uses(RefreshDatabase::class);

// Two refusals live in one loop over a received frame, and both end the same
// way: the entry reaches no strategy and the cursor is never advanced over it,
// so the peer offers it again on every reconnect. One of them was taught to
// count per author and report once, precisely because a device signing with a
// retired identity wrote the same line six thousand times. Its sibling kept the
// per-entry line, and a re-keyed device fills the log through it instead.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
const REFUSED_FRAME_SIZE = 12;

/**
 * @return array{0: int, 1: SyncSession, 2: NoiseSession, 3: array<string, string>}
 */
function refusedFrameSession(RecordingLogger $logger): array
{
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'refused-frame-'.bin2hex(random_bytes(5)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;

    $deskKx = sodium_crypto_kx_keypair();
    $deskSecret = sodium_crypto_kx_secretkey($deskKx);
    $deskPublic = sodium_crypto_kx_publickey($deskKx);
    $peer = new DialingSyncPeer($deskPublic);

    foreach ([
        ['desktop-self', sodium_bin2hex($deskPublic), 1],
        ['phone-peer', $peer->publicKeyHex(), 0],
    ] as [$deviceId, $x25519Hex, $isSelf]) {
        $db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
            'x25519_public_key_hex' => $x25519Hex,
            'safety_number_words' => 'abandon ability able about above absent',
            'is_self' => $isSelf,
            'paired_at' => '2026-09-01T10:00:00Z',
            'confirmed_at' => '2026-09-01T10:05:00Z',
            'last_seen_at' => null,
            'created_at' => '2026-09-01T10:00:00Z',
            'updated_at' => '2026-09-01T10:00:00Z',
        ]);
    }

    $responder = NoiseHandshakeState::initIkResponder($deskSecret, $deskPublic);
    $responder->readMessage($peer->handshakeMessage());
    $peer->adopt($responder->writeMessage(''));
    [$send, $receive, $peerStatic] = $responder->split();

    $session = new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: []),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
        logger: $logger,
    );

    expect($session->authenticate(new NoiseSession($send, $receive, $peerStatic), $userId, 'desktop-self'))->toBeTrue();

    return [$userId, $session, $peer->session(), app(DeviceRegistryService::class)->signatureVerificationKeys($userId)];
}

// A whole history signed by an identity this household no longer holds the key
// for — what a device that re-keyed and kept its id offers on every connect.
/**
 * @return list<OpLogEntry>
 */
function refusedFrameEntries(int $userId, string $author, string $signingSecret): array
{
    $signer = new DeviceKeySigner;
    $entries = [];

    for ($i = 0; $i < REFUSED_FRAME_SIZE; $i++) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'merchants',
            pk: (string) (800 + $i),
            field: 'name',
            value: json_encode('Merchant '.$i, JSON_THROW_ON_ERROR),
            hlcL: 400 + $i,
            hlcC: 0,
            deviceId: $author,
            opType: OpType::Set,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), $signingSecret));
    }

    return $entries;
}

it('reports a frame of unverifiable signatures once, with what it refused and what arrived', function (): void {
    $logger = new RecordingLogger;
    [$userId, $session, $peerNoise, $deviceKeys] = refusedFrameSession($logger);

    $stranger = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $entries = refusedFrameEntries($userId, 'phone-peer', $stranger);

    $session->receiveOps($peerNoise->encrypt((new TransportFramer)->encode($entries)), $userId, $deviceKeys);

    $refusals = array_values(array_filter(
        $logger->lines,
        static fn (array $line): bool => ($line['context']['reason'] ?? null) === 'signature_invalid',
    ));

    expect($refusals)->toHaveCount(
        1,
        'a line per entry is what made the first six thousand of these unreadable',
    );

    expect($refusals[0]['context']['refused'] ?? null)->toBe(REFUSED_FRAME_SIZE)
        ->and($refusals[0]['context']['received'] ?? null)->toBe(REFUSED_FRAME_SIZE)
        ->and($refusals[0]['context']['device_ids'] ?? null)->toBe(['phone-peer']);
});

it('keeps a refused frame\'s place in the peer cursor, which is why it comes back', function (): void {
    $logger = new RecordingLogger;
    [$userId, $session, $peerNoise, $deviceKeys] = refusedFrameSession($logger);

    $stranger = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

    $session->receiveOps(
        $peerNoise->encrypt((new TransportFramer)->encode(refusedFrameEntries($userId, 'phone-peer', $stranger))),
        $userId,
        $deviceKeys,
    );

    // The word the report uses has to match what the cursor did: nothing here
    // was dropped, because a cursor advanced past a refusal asks the peer to
    // skip it forever.
    expect(app(DatabaseManager::class)->connection()->table('sync_peer_catch_up_state')->count())
        ->toBe(0, 'a refused entry must not spend the cursor that would have brought it back');
});
