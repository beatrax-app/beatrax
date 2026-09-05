<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;

// Asserted from a real Guzzle response, not by constructing the exception: the
// status is the only thing telling a lapsed consent from a bank having a bad
// day, and a hand-built exception would pass even if the client stopped reading it.

// The client holds no credential of its own, so the DTO is built here and
// handed to the call, rather than read back out of a secrets double.
function ebErrCredentials(string $privateKeyPem): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: null,
        institutionId: 'asn',
    );
}

function ebErrClient(EnableBankingJwtSigner $signer, MockHandler $mock): EnableBankingHttpClient
{
    return new class($signer, $mock) extends EnableBankingHttpClient
    {
        public function __construct(
            EnableBankingJwtSigner $jwtSigner,
            private readonly MockHandler $mock,
        ) {
            parent::__construct($jwtSigner);
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        }
    };
}

beforeEach(function (): void {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);
    $this->credentials = ebErrCredentials($privateKeyPem);

    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-07-19 06:30:00');
        }
    };

    $this->signer = new EnableBankingJwtSigner($clock);
});

it('carries the response status onto the exception', function (int $status, bool $terminal): void {
    $mock = new MockHandler([new Response($status, [], 'upstream said no')]);
    $client = ebErrClient($this->signer, $mock);

    try {
        $client->aspsps($this->credentials, 'NL');
        expect(false)->toBeTrue('the client should not have returned a result');
    } catch (EnableBankingApiException $e) {
        expect($e->status)->toBe($status)
            ->and($e->isConsentFailure())->toBe($terminal)
            ->and($e->getMessage())->toContain('upstream said no');
    }
})->with([
    'unauthorized' => [401, true],
    'forbidden' => [403, true],
    'rate limited' => [429, false],
    'server error' => [500, false],
]);

// A connection that never produced a response has no status to read, and
// calling that terminal would strand a connection that needed the network back.
it('reports a transport failure with no status at all', function (): void {
    $mock = new MockHandler([
        new ConnectException('Connection refused', new Request('GET', 'https://api.enablebanking.com/aspsps')),
    ]);
    $client = ebErrClient($this->signer, $mock);

    try {
        $client->aspsps($this->credentials, 'NL');
        expect(false)->toBeTrue('the client should not have returned a result');
    } catch (EnableBankingApiException $e) {
        expect($e->status)->toBeNull()
            ->and($e->isConsentFailure())->toBeFalse()
            ->and($e->getMessage())->toContain('Connection refused');
    }
});

it('refuses a 2xx whose body is not JSON', function (): void {
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{not json')]);
    $client = ebErrClient($this->signer, $mock);

    expect(fn () => $client->aspsps($this->credentials, 'NL'))
        ->toThrow(EnableBankingApiException::class, 'did not decode as JSON');
});

// A bare scalar is valid JSON with no fields: the call succeeded and the body
// was empty, which every caller already handles.
it('reads a well-formed non-object body as an empty result', function (string $body): void {
    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]);
    $client = ebErrClient($this->signer, $mock);

    expect($client->aspsps($this->credentials, 'NL'))->toBe([]);
})->with([
    'a string' => ['"just a string"'],
    'a number' => ['42'],
    'null' => ['null'],
]);

// postJson() and getJson() carry their own try/catch, so the two drift unless
// both are driven.
it('maps a POST failure the same way it maps a GET failure', function (): void {
    $mock = new MockHandler([new Response(401, [], 'session expired')]);
    $client = ebErrClient($this->signer, $mock);

    try {
        $client->initiateAuth(
            credentials: $this->credentials,
            institutionId: 'ASNBNL21',
            country: 'NL',
            redirectUrl: 'https://127.0.0.1:8443/open-banking/callback',
            scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
            validUntil: CarbonImmutable::parse('2026-10-19 00:00:00'),
        );
        expect(false)->toBeTrue('the client should not have returned a result');
    } catch (EnableBankingApiException $e) {
        expect($e->status)->toBe(401)
            ->and($e->isConsentFailure())->toBeTrue()
            ->and($e->getMessage())->toContain('POST');
    }
});

it('reports a POST that never reached the API as a transport failure', function (): void {
    $mock = new MockHandler([
        new ConnectException('Connection refused', new Request('POST', 'https://api.enablebanking.com/auth')),
    ]);
    $client = ebErrClient($this->signer, $mock);

    try {
        $client->initiateAuth(
            credentials: $this->credentials,
            institutionId: 'ASNBNL21',
            country: 'NL',
            redirectUrl: 'https://127.0.0.1:8443/open-banking/callback',
            scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
            validUntil: CarbonImmutable::parse('2026-10-19 00:00:00'),
        );
        expect(false)->toBeTrue('the client should not have returned a result');
    } catch (EnableBankingApiException $e) {
        expect($e->status)->toBeNull()
            ->and($e->isConsentFailure())->toBeFalse();
    }
});
