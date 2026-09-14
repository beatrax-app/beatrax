<?php

declare(strict_types=1);

use Amp\Http\HttpStatus;
use Amp\Http\Server\Driver\Client as AmpDriverClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket;
use Amp\Socket\BindContext;
use Amp\Socket\InternetAddress;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use League\Uri\Http as HttpUri;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayDrainToken;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Modules\Sync\Tests\Support\RecordingLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Amp\ByteStream\buffer;

uses(RefreshDatabase::class);

// The relay is the one listener on this device that answers a caller it has not
// authenticated, so every way it can fail has to end in a refusal it chose. A
// store it cannot reach is the case nothing exercised: the endpoint is open by
// design, and an unhandled throw there is a stack trace on an open port.
/**
 * @link ../../../../.docs/features/sync/relay-endpoint-authorization.md
 */
function relayDaemonCommand(RecordingLogger $logger): RelayServeCommand
{
    return new RelayServeCommand(
        $logger,
        new RelayMailbox(app(DatabaseManager::class), app(Clock::class)),
        new RelayDrainRegistry,
        new RelayRateLimiter(app(Clock::class)),
        new RelayTlsMaterial,
        new DaemonShutdownSignal,
    );
}

function relayDaemonRoute(RelayServeCommand $command, string $method, string $url, string $body = '', ?string $bearer = null): AmpResponse
{
    $client = Mockery::mock(AmpDriverClient::class);
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 40000 + random_int(1, 20000)));

    $headers = $bearer === null ? [] : ['authorization' => 'Bearer '.$bearer];

    /** @var AmpResponse $response */
    $response = (new ReflectionMethod($command, 'route'))->invoke(
        $command,
        new AmpRequest($client, $method, HttpUri::new($url), $headers, $body),
    );

    return $response;
}

/**
 * @return array{0: int, 1: string} [exit code, console output]
 */
function relayDaemonRun(RelayServeCommand $command, int $port): array
{
    $output = new BufferedOutput;
    $command->setLaravel(app());

    $code = $command->run(new ArrayInput(['--port' => (string) $port]), $output);

    return [$code, $output->fetch()];
}

