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
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
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

// The rejection fixtures carry a deliberately malformed private-key PEM: if
// assertAllowedUrl() stopped running before the signer, signing would throw a
// different type first and these assertions would fail rather than pass emptily.
function egressGuardRejectionSecrets(): OpenBankingSecretsRepository
{
    return new class extends OpenBankingSecretsRepository
    {
        public function __construct() {}

        public function load(): ?OpenBankingCredentials
        {
            return new OpenBankingCredentials(
                applicationId: 'fixture-application-id',
                privateKeyPem: 'THIS IS NOT A VALID PEM BLOCK',
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: null,
                institutionId: 'asn',
            );
        }
    };
}

function egressGuardAcceptedSecrets(string $privateKeyPem): OpenBankingSecretsRepository
{
    return new class($privateKeyPem) extends OpenBankingSecretsRepository
    {
        public function __construct(private readonly string $privateKeyPem) {}

        public function load(): ?OpenBankingCredentials
        {
            return new OpenBankingCredentials(
                applicationId: 'fixture-application-id',
                privateKeyPem: $this->privateKeyPem,
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: 'sca.asnbank.example',
                institutionId: 'asn',
            );
        }
    };
}

function egressGuardRejectionClient(string $attackerBaseUri): EnableBankingHttpClient
{
    return new class(egressGuardRejectionSecrets(), egressGuardRealSigner(), $attackerBaseUri) extends EnableBankingHttpClient
    {
        public function __construct(
            OpenBankingSecretsRepository $secrets,
            EnableBankingJwtSigner $jwtSigner,
            private readonly string $attackerBaseUri,
        ) {
            parent::__construct($secrets, $jwtSigner);
        }

        protected function baseUri(): string
        {
            return $this->attackerBaseUri;
        }
    };
}

function egressGuardAcceptedClient(string $baseUri, MockHandler $mock): EnableBankingHttpClient
{
    $secrets = egressGuardAcceptedSecrets(egressGuardValidPrivateKeyPem());

    return new class($secrets, egressGuardRealSigner(), $baseUri, $mock) extends EnableBankingHttpClient
    {
        public function __construct(
            OpenBankingSecretsRepository $secrets,
            EnableBankingJwtSigner $jwtSigner,
            private readonly string $baseUri,
            private readonly MockHandler $mock,
        ) {
            parent::__construct($secrets, $jwtSigner);
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
 * @return array<string, array{invoke: Closure(EnableBankingHttpClient): mixed, mockBody: array<string, mixed>}>
 */
function egressGuardDocumentedCallPaths(): array
{
    return [
        'initiateAuth' => [
            'invoke' => static fn (EnableBankingHttpClient $client): array => $client->initiateAuth(
                institutionId: 'asn',
                country: 'NL',
                redirectUrl: 'http://127.0.0.1:9999/oauth/callback/open-banking',
                scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
                validUntil: CarbonImmutable::now()->addDays(90),
            ),
            'mockBody' => ['url' => 'https://sca.example/consent', 'authorization_id' => 'auth-fixture-id'],
        ],
        'createSession' => [
            'invoke' => static fn (EnableBankingHttpClient $client): array => $client->createSession('fixture-code'),
            'mockBody' => ['session_id' => 'session-fixture-id', 'accounts' => [['uid' => 'account-fixture-uid']]],
        ],
        'aspsps' => [
            'invoke' => static fn (EnableBankingHttpClient $client): array => $client->aspsps('NL'),
            'mockBody' => ['aspsps' => [['name' => 'ASN Bank', 'country' => 'NL']]],
        ],
        'accountDetails' => [
            'invoke' => static fn (EnableBankingHttpClient $client): array => $client->accountDetails('fixture-uid'),
            'mockBody' => ['uid' => 'fixture-uid', 'iban' => 'NL91ABNA0417164300'],
        ],
        'transactions' => [
            'invoke' => static fn (EnableBankingHttpClient $client): array => $client->transactions(
                'fixture-uid',
                new FetchWindow(dateFrom: CarbonImmutable::now()->subDays(30), dateTo: CarbonImmutable::now()),
            ),
            'mockBody' => EnableBankingFixtures::transactions(),
        ],
        'balances' => [
            'invoke' => static fn (EnableBankingHttpClient $client): array => $client->balances('fixture-uid'),
            'mockBody' => EnableBankingFixtures::balances(),
        ],
    ];
}

foreach (egressGuardDocumentedCallPaths() as $name => $callPath) {
    $invoke = $callPath['invoke'];
    $mockBody = $callPath['mockBody'];

    it("{$name}() refuses an attacker-controlled host before any bearer token is attached", function () use ($invoke): void {
        $client = egressGuardRejectionClient('https://attacker.example.com/');

        expect(fn () => $invoke($client))->toThrow(
            UnsafeOpenBankingRequestException::class,
            'non-allow-listed host: attacker.example.com',
        );
    });

    it("{$name}() refuses a look-alike host before any bearer token is attached", function () use ($invoke): void {
        $client = egressGuardRejectionClient('https://api.enablebanking.com.evil.example/');

        expect(fn () => $invoke($client))->toThrow(
            UnsafeOpenBankingRequestException::class,
            'non-allow-listed host: api.enablebanking.com.evil.example',
        );
    });

    it("{$name}() refuses a non-HTTPS scheme even against the real Enable Banking host", function () use ($invoke): void {
        $client = egressGuardRejectionClient('http://api.enablebanking.com/');

        expect(fn () => $invoke($client))->toThrow(
            UnsafeOpenBankingRequestException::class,
            'non-HTTPS scheme',
        );
    });

    it("{$name}() succeeds against the real Enable Banking host once mocked at the transport layer", function () use ($invoke, $mockBody): void {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($mockBody, JSON_THROW_ON_ERROR)),
        ]);
        $client = egressGuardAcceptedClient('https://api.enablebanking.com/', $mock);

        $result = $invoke($client);

        expect($result)->toBe($mockBody);
    });
}

