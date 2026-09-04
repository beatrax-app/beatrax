<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Psr\Http\Message\RequestInterface;

// The signer takes the key by value, so nothing about the type stops a caller
// putting it in the payload beside the token it produced. What is asserted here
// is the wire, not the call graph: whatever a request is built from, the key
// must not be recoverable from what left the process.

function privateKeyEgressFreshPem(): string
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }
    openssl_pkey_export($resource, $pem);

    return (string) $pem;
}

function privateKeyEgressSigner(): EnableBankingJwtSigner
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

function privateKeyEgressSecrets(string $privateKeyPem): OpenBankingSecretsRepository
{
    return new class($privateKeyPem) extends OpenBankingSecretsRepository
    {
        public function __construct(private readonly string $privateKeyPem) {}

        public function load(): ?OpenBankingCredentials
        {
            return new OpenBankingCredentials(
                applicationId: 'fixture-application-id',
                privateKeyPem: $this->privateKeyPem,
                sessionId: 'fixture-session-id',
                consentExpiresAt: CarbonImmutable::now()->addDays(90),
                bankScaHost: 'sca.asnbank.example',
                institutionId: 'asn',
            );
        }
    };
}

/**
 * @param  array<int, array{0: mixed, 1: mixed}>  $history
 */
function privateKeyEgressClient(string $privateKeyPem, MockHandler $mock, array &$history): EnableBankingHttpClient
{
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new class(privateKeyEgressSecrets($privateKeyPem), privateKeyEgressSigner(), $stack) extends EnableBankingHttpClient
    {
        public function __construct(
            OpenBankingSecretsRepository $secrets,
            EnableBankingJwtSigner $jwtSigner,
            private readonly HandlerStack $stack,
        ) {
            parent::__construct($secrets, $jwtSigner);
        }

        protected function makeHttpClient(): GuzzleClient
        {
            return new GuzzleClient(['handler' => $this->stack]);
        }
    };
}

/**
 * @return list<Closure(EnableBankingHttpClient): mixed>
 */
function privateKeyEgressCallPaths(): array
{
    return [
        static fn (EnableBankingHttpClient $client): array => $client->initiateAuth(
            institutionId: 'asn',
            country: 'NL',
            redirectUrl: 'http://127.0.0.1:9999/oauth/callback/open-banking',
            scope: new EnableBankingAccessScope(balances: true, transactions: true, accounts: true),
            validUntil: CarbonImmutable::now()->addDays(90),
        ),
        static fn (EnableBankingHttpClient $client): array => $client->createSession('fixture-code'),
        static fn (EnableBankingHttpClient $client): array => $client->aspsps('NL'),
        static fn (EnableBankingHttpClient $client): array => $client->accountDetails('fixture-uid'),
        static fn (EnableBankingHttpClient $client): array => $client->transactions(
            'fixture-uid',
            new FetchWindow(dateFrom: CarbonImmutable::now()->subDays(30), dateTo: CarbonImmutable::now()),
        ),
        static fn (EnableBankingHttpClient $client): array => $client->balances('fixture-uid'),
    ];
}

/**
 * @return list<string> the base64 body lines, which are what a leak carries
 */
function privateKeyEgressBodyLines(string $pem): array
{
    $lines = [];
    foreach (explode("\n", $pem) as $line) {
        $line = trim($line);
        if ($line !== '' && ! str_starts_with($line, '-----')) {
            $lines[] = $line;
        }
    }

    return $lines;
}

// A JSON body escapes every forward slash, and a base64 line usually carries
// one, so a PEM sent through the `json` option matches none of the per-line
// needles. The reader answers on the unescaped copy too, or a leak the encoder
// reshaped reads as a clean request.
/** @param  list<string>  $needles */
function privateKeyEgressCarriesKeyMaterial(string $wire, array $needles): bool
{
    $forms = [$wire, str_replace('\\/', '/', $wire)];

    foreach ($forms as $form) {
        foreach ($needles as $needle) {
            if (str_contains($form, $needle)) {
                return true;
            }
        }
    }

    return false;
}

it('hands the aggregator a token signed by the key and never the key itself', function (): void {
    $privateKeyPem = privateKeyEgressFreshPem();
    $callPaths = privateKeyEgressCallPaths();

    $mock = new MockHandler(array_fill(
        0,
        count($callPaths),
        new Response(200, ['Content-Type' => 'application/json'], '{}'),
    ));

    /** @var array<int, array{request: RequestInterface}> $history */
    $history = [];
    $client = privateKeyEgressClient($privateKeyPem, $mock, $history);

    foreach ($callPaths as $invoke) {
        $invoke($client);
    }

    // Counted before anything is read: a walk that made no request would find
    // no key in it, which is the answer a clean client gives.
    expect($history)->toHaveCount(count($callPaths));

    $bodyLines = privateKeyEgressBodyLines($privateKeyPem);
    expect($bodyLines)->not->toBeEmpty();

    $leaked = [];
    $signed = 0;

    foreach ($history as $entry) {
        $request = $entry['request'];

        $headers = '';
        foreach ($request->getHeaders() as $name => $values) {
            $headers .= $name.': '.implode(',', $values)."\n";
        }
        if (str_contains($headers, 'Authorization: Bearer ')) {
            $signed++;
        }

        $wire = $request->getMethod().' '.$request->getUri()."\n".$headers."\n".(string) $request->getBody();

        if (privateKeyEgressCarriesKeyMaterial($wire, ['PRIVATE KEY', $privateKeyPem, ...$bodyLines])) {
            $leaked[] = $request->getMethod().' '.$request->getUri()->getPath();
        }
    }

    // Every path signs, so a request list with no bearer in it would mean the
    // signing branch was never reached and the key never had a chance to leak.
    expect($signed)->toBe(count($callPaths));

    expect($leaked)->toBe(
        [],
        'The RSA private key backs the reader\'s own aggregator registration and is generated on '
        .'their machine, so it must never appear in an outbound request — sign with it and send '
        ."the token. These requests carried key material:\n  ".implode("\n  ", $leaked),
    );

    // The same reader over requests that do carry it, so an empty leak list is
    // never mistaken for a check that stopped reading. The JSON form is the one
    // a leak would really take, and it is the one plain matching misses.
    $rawWire = "POST /auth\n\n".$privateKeyPem;
    $jsonWire = "POST /auth\n\n".json_encode(['client_key' => $privateKeyPem], JSON_THROW_ON_ERROR);

    expect(privateKeyEgressCarriesKeyMaterial($rawWire, [$bodyLines[0]]))->toBeTrue()
        ->and(privateKeyEgressCarriesKeyMaterial($jsonWire, [$bodyLines[0]]))->toBeTrue();
});
