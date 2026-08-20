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
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Transport\PairingOfferRequestHandler;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

use function Amp\ByteStream\buffer;

uses(RefreshDatabase::class);

/*
 * The pairing offer a device holding only a typed word-code needs.
 *
 * A QR carries the initiator's device id and both public keys; a word-code
 * carries the token and nothing else, so a fresh responder has no local row
 * to accept against. GET /pair/offer closes that gap over the LAN — and the
 * whole point of these tests is what it must NOT close: it hands out public
 * identity only, never the relay endpoint/token/pin the QR may carry,
 * because a camera is out of band and this endpoint is not.
 */

function pairingOfferUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('offer-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Issue a token as the desktop initiator would, returning the plaintext
 * token alongside the identity the offer is expected to echo back.
 *
 * @return array{token: string, deviceId: string, ed: string, kx: string}
 */
function pairingOfferIssue(User $user): array
{
    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    $deviceId = 'desktop-offer-initiator';
    $ed = bin2hex(random_bytes(32));
    $kx = bin2hex(random_bytes(32));

    return [
        'token' => $service->issue((int) $user->id, $deviceId, $ed, $kx),
        'deviceId' => $deviceId,
        'ed' => $ed,
        'kx' => $kx,
    ];
}

function pairingOfferHandler(int $userId): PairingOfferRequestHandler
{
    return new PairingOfferRequestHandler(
        // The websocket's stand-in: every request that is not the offer route
        // must arrive here untouched.
        new ClosureRequestHandler(static fn (): AmpResponse => new AmpResponse(101, [], 'delegated-to-websocket')),
        app(PairingOfferService::class),
        new PairingOfferRateLimiter(app(Clock::class)),
        $userId,
    );
}

function pairingOfferRequest(string $method, string $path, string $clientIp = '198.51.100.7'): AmpRequest
{
    $client = Mockery::mock(AmpClient::class);
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress($clientIp, 45123));

    return new AmpRequest($client, $method, HttpUri::new("http://192.0.2.10:51337{$path}"));
}

/**
 * @return array{status: int, raw: string, body: array<string, mixed>}
 */
function pairingOfferDispatch(PairingOfferRequestHandler $handler, AmpRequest $request): array
{
    $response = $handler->handleRequest($request);
    $raw = buffer($response->getBody());
    $decoded = json_decode($raw, true);

    return [
        'status' => $response->getStatus(),
        'raw' => $raw,
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    $secretsDir = UserDataPathService::secretsPath();

    foreach ([
        $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-token.json',
        UserDataPathService::appPath('sync/relay.json'),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('returns the initiator public identity for a live token', function (): void {
    $user = pairingOfferUser('offer-live');
    $issued = pairingOfferIssue($user);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(200);
    expect($result['body']['device_id'] ?? null)->toBe($issued['deviceId']);
    expect($result['body']['ed25519'] ?? null)->toBe($issued['ed']);
    expect($result['body']['x25519'] ?? null)->toBe($issued['kx']);
});

it('returns nothing beyond the four public identity fields', function (): void {
    $user = pairingOfferUser('offer-shape');
    $issued = pairingOfferIssue($user);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect(array_keys($result['body']))->toBe(['device_id', 'ed25519', 'x25519', 'name']);
});

it('never hands out the relay endpoint, token or pin even when one is configured', function (): void {
    $user = pairingOfferUser('offer-no-relay');
    $issued = pairingOfferIssue($user);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.invalid:51338');
    $relayConfig->setAuthToken('relay-bearer-secret');
    $relayConfig->setPin('relay-pin-material');

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(200);

    // The QR is an out-of-band channel a network attacker cannot touch and
    // may therefore bootstrap a relay. This answer travels the very network
    // that attacker is on, so none of it may appear in the body.
    expect($result['raw'])->not->toContain('relay.invalid')
        ->and($result['raw'])->not->toContain('relay-bearer-secret')
        ->and($result['raw'])->not->toContain('relay-pin-material');

    foreach (['relay', 'rtok', 'rpin', 'endpoint', 'pin'] as $forbidden) {
        expect($result['body'])->not->toHaveKey($forbidden);
    }
});

it('refuses an unknown token with a bare 404', function (): void {
    $user = pairingOfferUser('offer-unknown');
    pairingOfferIssue($user);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', bin2hex(random_bytes(16)))),
    );

    expect($result['status'])->toBe(404);
    expect($result['body'])->toBe(['error' => 'not_found']);
});

it('refuses a missing token with the same bare 404', function (): void {
    $user = pairingOfferUser('offer-no-token');

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer'),
    );

    expect($result['status'])->toBe(404);
    expect($result['body'])->toBe(['error' => 'not_found']);
});

it('refuses an expired token', function (): void {
    $user = pairingOfferUser('offer-expired');
    $issued = pairingOfferIssue($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('pairing_tokens')
        ->where('token_hash', hash('sha256', $issued['token']))
        ->update(['expires_at' => CarbonImmutable::now()->subMinute()->toIso8601String()]);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(404);
    expect($result['body'])->toBe(['error' => 'not_found']);
});

it('refuses a token whose ceremony already finished', function (): void {
    $user = pairingOfferUser('offer-finished');
    $issued = pairingOfferIssue($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('pairing_tokens')
        ->where('token_hash', hash('sha256', $issued['token']))
        ->update(['state' => 'confirmed']);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(404);
});

it('still answers while the row is awaiting confirmation', function (): void {
    $user = pairingOfferUser('offer-awaiting');
    $issued = pairingOfferIssue($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('pairing_tokens')
        ->where('token_hash', hash('sha256', $issued['token']))
        ->update(['state' => 'awaiting_confirm']);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(200);
    expect($result['body']['device_id'] ?? null)->toBe($issued['deviceId']);
});

it('refuses a token belonging to another user', function (): void {
    $owner = pairingOfferUser('offer-owner');
    $other = pairingOfferUser('offer-other');
    $issued = pairingOfferIssue($owner);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $other->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(404);
});

it('refuses every lookup when the daemon resolved no user', function (): void {
    $user = pairingOfferUser('offer-no-daemon-user');
    $issued = pairingOfferIssue($user);

    $result = pairingOfferDispatch(
        pairingOfferHandler(0),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['status'])->toBe(404);
});

it('throttles a source hammering the endpoint', function (): void {
    $user = pairingOfferUser('offer-throttle');
    $issued = pairingOfferIssue($user);

    $handler = pairingOfferHandler((int) $user->id);

    for ($attempt = 0; $attempt < PairingOfferRateLimiter::MAX_PER_WINDOW; $attempt++) {
        $allowed = pairingOfferDispatch($handler, pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])));
        expect($allowed['status'])->toBe(200);
    }

    $refused = pairingOfferDispatch($handler, pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])));

    expect($refused['status'])->toBe(429);
    expect($refused['body'])->toBe(['error' => 'rate_limited']);
});

