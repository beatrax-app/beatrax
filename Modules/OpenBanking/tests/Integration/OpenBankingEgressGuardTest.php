<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\UnsafeOpenBankingRequestException;
use Modules\OpenBanking\Tests\Support\EnableBankingFixtures;

function egressGuardRealSigner(): EnableBankingJwtSigner
{
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::now();
        }
    };

    return new EnableBankingJwtSigner($clock);
}

function egressGuardValidPrivateKeyPem(): string
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $pem);

    return $pem;
}

// The rejection credentials carry a deliberately malformed private-key PEM: if
// assertAllowedUrl() stopped running before the signer, signing would throw a
// different type first and these assertions would fail rather than pass emptily.
function egressGuardRejectionCredentials(?string $bankScaHost = null): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: 'THIS IS NOT A VALID PEM BLOCK',
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: $bankScaHost,
        institutionId: 'asn',
    );
}

function egressGuardAcceptedCredentials(string $privateKeyPem): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: 'sca.asnbank.example',
        institutionId: 'asn',
    );
}

function egressGuardRejectionClient(string $attackerBaseUri): EnableBankingHttpClient
{
    return new class(egressGuardRealSigner(), $attackerBaseUri) extends EnableBankingHttpClient
    {
        public function __construct(
            EnableBankingJwtSigner $jwtSigner,
            private readonly string $attackerBaseUri,
        ) {
            parent::__construct($jwtSigner);
        }

        protected function baseUri(): string
        {
            return $this->attackerBaseUri;
        }
    };
}

function egressGuardAcceptedClient(string $baseUri, MockHandler $mock): EnableBankingHttpClient
{
    return new class(egressGuardRealSigner(), $baseUri, $mock) extends EnableBankingHttpClient
    {
        public function __construct(
            EnableBankingJwtSigner $jwtSigner,
            private readonly string $baseUri,
            private readonly MockHandler $mock,
        ) {
            parent::__construct($jwtSigner);
        }

        protected function baseUri(): string
        {
            return $this->baseUri;
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        }
    };
}

/**
 * @return array<string, array{invoke: Closure(EnableBankingHttpClient, OpenBankingCredentials): mixed, mockBody: array<string, mixed>}>
 */
function egressGuardDocumentedCallPaths(): array
{
    return [
        'initiateAuth' => [
            'invoke' => static fn (EnableBankingHttpClient $client, OpenBankingCredentials $credentials): array => $client->initiateAuth(
                credentials: $credentials,
                institutionId: 'asn',
                country: 'NL',
                redirectUrl: 'http://127.0.0.1:9999/oauth/callback/open-banking',
                scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
                validUntil: CarbonImmutable::now()->addDays(90),
            ),
            'mockBody' => ['url' => 'https://sca.example/consent', 'authorization_id' => 'auth-fixture-id'],
        ],
        'createSession' => [
            'invoke' => static fn (EnableBankingHttpClient $client, OpenBankingCredentials $credentials): array => $client->createSession($credentials, 'fixture-code'),
            'mockBody' => ['session_id' => 'session-fixture-id', 'accounts' => [['uid' => 'account-fixture-uid']]],
        ],
        'aspsps' => [
            'invoke' => static fn (EnableBankingHttpClient $client, OpenBankingCredentials $credentials): array => $client->aspsps($credentials, 'NL'),
            'mockBody' => ['aspsps' => [['name' => 'ASN Bank', 'country' => 'NL']]],
        ],
        'accountDetails' => [
            'invoke' => static fn (EnableBankingHttpClient $client, OpenBankingCredentials $credentials): array => $client->accountDetails($credentials, 'fixture-uid'),
            'mockBody' => ['uid' => 'fixture-uid', 'iban' => 'NL91ABNA0417164300'],
        ],
        'transactions' => [
            'invoke' => static fn (EnableBankingHttpClient $client, OpenBankingCredentials $credentials): array => $client->transactions(
                $credentials,
                'fixture-uid',
                new FetchWindow(dateFrom: CarbonImmutable::now()->subDays(30), dateTo: CarbonImmutable::now()),
            ),
            'mockBody' => EnableBankingFixtures::transactions(),
        ],
        'balances' => [
            'invoke' => static fn (EnableBankingHttpClient $client, OpenBankingCredentials $credentials): array => $client->balances($credentials, 'fixture-uid'),
            'mockBody' => EnableBankingFixtures::balances(),
        ],
    ];
}

foreach (egressGuardDocumentedCallPaths() as $name => $callPath) {
    $invoke = $callPath['invoke'];
    $mockBody = $callPath['mockBody'];

    it("{$name}() refuses an attacker-controlled host before any bearer token is attached", function () use ($invoke): void {
        $client = egressGuardRejectionClient('https://attacker.example.com/');

        expect(fn () => $invoke($client, egressGuardRejectionCredentials()))->toThrow(
            UnsafeOpenBankingRequestException::class,
            'non-allow-listed host: attacker.example.com',
        );
    });

    it("{$name}() refuses a look-alike host before any bearer token is attached", function () use ($invoke): void {
        $client = egressGuardRejectionClient('https://api.enablebanking.com.evil.example/');

        expect(fn () => $invoke($client, egressGuardRejectionCredentials()))->toThrow(
            UnsafeOpenBankingRequestException::class,
            'non-allow-listed host: api.enablebanking.com.evil.example',
        );
    });

    it("{$name}() refuses a non-HTTPS scheme even against the real Enable Banking host", function () use ($invoke): void {
        $client = egressGuardRejectionClient('http://api.enablebanking.com/');

        expect(fn () => $invoke($client, egressGuardRejectionCredentials()))->toThrow(
            UnsafeOpenBankingRequestException::class,
            'non-HTTPS scheme',
        );
    });

    it("{$name}() succeeds against the real Enable Banking host once mocked at the transport layer", function () use ($invoke, $mockBody): void {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockBody, JSON_THROW_ON_ERROR)),
        ]);
        $client = egressGuardAcceptedClient('https://api.enablebanking.com/', $mock);

        $result = $invoke($client, egressGuardAcceptedCredentials(egressGuardValidPrivateKeyPem()));

        expect($result)->toBe($mockBody);
    });
}

// The SCA host is reached by the READER's browser, through Redirector::away()
// in the connect controller. No URL this client builds ever lands on it, so an
// SCA host on the credentials must not widen the list a token is checked against.
it('refuses the bank SCA host carried on the credentials, and still refuses an attacker host beside it', function (): void {
    $credentials = egressGuardRejectionCredentials(bankScaHost: 'sca.asnbank.example');

    $scaClient = egressGuardRejectionClient('https://sca.asnbank.example/');
    expect(fn () => $scaClient->aspsps($credentials, 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: sca.asnbank.example',
    );

    $attackerClient = egressGuardRejectionClient('https://attacker.example.com/');
    expect(fn () => $attackerClient->aspsps($credentials, 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});