// The SCA host is reached by the READER's browser, through Redirector::away()
// in the connect controller. No URL this client builds ever lands on it, so a
// persisted SCA host must not widen the list a bearer token is checked against.
it('refuses the persisted bank SCA host, and still refuses an attacker host beside it', function (): void {
    $scaClient = egressGuardRejectionClientWithScaHost('https://sca.asnbank.example/');

    expect(fn () => $scaClient->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: sca.asnbank.example',
    );

    $attackerClient = egressGuardRejectionClientWithScaHost('https://attacker.example.com/');

    expect(fn () => $attackerClient->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});

function egressGuardRejectionClientWithScaHost(string $baseUri): EnableBankingHttpClient
{
    return new class(egressGuardRejectionSecretsWithScaHost('sca.asnbank.example'), egressGuardRealSigner(), $baseUri) extends EnableBankingHttpClient
    {
        public function __construct(
            OpenBankingSecretsRepository $secrets,
            EnableBankingJwtSigner $jwtSigner,
            private readonly string $baseUri,
        ) {
            parent::__construct($secrets, $jwtSigner);
        }

        protected function baseUri(): string
        {
            return $this->baseUri;
        }
    };
}

function egressGuardRejectionSecretsWithScaHost(string $bankScaHost): OpenBankingSecretsRepository
{
    return new class($bankScaHost) extends OpenBankingSecretsRepository
    {
        public function __construct(private readonly string $bankScaHost)
        {
            // No parent::__construct(): this fixture never touches the filesystem.
        }

        public function load(): ?OpenBankingCredentials
        {
            return new OpenBankingCredentials(
                applicationId: 'fixture-application-id',
                privateKeyPem: 'THIS IS NOT A VALID PEM BLOCK',
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: $this->bankScaHost,
                institutionId: 'asn',
            );
        }
    };
}