it('buckets the throttle per source, not globally', function (): void {
    $user = pairingOfferUser('offer-throttle-bucket');
    $issued = pairingOfferIssue($user);

    $handler = pairingOfferHandler((int) $user->id);

    for ($attempt = 0; $attempt <= PairingOfferRateLimiter::MAX_PER_WINDOW; $attempt++) {
        pairingOfferDispatch($handler, pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token']), '198.51.100.7'));
    }

    $fromElsewhere = pairingOfferDispatch(
        $handler,
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token']), '198.51.100.8'),
    );

    expect($fromElsewhere['status'])->toBe(200);
});

it('hands every other path to the websocket untouched', function (): void {
    $user = pairingOfferUser('offer-delegates');

    $handler = pairingOfferHandler((int) $user->id);

    foreach ([['GET', '/sync'], ['GET', '/'], ['POST', '/pair/offer']] as [$method, $path]) {
        $result = pairingOfferDispatch($handler, pairingOfferRequest($method, $path));

        expect($result['status'])->toBe(101, "{$method} {$path} must reach the websocket");
        expect($result['raw'])->toBe('delegated-to-websocket');
    }
});

it('labels the offer with this device registry name', function (): void {
    $user = pairingOfferUser('offer-named');
    $issued = pairingOfferIssue($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toIso8601String();
    $db->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
        'device_id' => $issued['deviceId'],
        'name' => 'Studio Mac',
        'ed25519_public_key_hex' => $issued['ed'],
        'x25519_public_key_hex' => $issued['kx'],
        'safety_number_words' => 'one two three four five six',
        'is_self' => 1,
        'paired_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $result = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($result['body']['name'] ?? null)->toBe('Studio Mac');
});

it('never accepts the raw token, only its hash', function (): void {
    /*
     * The typed word-code is a bearer credential: whoever holds it can walk
     * the accept path. The phone asks every mDNS responder in turn whether it
     * holds the token, over plaintext HTTP, before it knows which one is the
     * real desktop — so sending the token itself hands it to whoever answers
     * a multicast question first.
     *
     * The row is stored under sha256(token), so the hash is all a lookup ever
     * needed. What can leak now buys public keys and nothing else.
     */
    $user = pairingOfferUser('offer-hash-only');
    $issued = pairingOfferIssue($user);

    $raw = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.$issued['token']),
    );

    expect($raw['status'])->toBe(404, 'the endpoint accepted a raw token');

    $hashed = pairingOfferDispatch(
        pairingOfferHandler((int) $user->id),
        pairingOfferRequest('GET', '/pair/offer?token='.hash('sha256', $issued['token'])),
    );

    expect($hashed['status'])->toBe(200);
});
