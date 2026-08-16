<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use League\Uri\Http as HttpUri;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;

uses(RefreshDatabase::class);

/*
 * RelayRoundTripTest — END-TO-END relay contract regression guard (CR-01..CR-04).
 *
 * This is the test the code review specifically asked for: it exercises the REAL
 * RelayClient against the REAL RelayServeCommand route handlers, so the three
 * independent wire-contract bugs (CR-01 deliver envelope, CR-02 drain query key,
 * CR-03 drain response envelope) and the CR-04 per-device drain authorization can
 * never silently regress.
 *
 * Wiring: a fake Illuminate HTTP Factory intercepts the RelayClient's outbound
 * HTTP calls, translates each into an amphp Request, dispatches it through the
 * real RelayServeCommand::route() (via reflection — route() is private), and maps
 * the amphp Response back to an Illuminate client response. No socket server is
 * booted; the actual handler code path (handleDeliver / handleDrain /
 * handleConfirm / isAuthorized) runs for real against a real RelayMailbox + DB.
 *
 * ZK: the test never decrypts the blob — it is opaque random bytes throughout.
 */

/**
 * Build an HttpFactory whose requests are routed through the real
 * RelayServeCommand handler instead of the network.
 */
function relayServerHttpFactory(RelayServeCommand $command): HttpFactory
{
    $factory = new HttpFactory;

    $route = new ReflectionMethod($command, 'route');

    $factory->fake(function (ClientRequest $request) use ($command, $route) {
        $method = $request->method();
        $url = $request->url();

        $ampClient = Mockery::mock(AmpClient::class);
        $ampUri = HttpUri::new($url);

        $headers = [];
        if ($request->hasHeader('Authorization')) {
            $headers['authorization'] = $request->header('Authorization')[0];
        }

        $body = $request->body();

        $ampRequest = new AmpRequest($ampClient, $method, $ampUri, $headers, $body);

        /** @var AmpResponse $ampResponse */
        $ampResponse = $route->invoke($command, $ampRequest);

        $status = $ampResponse->getStatus();
        $respBody = buffer($ampResponse->getBody());

        return HttpFactory::response($respBody, $status, [
            'Content-Type' => 'application/json',
        ]);
    });

    return $factory;
}

beforeEach(function (): void {
    // Configure the relay endpoint (HTTPS so RelayClient does not reject it) and
    // a relay secret. The per-device drain token is derived from this secret.
    $this->relayConfig = new RelayConfig;
    $this->relayConfig->setEndpointUrl('https://relay.test');
    $this->relayConfig->setAuthToken('relay-shared-secret');

    $mailbox = new RelayMailbox(
        app(DatabaseManager::class),
        app(Clock::class),
    );

    $command = new RelayServeCommand(new NullLogger, $mailbox, $this->relayConfig, new RelayTlsMaterial, new DaemonShutdownSignal);

    $this->relayClient = new RelayClient(
        relayServerHttpFactory($command),
        $this->relayConfig,
        new NullLogger,
    );
});

afterEach(function (): void {
    // Clean up the on-disk relay config + token written during the test. Delete
    // the files outright (not just clear the values) so no artifact is left in
    // storage/app/secrets — keeping the working tree clean.
    $tokenPath = UserDataPathService::secretsPath()
        .DIRECTORY_SEPARATOR.'sync-relay-token.json';
    $relayPath = UserDataPathService::appPath('sync/relay.json');

    foreach ([$tokenPath, $relayPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('round-trips deliver -> drain -> confirm against the real relay handler (CR-01..CR-04)', function (): void {
    $senderDid = 'device-sender';
    $recipientDid = 'device-recipient';
    $blob = random_bytes(96); // opaque Noise ciphertext — never decrypted here.

    // 1. DELIVER — proves CR-01 (JSON envelope sender_did/recipient_did/blob:base64).
    $this->relayClient->deliver($senderDid, $recipientDid, $blob);

    // The blob lands in the recipient's mailbox, pending, stored verbatim.
    $row = DB::table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->first();
    expect($row)->not->toBeNull('deliver() must persist the blob (CR-01 contract)');
    expect($row->sender_did)->toBe($senderDid);
    expect($row->blob)->toBe($blob, 'blob must be stored verbatim (ZK invariant)');
    expect($row->delivered_at)->toBeNull('freshly delivered blob is pending');

    // 2. DRAIN — proves CR-02 (?did= query key) + CR-03 ({blobs:[...]} unwrap).
    $deviceToken = $this->relayConfig->deriveDeviceToken($recipientDid);
    expect($deviceToken)->not->toBeNull();

    $rows = $this->relayClient->drain($recipientDid, $deviceToken);

    expect($rows)->toBeArray()->toHaveCount(1, 'drain() must return the single pending row, unwrapped');
    $drained = $rows[0];
    expect($drained)->toBeArray()->toHaveKeys(['id', 'sender_did', 'blob']);
    expect($drained['sender_did'])->toBe($senderDid);
    expect(base64_decode((string) $drained['blob'], true))->toBe($blob, 'drained blob must round-trip the ciphertext');

    // 3. CONFIRM — proves the confirm path + CR-04 per-device authorization.
    $this->relayClient->confirm((int) $drained['id'], $deviceToken);

    $confirmed = DB::table('relay_mailbox')
        ->where('id', $drained['id'])
        ->first();
    expect($confirmed->delivered_at)->not->toBeNull('confirm() must mark the row delivered');
});

it('rejects a drain whose per-device token is scoped to a different device (CR-04)', function (): void {
    $recipientDid = 'device-victim';
    $this->relayClient->deliver('device-sender', $recipientDid, random_bytes(48));

    // Token derived for a DIFFERENT device — must not unlock the victim's mailbox.
    $wrongDeviceToken = $this->relayConfig->deriveDeviceToken('device-attacker');
    expect($wrongDeviceToken)->not->toBeNull();

    expect(fn () => $this->relayClient->drain($recipientDid, $wrongDeviceToken))
        ->toThrow(RuntimeException::class);

    // The relay-wide shared secret must ALSO be rejected (no single global token).
    expect(fn () => $this->relayClient->drain($recipientDid, 'relay-shared-secret'))
        ->toThrow(RuntimeException::class);
});

it('rejects a confirm whose per-device token is scoped to a different device (CR-04)', function (): void {
    $recipientDid = 'device-victim-2';
    $this->relayClient->deliver('device-sender', $recipientDid, random_bytes(48));

    $id = (int) DB::table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->value('id');

    $wrongDeviceToken = $this->relayConfig->deriveDeviceToken('device-attacker');

    expect(fn () => $this->relayClient->confirm($id, $wrongDeviceToken))
        ->toThrow(RuntimeException::class);

    // Row must remain pending — the unauthorized confirm did not delete/mark it.
    $row = DB::table('relay_mailbox')->where('id', $id)->first();
    expect($row->delivered_at)->toBeNull('unauthorized confirm must not mark the row delivered');
});
