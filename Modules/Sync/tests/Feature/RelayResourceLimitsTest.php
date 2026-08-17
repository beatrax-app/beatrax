<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use League\Uri\Http as HttpUri;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayDeliverRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;

uses(RefreshDatabase::class);

/*
 * RelayResourceLimitsTest — server-side resource-exhaustion guards for the
 * OPEN POST /relay/deliver endpoint (confirmed security finding: the relay
 * accepts unauthenticated blobs by design, but had no server-side blob size
 * cap, no did format/length validation, no per-mailbox quota, and GET
 * /relay/drain returned every pending row in one response).
 *
 * These tests exercise the REAL RelayServeCommand route handlers directly
 * (via reflection, mirroring RelayRoundTripTest's pattern) so the guards are
 * proven against the actual HTTP boundary — not just the client, which an
 * attacker hitting the socket directly would never go through.
 */

/**
 * Build a bare amphp Request for direct dispatch through
 * RelayServeCommand::route() (private — invoked via reflection).
 */
function buildRelayLimitsRequest(string $method, string $path, string $body = '', array $headers = [], string $clientIp = '203.0.113.1'): AmpRequest
{
    $client = Mockery::mock(AmpClient::class);
    // Deliver reads the source IP for its per-IP rate-limit bucket.
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress($clientIp, 12345));
    $uri = HttpUri::new("https://relay.test{$path}");

    return new AmpRequest($client, $method, $uri, $headers, $body);
}

/**
 * Dispatch a request through the command's private route() method and decode
 * the JSON response body alongside the HTTP status.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function dispatchRelayLimitsRequest(RelayServeCommand $command, AmpRequest $request): array
{
    $route = new ReflectionMethod($command, 'route');

    /** @var AmpResponse $response */
    $response = $route->invoke($command, $request);
    $raw = buffer($response->getBody());
    $decoded = json_decode($raw, true);

    return [
        'status' => $response->getStatus(),
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

beforeEach(function (): void {
    $this->relayConfig = new RelayConfig;
    $this->relayConfig->setEndpointUrl('https://relay.test');
    $this->relayConfig->setAuthToken('relay-shared-secret');

    $this->mailbox = new RelayMailbox(
        app(DatabaseManager::class),
        app(Clock::class),
    );

    $this->command = new RelayServeCommand(
        new NullLogger,
        $this->mailbox,
        new RelayDrainRegistry,
        new RelayDeliverRateLimiter(app(Clock::class)),
        new RelayTlsMaterial,
        new DaemonShutdownSignal,
    );
});

afterEach(function (): void {
    $secretsDir = UserDataPathService::secretsPath();
    $tokenPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-token.json';
    $drainSecretPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-drain-secret.json';
    $drainRegistryPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json';
    $relayPath = UserDataPathService::appPath('sync/relay.json');

    foreach ([$tokenPath, $drainSecretPath, $drainRegistryPath, $relayPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('rejects an oversized blob with 413 and persists no row', function (): void {
    $oversized = str_repeat('A', RelayClient::MAX_BLOB_BYTES + 1);
    $body = json_encode([
        'sender_did' => 'device-sender',
        'recipient_did' => 'device-oversize-victim',
        'blob' => base64_encode($oversized),
    ], JSON_THROW_ON_ERROR);

    $result = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', $body),
    );

    expect($result['status'])->toBe(413);
    expect($result['body']['error'] ?? null)->toBe('blob_too_large');

    $count = DB::table('relay_mailbox')->where('recipient_did', 'device-oversize-victim')->count();
    expect($count)->toBe(0, 'an oversized blob must not be persisted');
});

it('accepts a blob exactly at the server-side cap', function (): void {
    $atCap = str_repeat('B', RelayClient::MAX_BLOB_BYTES);
    $body = json_encode([
        'sender_did' => 'device-sender',
        'recipient_did' => 'device-at-cap',
        'blob' => base64_encode($atCap),
    ], JSON_THROW_ON_ERROR);

    $result = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', $body),
    );

    expect($result['status'])->toBe(202);

    $count = DB::table('relay_mailbox')->where('recipient_did', 'device-at-cap')->count();
    expect($count)->toBe(1, 'a blob exactly at the cap must be accepted');
});

it('rejects a malformed recipient_did with a 4xx and persists no row', function (): void {
    $body = json_encode([
        'sender_did' => 'device-sender',
        'recipient_did' => "device with spaces\tand tabs",
        'blob' => base64_encode(random_bytes(32)),
    ], JSON_THROW_ON_ERROR);

    $result = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', $body),
    );

    expect($result['status'])->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    expect($result['body']['error'] ?? null)->toBe('invalid_did');

    $count = DB::table('relay_mailbox')->count();
    expect($count)->toBe(0, 'a malformed recipient_did must not be persisted');
});

it('rejects an oversized recipient_did with a 4xx and persists no row', function (): void {
    $body = json_encode([
        'sender_did' => 'device-sender',
        'recipient_did' => str_repeat('a', 129),
        'blob' => base64_encode(random_bytes(32)),
    ], JSON_THROW_ON_ERROR);

    $result = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', $body),
    );

    expect($result['status'])->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    expect($result['body']['error'] ?? null)->toBe('invalid_did');

    $count = DB::table('relay_mailbox')->count();
    expect($count)->toBe(0, 'an oversized recipient_did must not be persisted');
});

it('rejects a malformed sender_did with a 4xx and persists no row', function (): void {
    $body = json_encode([
        'sender_did' => "device\nwith\nnewlines",
        'recipient_did' => 'device-recipient',
        'blob' => base64_encode(random_bytes(32)),
    ], JSON_THROW_ON_ERROR);

    $result = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', $body),
    );

    expect($result['status'])->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    expect($result['body']['error'] ?? null)->toBe('invalid_did');

    $count = DB::table('relay_mailbox')->count();
    expect($count)->toBe(0, 'a malformed sender_did must not be persisted');
});

