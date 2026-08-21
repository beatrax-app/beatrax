<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Uri\Http as HttpUri;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\PairingFramePullHandler;

use function Amp\ByteStream\buffer;

uses(RefreshDatabase::class);

// The return leg is served on 0.0.0.0, over the same relay_mailbox rows that
// GET /relay/drain protects with a per-device bearer token. Being on the wifi
// must therefore not be the qualification: what the collecting device can prove
// and a stranger cannot is possession of the secret half of the key the pairing
// row already bound for it.

const PULL_PHONE_DID = '11111111-2222-4333-8444-555555555555';

const PULL_DESKTOP_DID = '77777777-6666-4555-8444-333333333333';

function pullUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('pull-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{secretHex: string, publicHex: string}
 */
function pullKeypair(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [
        'secretHex' => sodium_bin2hex(sodium_crypto_sign_secretkey($keypair)),
        'publicHex' => sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
    ];
}

function pullProof(string $deviceId, string $secretHex): string
{
    return app(DeviceKeySigner::class)->sign(
        PairingFrame::pullProofMessage($deviceId),
        sodium_hex2bin($secretHex),
    );
}

// The desktop's row as it stands once the phone's accept has bound it: the
// responder columns hold the phone's id and public key, which is exactly what
// the proof below has to be checked against.
function pullBoundRow(User $user, string $responderEdHex): void
{
    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    $token = $service->issue((int) $user->id, PULL_DESKTOP_DID, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)));

    $service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        PULL_PHONE_DID,
        $responderEdHex,
        bin2hex(random_bytes(32)),
    );
}

function pullQueuedConfirm(string $recipientDid): void
{
    app(PairingPeerOutbox::class)->queueFor(PULL_DESKTOP_DID, $recipientDid, [
        'type' => 'PAIR_CONFIRM',
        'token_hash' => str_repeat('d', 64),
        'confirming_device_id' => PULL_DESKTOP_DID,
        'peer_device_id' => $recipientDid,
        'sig_hex' => str_repeat('f', 128),
    ]);
}

function pullHandler(int $userId): PairingFramePullHandler
{
    return new PairingFramePullHandler(
        new ClosureRequestHandler(static fn (): AmpResponse => new AmpResponse(101, [], 'delegated-to-websocket')),
        app(PairingPeerOutbox::class),
        new PairingOfferRateLimiter(app(Clock::class)),
        app(PairingPullAuthorizer::class),
        $userId,
    );
}

/**
 * @return array{status: int, frames: array<int, mixed>}
 */
function pullDispatch(PairingFramePullHandler $handler, string $query): array
{
    $client = Mockery::mock(AmpClient::class);
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('198.51.100.7', 45123));

    $response = $handler->handleRequest(
        new AmpRequest($client, 'GET', HttpUri::new("http://192.0.2.10:51337/pair/frames{$query}")),
    );

    $decoded = json_decode(buffer($response->getBody()), true);

    return [
        'status' => $response->getStatus(),
        'frames' => is_array($decoded) && is_array($decoded['frames'] ?? null) ? $decoded['frames'] : [],
    ];
}

function pullPending(string $recipientDid): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->whereNull('delivered_at')
        ->count();
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('hands the waiting frames to a device that proves it owns the bound key', function (): void {
    $user = pullUser('pull-authorised');
    $phone = pullKeypair();

    pullBoundRow($user, $phone['publicHex']);
    pullQueuedConfirm(PULL_PHONE_DID);

    $result = pullDispatch(
        pullHandler((int) $user->id),
        '?device='.PULL_PHONE_DID.'&proof='.pullProof(PULL_PHONE_DID, $phone['secretHex']),
    );

    expect($result['status'])->toBe(200);
    expect($result['frames'])->toHaveCount(1);
    expect($result['frames'][0]['type'])->toBe('PAIR_CONFIRM');
});

// The finding itself: naming a device id was the whole qualification, and the
// blobs handed back carry the token_hash an attacker needs to rebind the row.
it('hands nothing to a caller that names the device but proves nothing', function (): void {
    $user = pullUser('pull-unproven');
    $phone = pullKeypair();

    pullBoundRow($user, $phone['publicHex']);
    pullQueuedConfirm(PULL_PHONE_DID);

    $result = pullDispatch(pullHandler((int) $user->id), '?device='.PULL_PHONE_DID);

    expect($result['status'])->toBe(200);
    expect($result['frames'])->toBe([]);
    expect(pullPending(PULL_PHONE_DID))->toBe(1);
});

it('hands nothing to a caller whose proof was signed by some other key', function (): void {
    $user = pullUser('pull-wrong-key');
    $phone = pullKeypair();
    $attacker = pullKeypair();

    pullBoundRow($user, $phone['publicHex']);
    pullQueuedConfirm(PULL_PHONE_DID);

    $result = pullDispatch(
        pullHandler((int) $user->id),
        '?device='.PULL_PHONE_DID.'&proof='.pullProof(PULL_PHONE_DID, $attacker['secretHex']),
    );

    expect($result['frames'])->toBe([]);
    expect(pullPending(PULL_PHONE_DID))->toBe(1);
});

// A signature is only a qualification against a row THIS device is a party to.
// The listener serves one user, and another one's handshake is not its business.
it('hands nothing on a proof valid only for another account handshake', function (): void {
    $owner = pullUser('pull-owner');
    $stranger = pullUser('pull-stranger');
    $phone = pullKeypair();

    pullBoundRow($stranger, $phone['publicHex']);
    pullQueuedConfirm(PULL_PHONE_DID);

    $result = pullDispatch(
        pullHandler((int) $owner->id),
        '?device='.PULL_PHONE_DID.'&proof='.pullProof(PULL_PHONE_DID, $phone['secretHex']),
    );

    expect($result['frames'])->toBe([]);
});

it('stops qualifying a proof once the handshake it rests on has expired', function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');

    $user = pullUser('pull-expired');
    $phone = pullKeypair();

    pullBoundRow($user, $phone['publicHex']);
    pullQueuedConfirm(PULL_PHONE_DID);

    CarbonImmutable::setTestNow('2026-06-15 11:00:00');

    $result = pullDispatch(
        pullHandler((int) $user->id),
        '?device='.PULL_PHONE_DID.'&proof='.pullProof(PULL_PHONE_DID, $phone['secretHex']),
    );

    expect($result['frames'])->toBe([]);
});

// takeFor() used to confirm each row inside its own read loop, before the body
// existed. The row is marked delivered only once the answer has been built.
it('marks a frame delivered only after it is in the answer', function (): void {
    $user = pullUser('pull-delivery');
    $phone = pullKeypair();

    pullBoundRow($user, $phone['publicHex']);
    pullQueuedConfirm(PULL_PHONE_DID);

    expect(pullPending(PULL_PHONE_DID))->toBe(1);

    pullDispatch(
        pullHandler((int) $user->id),
        '?device='.PULL_PHONE_DID.'&proof='.pullProof(PULL_PHONE_DID, $phone['secretHex']),
    );

    expect(pullPending(PULL_PHONE_DID))->toBe(0);
});

// A device id array-ified into `device[]=x` degrades to '' rather than reaching
// the authorizer as something it has to reason about.
it('answers an empty list to a malformed device parameter', function (): void {
    $user = pullUser('pull-malformed');

    $result = pullDispatch(pullHandler((int) $user->id), '?device[]=x&proof[]=y');

    expect($result['status'])->toBe(200);
    expect($result['frames'])->toBe([]);
});
