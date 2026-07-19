<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingJwtSigner;

/*
 * EnableBankingJwtSigner: proves the RS256 round-trip (signed via the
 * private key, verified via the paired public key) and the exact claim
 * shape Enable Banking requires — kid = application_id, exp - iat =
 * 3600. EnableBankingAccessScope: proves toArray() can never surface a
 * `payments` key (Req 11 / D-11 Pitfall 6), since no such property
 * exists on the class.
 */

beforeEach(function (): void {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Test fixture: failed to generate RSA keypair.');
    }

    $exported = openssl_pkey_export($resource, $privateKeyPem);
    if (! $exported) {
        throw new RuntimeException('Test fixture: failed to export RSA private key.');
    }
    $this->privateKeyPem = $privateKeyPem;

    $details = openssl_pkey_get_details($resource);
    if ($details === false) {
        throw new RuntimeException('Test fixture: failed to read RSA key details.');
    }
    $this->publicKeyPem = $details['key'];

    // Fixed 5 minutes in the past, relative to the real clock — avoids
    // JWT::decode's BeforeValidException (iat in the future) while
    // staying deterministic per test run.
    $this->fixedNow = CarbonImmutable::now()->subMinutes(5);
    $this->clock = new class($this->fixedNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $fixedNow) {}

        public function now(): CarbonImmutable
        {
            return $this->fixedNow;
        }
    };
});

it('signs a JWT that round-trips against the paired public key with kid = application_id and exp - iat = 3600', function (): void {
    $signer = new EnableBankingJwtSigner($this->clock);

    $jwt = $signer->sign($this->privateKeyPem, 'my-application-id');

    $headers = new stdClass;
    $decoded = JWT::decode($jwt, new Key($this->publicKeyPem, 'RS256'), $headers);

    expect($headers)->not->toBeNull()
        ->and($headers->alg)->toBe('RS256')
        ->and($headers->kid)->toBe('my-application-id')
        ->and($decoded->iss)->toBe('enablebanking.com')
        ->and($decoded->aud)->toBe('api.enablebanking.com')
        ->and((int) $decoded->iat)->toBe($this->fixedNow->getTimestamp())
        ->and((int) $decoded->exp - (int) $decoded->iat)->toBe(3600);
});

it('fails verification against a mismatched public key', function (): void {
    $signer = new EnableBankingJwtSigner($this->clock);
    $jwt = $signer->sign($this->privateKeyPem, 'my-application-id');

    $otherResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $otherDetails = openssl_pkey_get_details($otherResource);
    $otherPublicKeyPem = $otherDetails['key'];

    expect(fn () => JWT::decode($jwt, new Key($otherPublicKeyPem, 'RS256')))
        ->toThrow(SignatureInvalidException::class);
});

it('toArray() never surfaces a payments key regardless of which booleans are set', function (): void {
    $allTrue = new EnableBankingAccessScope(balances: true, transactions: true, accounts: true);
    $allFalse = new EnableBankingAccessScope(balances: false, transactions: false, accounts: false);
    $mixed = new EnableBankingAccessScope(balances: true, transactions: false, accounts: true);

    expect($allTrue->toArray())->toBe(['balances' => true, 'transactions' => true, 'accounts' => true])
        ->and($allTrue->toArray())->not->toHaveKey('payments')
        ->and($allFalse->toArray())->toBe([])
        ->and($mixed->toArray())->toBe(['balances' => true, 'accounts' => true])
        ->and(array_keys($mixed->toArray()))->each->toBeIn(['balances', 'transactions', 'accounts']);
});
