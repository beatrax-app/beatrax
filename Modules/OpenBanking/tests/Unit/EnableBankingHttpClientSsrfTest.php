<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Exceptions\UnsafeOpenBankingRequestException;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

/*
 * SSRF / bearer-token-leak regression test for EnableBankingHttpClient
 * (D-11, T-19-04-01). Mirrors GraphApiClientSsrfTest's structure — 5
 * cases: attacker host, non-HTTPS on the EB host, look-alike host,
 * unparseable URL, and the dynamic bank-SCA-host case accepted.
 *
 * Unlike GraphApiClient, none of this client's public methods accept a
 * caller-supplied URL (GraphApiClient's deltaLink/nextLink parameters
 * let its test drive an attacker host straight through a real
 * production method argument). Every URL here is built from
 * `baseUri()`, so the negative cases override that protected hook to
 * substitute a hostile base — the request is refused before any bearer
 * token is attached or any Guzzle client is even constructed, so no
 * real network call is ever attempted. The one positive case
 * additionally overrides `makeHttpClient()` with a MockHandler-backed
 * client so the accepted-host path never leaves the process either.
 */

beforeEach(function (): void {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $privateKeyPem);
    $this->privateKeyPem = $privateKeyPem;

    $this->clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::now();
        }
    };
    $this->jwtSigner = new EnableBankingJwtSigner($this->clock);
});

/**
 * Builds a fixture secrets-repository stub (the "empty-constructor
 * anonymous-subclass stub" pattern GraphApiClientSsrfTest already
 * establishes) returning fixed application credentials, optionally
 * carrying a resolved bank SCA host.
 */
function ebFixtureSecrets(string $privateKeyPem, ?string $bankScaHost = null): OpenBankingSecretsRepository
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

it('rejects an attacker-controlled host before any bearer token is attached', function (): void {
    $secrets = ebFixtureSecrets($this->privateKeyPem);
    $client = new class($secrets, $this->jwtSigner) extends EnableBankingHttpClient
    {
        protected function baseUri(): string
        {
            return 'https://attacker.example.com/';
        }
    };

    expect(fn () => $client->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});

it('rejects a look-alike host', function (): void {
    $secrets = ebFixtureSecrets($this->privateKeyPem);
    $client = new class($secrets, $this->jwtSigner) extends EnableBankingHttpClient
    {
        protected function baseUri(): string
        {
            return 'https://api.enablebanking.com.evil.example/';
        }
    };

    expect(fn () => $client->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: api.enablebanking.com.evil.example',
    );
});

it('rejects a non-HTTPS scheme even on the real Enable Banking host', function (): void {
    $secrets = ebFixtureSecrets($this->privateKeyPem);
    $client = new class($secrets, $this->jwtSigner) extends EnableBankingHttpClient
    {
        protected function baseUri(): string
        {
            return 'http://api.enablebanking.com/';
        }
    };

    expect(fn () => $client->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-HTTPS scheme',
    );
});

it('rejects an unparseable URL', function (): void {
    $secrets = ebFixtureSecrets($this->privateKeyPem);
    $client = new class($secrets, $this->jwtSigner) extends EnableBankingHttpClient
    {
        protected function baseUri(): string
        {
            return '://no-scheme-here/';
        }
    };

    expect(fn () => $client->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'bearer token',
    );
});

it('accepts the resolved bank SCA host dynamically merged into the allow-list', function (): void {
    $secrets = ebFixtureSecrets($this->privateKeyPem, bankScaHost: 'sca.asnbank.example');
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['aspsps' => []], JSON_THROW_ON_ERROR)),
    ]);
    $client = new class($secrets, $this->jwtSigner, $mock) extends EnableBankingHttpClient
    {
        public function __construct(
            OpenBankingSecretsRepository $secrets,
            EnableBankingJwtSigner $jwtSigner,
            private readonly MockHandler $mock,
        ) {
            parent::__construct($secrets, $jwtSigner);
        }

        protected function baseUri(): string
        {
            return 'https://sca.asnbank.example/';
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
        }
    };

    $result = $client->aspsps('NL');

    expect($result)->toBe(['aspsps' => []]);
});

it('rejects an attacker host even when a bank SCA host has separately been resolved', function (): void {
    $secrets = ebFixtureSecrets($this->privateKeyPem, bankScaHost: 'sca.asnbank.example');
    $client = new class($secrets, $this->jwtSigner) extends EnableBankingHttpClient
    {
        protected function baseUri(): string
        {
            return 'https://attacker.example.com/';
        }
    };

    expect(fn () => $client->aspsps('NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});