it('rejects delivery once a recipient mailbox is at quota with 429, without exceeding the cap', function (): void {
    $recipientDid = 'device-quota-victim';
    $quota = (new ReflectionClassConstant(RelayServeCommand::class, 'MAX_PENDING_PER_RECIPIENT'))->getValue();

    $rows = [];
    for ($i = 0; $i < $quota; $i++) {
        $rows[] = [
            'sender_did' => 'device-flooder',
            'recipient_did' => $recipientDid,
            'blob' => random_bytes(16),
            'created_at' => '2026-06-15T10:00:00Z',
            'delivered_at' => null,
            'expires_at' => '2026-07-15T10:00:00Z',
        ];
    }
    DB::table('relay_mailbox')->insert($rows);

    $body = json_encode([
        'sender_did' => 'device-flooder',
        'recipient_did' => $recipientDid,
        'blob' => base64_encode(random_bytes(16)),
    ], JSON_THROW_ON_ERROR);

    $result = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', $body),
    );

    expect($result['status'])->toBe(429);
    expect($result['body']['error'] ?? null)->toBe('mailbox_full');

    $count = DB::table('relay_mailbox')->where('recipient_did', $recipientDid)->count();
    expect($count)->toBe($quota, 'the quota-exceeding delivery must not be persisted (no growth beyond the cap)');
});