afterEach(function (): void {
    $secrets = UserDataPathService::secretsPath();

    foreach (['sync-relay-cert.pem', 'sync-relay-key.pem', 'sync-relay-drain-registry.json', 'sync-relay-drain-tokens.json'] as $file) {
        $path = $secrets.DIRECTORY_SEPARATOR.$file;

        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('reports a failure instead of parking when it cannot take the port', function (): void {
    // Held by this test, never the desktop's own: a listener already on the
    // address is the one start-up failure a supervisor actually sees.
    $held = Socket\listen('0.0.0.0:0');
    $port = $held->getAddress()->getPort();

    $logger = new RecordingLogger;

    try {
        [$code, $output] = relayDaemonRun(relayDaemonCommand($logger), $port);
    } finally {
        $held->close();
    }

    expect($code)->toBe(RelayServeCommand::FAILURE)
        ->and($output)->toContain('relay:serve: fatal')
        ->and($logger->said('relay:serve: fatal error.'))->toBeTrue()
        ->and($logger->said('no usable TLS material'))->toBeTrue('a relay that drops to plaintext must say so out loud');
});

it('refuses a port outside the range a socket can carry', function (): void {
    $logger = new RecordingLogger;

    [$code, $output] = relayDaemonRun(relayDaemonCommand($logger), 70000);

    expect($code)->toBe(RelayServeCommand::FAILURE)
        ->and($output)->toContain('invalid port 70000');
});

it('binds TLS only when the material can actually serve a connection', function (): void {
    $logger = new RecordingLogger;
    $command = relayDaemonCommand($logger);
    $context = new ReflectionMethod($command, 'tlsBindContext');

    expect($context->invoke($command))->toBeNull('there is no material yet')
        ->and($logger->said('no usable TLS material'))->toBeTrue();

    (new RelayTlsMaterial)->ensure('beatrax-relay.test');

    $bound = $context->invoke($command);

    expect($bound)->toBeInstanceOf(BindContext::class)
        ->and($bound?->getTlsContext())->not->toBeNull('a key that opens its certificate must never be served around');
});

it('answers a delivery it cannot store with a refusal rather than a stack trace', function (): void {
    $logger = new RecordingLogger;
    $command = relayDaemonCommand($logger);

    Schema::drop('relay_mailbox');

    $response = relayDaemonRoute($command, 'POST', 'http://127.0.0.1:9/relay/deliver', json_encode([
        'sender_did' => 'device-sender',
        'recipient_did' => 'device-recipient',
        'blob' => base64_encode(random_bytes(32)),
    ], JSON_THROW_ON_ERROR));

    $body = buffer($response->getBody());

    expect($response->getStatus())->toBe(HttpStatus::INTERNAL_SERVER_ERROR)
        ->and($body)->toBe('{"error":"deliver_failed"}')
        ->and(str_contains($body, 'relay_mailbox'))->toBeFalse('an open endpoint must not describe the store behind it')
        ->and($logger->said('relay:serve: deliver failed.'))->toBeTrue();
});

it('answers a drain it cannot read with a refusal rather than a stack trace', function (): void {
    $logger = new RecordingLogger;
    $command = relayDaemonCommand($logger);

    // Authorized first, so the refusal below is the store failing and not the
    // token check standing in front of it.
    $token = RelayDrainToken::mint('device-recipient');
    expect(relayDaemonRoute($command, 'GET', 'http://127.0.0.1:9/relay/drain?did=device-recipient', bearer: $token)->getStatus())
        ->toBe(HttpStatus::OK);

    Schema::drop('relay_mailbox');

    $response = relayDaemonRoute($command, 'GET', 'http://127.0.0.1:9/relay/drain?did=device-recipient', bearer: $token);

    expect($response->getStatus())->toBe(HttpStatus::INTERNAL_SERVER_ERROR)
        ->and(buffer($response->getBody()))->toBe('{"error":"drain_failed"}')
        ->and($logger->said('relay:serve: drain failed.'))->toBeTrue();
});

it('answers a confirm it cannot write with a refusal rather than a stack trace', function (): void {
    $logger = new RecordingLogger;
    $command = relayDaemonCommand($logger);
    $db = app(DatabaseManager::class);

    $token = RelayDrainToken::mint('device-recipient');
    relayDaemonRoute($command, 'GET', 'http://127.0.0.1:9/relay/drain?did=device-recipient', bearer: $token);

    $id = $db->connection()->table('relay_mailbox')->insertGetId([
        'sender_did' => 'device-sender',
        'recipient_did' => 'device-recipient',
        'blob' => random_bytes(16),
        'created_at' => '2026-09-01T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-10-01T10:00:00Z',
    ]);

    // Readable enough to resolve the recipient, unwritable by the time the
    // confirm lands — the shape a caller retries into.
    $db->connection()->statement('CREATE TRIGGER relay_no_writes BEFORE UPDATE ON relay_mailbox BEGIN SELECT RAISE(ABORT, \'locked\'); END');

    $response = relayDaemonRoute($command, 'DELETE', 'http://127.0.0.1:9/relay/drain/'.$id, bearer: $token);

    expect($response->getStatus())->toBe(HttpStatus::INTERNAL_SERVER_ERROR)
        ->and(buffer($response->getBody()))->toBe('{"error":"confirm_failed"}')
        ->and($logger->said('relay:serve: confirm failed.'))->toBeTrue();

    $db->connection()->statement('DROP TRIGGER relay_no_writes');
});

it('lets nobody confirm a row whose recipient is blank', function (): void {
    $logger = new RecordingLogger;
    $command = relayDaemonCommand($logger);
    $db = app(DatabaseManager::class);

    // A row that names no device is unreachable by construction: every token
    // is scoped to a device id, and '' is not one.
    $id = $db->connection()->table('relay_mailbox')->insertGetId([
        'sender_did' => 'device-sender',
        'recipient_did' => '',
        'blob' => random_bytes(16),
        'created_at' => '2026-09-01T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-10-01T10:00:00Z',
    ]);

    $response = relayDaemonRoute($command, 'DELETE', 'http://127.0.0.1:9/relay/drain/'.$id, bearer: RelayDrainToken::mint(''));

    expect($response->getStatus())->toBe(HttpStatus::UNAUTHORIZED)
        ->and($db->connection()->table('relay_mailbox')->where('id', $id)->value('delivered_at'))
        ->toBeNull('a blank recipient must not be a slot anyone can claim');
});

it('only promises signal handling on a runtime that has both halves of it', function (): void {
    $command = relayDaemonCommand(new RecordingLogger);

    expect((new ReflectionMethod($command, 'canTrapSignals'))->invoke($command))
        ->toBe(\function_exists('pcntl_signal') && \defined('SIGTERM') && \defined('SIGINT'));
});
