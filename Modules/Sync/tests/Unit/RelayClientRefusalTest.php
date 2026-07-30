<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\RelayRefusedException;
use Modules\Sync\Internal\Exceptions\RelayUnavailableException;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

uses(RefreshDatabase::class);

/*
 * The two ways a relay call ends badly, kept apart on purpose.
 *
 * The relay is a store-and-forward hop holding ciphertext it cannot read, so
 * an unreachable relay is a transient condition worth retrying. A refusal is
 * not: it means the client declined to send at all, and sending anyway would
 * either exceed what the relay carries or put routing metadata — which device
 * is talking to which — on the wire in the clear.
 */

beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-relayclient-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('BEATRAX_RELAY_ENDPOINT');
});

function relayClientWith(?string $endpoint): RelayClient
{
    /** @var RelayConfig $config */
    $config = app(RelayConfig::class);
    if ($endpoint !== null) {
        mkdir(dirname(UserDataPathService::appPath('sync/relay.json')), 0700, true);
        $config->setEndpointUrl($endpoint);
    }

    return new RelayClient(app(HttpFactory::class), $config);
}

it('refuses to use the relay before an endpoint is configured', function (): void {
    expect(fn () => relayClientWith(null)->deliver('device-a', 'device-b', 'blob'))
        ->toThrow(RelayRefusedException::class, 'No relay endpoint configured');
});

// Routing metadata is visible to anything on the path even though the payload
// is not, which is the whole reason the transport insists on TLS to the relay.
it('refuses to put routing metadata on a plaintext connection', function (): void {
    expect(fn () => relayClientWith('http://relay.example')->deliver('device-a', 'device-b', 'blob'))
        ->toThrow(RelayRefusedException::class, 'must use HTTPS');
});

// The ceiling mirrors the server's, so a blob past it would be rejected at the
// far end after being sent. Refusing here keeps an oversized frame off the
// wire rather than discovering the limit from a response.
it('refuses a blob larger than the relay will carry', function (): void {
    $oversized = str_repeat('a', RelayClient::MAX_BLOB_BYTES + 1);

    expect(fn () => relayClientWith('https://relay.example')->deliver('device-a', 'device-b', $oversized))
        ->toThrow(RelayRefusedException::class, 'too large');
});

it('accepts a blob exactly at the ceiling', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    $atLimit = str_repeat('a', RelayClient::MAX_BLOB_BYTES);
    relayClientWith('https://relay.example')->deliver('device-a', 'device-b', $atLimit);

    Http::assertSentCount(1);
});

// A non-2xx is the relay having a bad day rather than anything about the
// payload, so it arrives as the retryable type and names the status.
it('reports a relay that answers badly as unavailable, not refused', function (int $status): void {
    Http::fake(['*' => Http::response('upstream error', $status)]);

    expect(fn () => relayClientWith('https://relay.example')->deliver('device-a', 'device-b', 'blob'))
        ->toThrow(RelayUnavailableException::class, (string) $status);
})->with([500, 502, 400, 401]);

it('names the relay call that failed', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => relayClientWith('https://relay.example')->deliver('device-a', 'device-b', 'blob'))
        ->toThrow(RelayUnavailableException::class, 'deliver');
});
