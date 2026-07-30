<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Tls\LoopbackTlsException;
use Modules\OpenBanking\Public\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Public\Exceptions\OpenBankingConnectionException;
use Modules\OpenBanking\Public\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Public\Exceptions\UnsafeOpenBankingRequestException;

/*
 * The messages these exceptions carry, and the one decision taken from them.
 *
 * Each type builds its message from a named constructor rather than at the
 * throw site, so the identifiers a maintainer needs — which connection, which
 * host, which openssl primitive — live in one place instead of being
 * interpolated at each of twenty-two sites. That only helps if the identifiers
 * actually reach the message, which is what these assert. Several throw sites
 * are unreachable from a test (openssl_pkey_new() failing on a working build),
 * so without this their formatting would go unchecked entirely.
 */

it('names the call and keeps the transport failure as the cause', function (): void {
    $cause = new RuntimeException('cURL error 28: timeout');
    $e = EnableBankingApiException::transportFailed('https://api.enablebanking.com/aspsps', $cause);

    expect($e->getMessage())->toContain('https://api.enablebanking.com/aspsps')
        ->and($e->getMessage())->toContain('cURL error 28: timeout')
        ->and($e->getPrevious())->toBe($cause)
        ->and($e->status)->toBeNull();
});

it('carries the HTTP status alongside the body snippet', function (): void {
    $e = EnableBankingApiException::errorStatus('GET /aspsps', 500, 'upstream exploded');

    expect($e->status)->toBe(500)
        ->and($e->getMessage())->toContain('GET /aspsps')
        ->and($e->getMessage())->toContain('HTTP 500')
        ->and($e->getMessage())->toContain('upstream exploded');
});

it('names the call whose body would not decode and keeps the JsonException', function (): void {
    $cause = new JsonException('Syntax error');
    $e = EnableBankingApiException::malformedJson('GET /sessions', $cause);

    expect($e->getMessage())->toContain('GET /sessions')
        ->and($e->getPrevious())->toBe($cause);
});

it('says which field a transaction row was missing', function (): void {
    expect(EnableBankingApiException::missingTransactionField('booking_date')->getMessage())
        ->toContain('booking_date')
        ->and(EnableBankingApiException::missingOwnAccountIban()->getMessage())
        ->toContain('IBAN');
});

// 401 and 403 are the two statuses no retry can repair. Every other status,
// and every failure that never reached a status at all, must stay retryable —
// misreading one as terminal would strand a connection that only needed
// another attempt.
it('treats only 401 and 403 as a consent failure', function (?int $status, bool $expected): void {
    $e = $status === null
        ? EnableBankingApiException::transportFailed('GET /aspsps', new RuntimeException('offline'))
        : EnableBankingApiException::errorStatus('GET /aspsps', $status, 'body');

    expect($e->isConsentFailure())->toBe($expected);
})->with([
    [401, true],
    [403, true],
    [400, false],
    [429, false],
    [500, false],
    [null, false],
]);

// The job and the settings page both act on this, and the import pipeline
// between the provider call and their catch blocks is free to wrap what it
// rethrows — so the answer cannot depend on the failure arriving unwrapped.
it('finds a consent failure through a wrapping exception', function (): void {
    $inner = EnableBankingApiException::errorStatus('GET /aspsps', 401, 'unauthorized');
    $wrapped = new RuntimeException('import run failed', 0, new RuntimeException('stage failed', 0, $inner));

    expect(EnableBankingApiException::consentFailureWithin($wrapped))->toBeTrue();
});

it('reports no consent failure for an unrelated throwable', function (): void {
    expect(EnableBankingApiException::consentFailureWithin(new RuntimeException('disk full')))->toBeFalse()
        ->and(EnableBankingApiException::consentFailureWithin(
            EnableBankingApiException::errorStatus('GET /aspsps', 500, 'boom'),
        ))->toBeFalse();
});

it('names the host a bearer token was refused to', function (): void {
    expect(UnsafeOpenBankingRequestException::disallowedHost('attacker.example.com')->getMessage())
        ->toContain('attacker.example.com')
        ->and(UnsafeOpenBankingRequestException::nonHttpsScheme()->getMessage())
        ->toContain('non-HTTPS');
});

// parse_url() returns null for a host it cannot read, and the refusal still
// has to say something — an empty "refused to: " reads as a bug in the guard
// rather than a rejection of the URL.
it('still names an unparseable host', function (?string $host): void {
    expect(UnsafeOpenBankingRequestException::disallowedHost($host)->getMessage())
        ->toContain('(unparseable)');
})->with([[null], ['']]);

it('says which connection could not be fetched, and why', function (): void {
    expect(OpenBankingConnectionException::notFound(7, 42)->getMessage())
        ->toContain('7')
        ->and(OpenBankingConnectionException::notFound(7, 42)->getMessage())->toContain('42')
        ->and(OpenBankingConnectionException::notFetchable(7)->getMessage())
        ->toContain('consent has expired')
        ->and(OpenBankingConnectionException::accountNotResolved(7)->getMessage())
        ->toContain('account_uid')
        ->and(OpenBankingConnectionException::institutionMismatch(7, 'ING', 'ABN')->getMessage())
        ->toContain('ING');
});

it('names both institutions in a session mismatch', function (): void {
    // Naming only one would leave a maintainer unable to tell which of the two
    // is the stale side, which is the whole question the message has to answer.
    $message = OpenBankingConnectionException::institutionMismatch(7, 'ING', 'ABN')->getMessage();

    expect($message)->toContain('ING')->and($message)->toContain('ABN');
});

// The path is the only identifier this message may carry: the decoded payload
// and the raw bytes are both credential material, and this exception is
// logged wherever it surfaces.
it('names the unreadable secrets file without carrying its contents', function (): void {
    $cause = new JsonException('Syntax error');
    $e = OpenBankingCredentialsException::unreadable('/data/eb-secrets.json', $cause);

    expect($e->getMessage())->toContain('/data/eb-secrets.json')
        ->and($e->getMessage())->not->toContain('Syntax error')
        ->and($e->getPrevious())->toBe($cause)
        ->and(OpenBankingCredentialsException::notConfigured()->getMessage())
        ->toContain('No Enable Banking application credentials are persisted.');
});

it('names the openssl primitive that gave up and the directory it could not use', function (): void {
    expect(LoopbackTlsException::opensslFailed('openssl_csr_sign()', 'error:0909006C')->getMessage())
        ->toBe('openssl_csr_sign() failed: error:0909006C')
        ->and(LoopbackTlsException::couldNotWriteCertificate('/data/tls')->getMessage())
        ->toContain('/data/tls')
        ->and(LoopbackTlsException::couldNotCreateDirectory('/data/tls')->getMessage())
        ->toContain('/data/tls')
        ->and(LoopbackTlsException::couldNotCreateConfig()->getMessage())
        ->toContain('OpenSSL config')
        ->and(LoopbackTlsException::exportProducedNonPem()->getMessage())
        ->toContain('non-string PEM');
});

// Two controllers and the sync job catch RuntimeException to decide what the
// user is shown. A changed base class would be silent at the throw site and
// only surface as an uncaught exception in production.
it('keeps every failure catchable as a RuntimeException', function (string $class): void {
    expect(is_subclass_of($class, RuntimeException::class))->toBeTrue();
})->with([
    EnableBankingApiException::class,
    UnsafeOpenBankingRequestException::class,
    OpenBankingCredentialsException::class,
    OpenBankingConnectionException::class,
    LoopbackTlsException::class,
]);