it('drain caps at the page size and a subsequent drain returns the remainder', function (): void {
    $recipientDid = 'device-paging-recipient';
    $pageSize = (new ReflectionClassConstant(RelayServeCommand::class, 'DRAIN_PAGE_SIZE'))->getValue();
    $total = $pageSize + 10;

    $rows = [];
    for ($i = 0; $i < $total; $i++) {
        $rows[] = [
            'sender_did' => 'device-sender',
            'recipient_did' => $recipientDid,
            'blob' => random_bytes(8),
            'created_at' => sprintf('2026-06-15T%02d:%02d:%02dZ', intdiv($i, 3600) % 24, intdiv($i, 60) % 60, $i % 60),
            'delivered_at' => null,
            'expires_at' => '2026-07-15T10:00:00Z',
        ];
    }
    DB::table('relay_mailbox')->insert($rows);

    $token = $this->relayConfig->deviceDrainSecret();
    expect($token)->not->toBeNull();

    $firstPage = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('GET', "/relay/drain?did={$recipientDid}", '', ['authorization' => "Bearer {$token}"]),
    );

    expect($firstPage['status'])->toBe(200);
    expect($firstPage['body']['blobs'])->toHaveCount($pageSize, 'first drain must be capped at the page size');

    // Confirm every drained row so the second drain call surfaces the remainder
    // (drain does not itself mark rows delivered — confirm does).
    foreach ($firstPage['body']['blobs'] as $row) {
        $confirmResult = dispatchRelayLimitsRequest(
            $this->command,
            buildRelayLimitsRequest('DELETE', "/relay/drain/{$row['id']}", '', ['authorization' => "Bearer {$token}"]),
        );
        expect($confirmResult['status'])->toBe(200);
    }

    $secondPage = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('GET', "/relay/drain?did={$recipientDid}", '', ['authorization' => "Bearer {$token}"]),
    );

    expect($secondPage['status'])->toBe(200);
    expect($secondPage['body']['blobs'])->toHaveCount(
        $total - $pageSize,
        'second drain must return exactly the remainder after the first page was confirmed'
    );
});

it('happy path: a normal blob with valid dids still delivers and drains', function (): void {
    $senderDid = 'device-sender-happy';
    $recipientDid = 'device-recipient-happy';
    $blob = random_bytes(64);

    $deliverResult = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', json_encode([
            'sender_did' => $senderDid,
            'recipient_did' => $recipientDid,
            'blob' => base64_encode($blob),
        ], JSON_THROW_ON_ERROR)),
    );

    expect($deliverResult['status'])->toBe(202);

    $token = $this->relayConfig->deviceDrainSecret();
    $drainResult = dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('GET', "/relay/drain?did={$recipientDid}", '', ['authorization' => "Bearer {$token}"]),
    );

    expect($drainResult['status'])->toBe(200);
    expect($drainResult['body']['blobs'])->toHaveCount(1);
    expect(base64_decode((string) $drainResult['body']['blobs'][0]['blob'], true))->toBe($blob);
});

it('throttles a burst of deliveries from one source IP with 429 rate_limited (L13)', function (): void {
    $recipientDid = 'device-flood-victim';
    $flooderIp = '203.0.113.7';

    $deliver = fn (string $ip): array => dispatchRelayLimitsRequest(
        $this->command,
        buildRelayLimitsRequest('POST', '/relay/deliver', json_encode([
            'sender_did' => 'device-flooder',
            'recipient_did' => $recipientDid,
            'blob' => base64_encode(random_bytes(16)),
        ], JSON_THROW_ON_ERROR), [], $ip),
    );

    // The whole per-window budget from one source IP is accepted (all well
    // under MAX_PENDING_PER_RECIPIENT, so the quota cap never fires here).
    for ($i = 0; $i < RelayDeliverRateLimiter::MAX_PER_WINDOW; $i++) {
        expect($deliver($flooderIp)['status'])->toBe(202);
    }

    // The next delivery from the SAME IP is throttled — and it is the rate
    // limit, not the mailbox_full quota (distinct error code proves which).
    $throttled = $deliver($flooderIp);
    expect($throttled['status'])->toBe(429);
    expect($throttled['body']['error'] ?? null)->toBe('rate_limited');

    // A different source IP is unaffected — the limit is strictly per-source.
    expect($deliver('198.51.100.23')['status'])->toBe(202);
});
