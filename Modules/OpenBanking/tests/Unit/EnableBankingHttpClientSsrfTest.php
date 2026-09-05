<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\UnsafeOpenBankingRequestException;

// No public method takes a caller-supplied URL, so the rejection cases override
// the protected baseUri() hook. The refusal lands before a bearer token is
// attached or a Guzzle client is built, so nothing leaves the process.

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

// The credentials are a call argument now rather than something the client
// reads for itself, so each case hands them in beside the URL under test.
function ebSsrfCredentials(string $privateKeyPem, ?string $bankScaHost = null): OpenBankingCredentials
{
    return new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: $privateKeyPem,
        sessionId: null,
        consentExpiresAt: null,
        bankScaHost: $bankScaHost,
        institutionId: 'asn',
    );
}

function ebSsrfClientOn(EnableBankingJwtSigner $jwtSigner, string $baseUri): EnableBankingHttpClient
{
    return new class($jwtSigner, $baseUri) extends EnableBankingHttpClient
    {
        public function __construct(
            EnableBankingJwtSigner $jwtSigner,
            private readonly string $uri,
        ) {
            parent::__construct($jwtSigner);
        }

        protected function baseUri(): string
        {
            return $this->uri;
        }
    };
}

it('rejects an attacker-controlled host before any bearer token is attached', function (): void {
    $client = ebSsrfClientOn($this->jwtSigner, 'https://attacker.example.com/');

    expect(fn () => $client->aspsps(ebSsrfCredentials($this->privateKeyPem), 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});

it('rejects a look-alike host', function (): void {
    $client = ebSsrfClientOn($this->jwtSigner, 'https://api.enablebanking.com.evil.example/');

    expect(fn () => $client->aspsps(ebSsrfCredentials($this->privateKeyPem), 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: api.enablebanking.com.evil.example',
    );
});

it('rejects a non-HTTPS scheme even on the real Enable Banking host', function (): void {
    $client = ebSsrfClientOn($this->jwtSigner, 'http://api.enablebanking.com/');

    expect(fn () => $client->aspsps(ebSsrfCredentials($this->privateKeyPem), 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-HTTPS scheme',
    );
});

it('rejects an unparseable URL', function (): void {
    $client = ebSsrfClientOn($this->jwtSigner, '://no-scheme-here/');

    expect(fn () => $client->aspsps(ebSsrfCredentials($this->privateKeyPem), 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'bearer token',
    );
});

// The SCA host is where the READER's browser goes, via an outward redirect this
// client never issues. Nothing here builds a URL on it, so allow-listing it
// would authorise a bearer-token request no production path makes.
it('rejects the resolved bank SCA host: this client only ever talks to the API host', function (): void {
    $credentials = ebSsrfCredentials($this->privateKeyPem, bankScaHost: 'sca.asnbank.example');
    $client = ebSsrfClientOn($this->jwtSigner, 'https://sca.asnbank.example/');

    expect(fn () => $client->aspsps($credentials, 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: sca.asnbank.example',
    );
});

it('rejects an attacker host even when a bank SCA host has separately been resolved', function (): void {
    $credentials = ebSsrfCredentials($this->privateKeyPem, bankScaHost: 'sca.asnbank.example');
    $client = ebSsrfClientOn($this->jwtSigner, 'https://attacker.example.com/');

    expect(fn () => $client->aspsps($credentials, 'NL'))->toThrow(
        UnsafeOpenBankingRequestException::class,
        'non-allow-listed host: attacker.example.com',
    );
});
