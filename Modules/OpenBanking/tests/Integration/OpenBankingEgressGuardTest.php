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
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Exceptions\UnsafeOpenBankingRequestException;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Tests\Support\EnableBankingFixtures;

/*
 * Req 10 falsifiable local-only egress guard (19-13 Task 2). Drives the
 * REAL `EnableBankingHttpClient` boundary across every documented call
 * path — `initiateAuth`, `createSession`, `aspsps`, `accountDetails`,
 * `transactions`, `balances` — and proves each one:
 *
 *   (a) refuses a URL whose host is neither `api.enablebanking.com` nor
 *       the resolved bank SCA host;
 *   (b) refuses a non-HTTPS scheme, even on the real Enable Banking
 *       host;
 *   (c) does BOTH of the above BEFORE any bearer token is attached;
 *   (d) succeeds against the real Enable Banking host (and, for the
 *       representative `aspsps()` call, the dynamically-resolved bank
 *       SCA host) once mocked at the Guzzle transport layer — proving
 *       the guard is a genuine allow-list, not a deny-list that happens
 *       to also break the happy path.
 *
 * "Before any bearer token is attached" (c) is proven WITHOUT
 * subclassing `EnableBankingJwtSigner` (it is `final`, matching the
 * project's discipline of not leaving cryptographic signing classes
 * extensible) — the rejection-path fixture credentials instead carry a
 * deliberately MALFORMED private-key PEM. If `assertAllowedUrl()` did
 * not run before `EnableBankingJwtSigner::sign()`, signing the
 * malformed key would throw FIRST, and it would throw a DIFFERENT type —
 * so the assertion on `UnsafeOpenBankingRequestException` would fail.
 * This makes the ordering claim falsifiable against a real regression,
 * not merely asserted against a stub. The type is what carries the
 * claim now; the message is only asserted where the specific host
 * rejected is itself part of what is being proven.
 *
 * Function names are prefixed `egressGuard*` — distinct from both
 * `OpenBankingSecretsFileGuardTest`'s `openBankingGuard*` and
 * `OpenBankingAisOnlyArchTest`'s `aisOnlyGuard*` helpers — because Pest
 * loads every test file's top-level functions into the same global
 * namespace within one PHP process, and a shared name would fatal with
 * "cannot redeclare function".
 */

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

/**
 * Rejection-path fixture credentials: a MALFORMED private key proves the
 * assert-before-sign ordering (see file docblock).
 */
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

function egressGuardAcceptedSecrets(string $privateKeyPem, ?string $bankScaHost = null): OpenBankingSecretsRepository
{
    return new class($privateKeyPem, $bankScaHost) extends OpenBankingSecretsRepository
    {
        public function __construct(
            private readonly string $privateKeyPem,
            private readonly ?string $bankScaHost,
        ) {}

        public function load(): ?OpenBankingCredentials
        {
            return new OpenBankingCredentials(
                applicationId: 'fixture-application-id',
                privateKeyPem: $this->privateKeyPem,
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: $this->bankScaHost,
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

function egressGuardAcceptedClient(string $baseUri, MockHandler $mock, ?string $bankScaHost = null): EnableBankingHttpClient
{
    $secrets = egressGuardAcceptedSecrets(egressGuardValidPrivateKeyPem(), $bankScaHost);

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

it('accepts the dynamically-resolved bank SCA host once persisted, without weakening the attacker-host rejection', function (): void {
    $mockBody = ['aspsps' => []];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode($mockBody, JSON_THROW_ON_ERROR)),
    ]);
    $acceptedClient = egressGuardAcceptedClient('https://sca.asnbank.example/', $mock, bankScaHost: 'sca.asnbank.example');

    expect($acceptedClient->aspsps('NL'))->toBe($mockBody);

    // The SAME resolved SCA host does NOT grant a free pass to a
    // DIFFERENT attacker host merely because a bank SCA host has been
    // persisted on the credential.
    $rejectionClient = new class(egressGuardRejectionSecretsWithScaHost('sca.asnbank.example'), egressGuardRealSigner(), 'https://attacker.example.com/') extends EnableBankingHttpClient
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

    expect(fn () => $rejectionClient->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});

function egressGuardRejectionSecretsWithScaHost(string $bankScaHost): OpenBankingSecretsRepository
{
    return new class($bankScaHost) extends OpenBankingSecretsRepository
    {
        public function __construct(private readonly string $bankScaHost)
        {
            // Deliberately does NOT call parent::__construct() — this
            // fixture never touches the filesystem, so the parent's
            // Filesystem/SecretShield dependencies are unnecessary.
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
