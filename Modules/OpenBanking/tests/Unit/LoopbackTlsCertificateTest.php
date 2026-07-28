<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;

/*
 * Unit coverage for the self-signed loopback certificate the
 * `open-banking:serve-tls` listener presents (19-VALIDATION follow-up:
 * the HTTPS-loopback TLS listener that unblocks the live Enable Banking
 * consent dance). The socket pump itself is exercised by manual UAT; here
 * we prove the certificate material is well-formed and loopback-scoped.
 */

beforeEach(function (): void {
    $this->tlsDir = sys_get_temp_dir().'/ob-tls-test-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    if (is_dir($this->tlsDir)) {
        foreach (glob($this->tlsDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tlsDir);
    }
});

it('generates a self-signed cert covering the loopback host and localhost', function (): void {
    $cert = new LoopbackTlsCertificate($this->tlsDir);

    $paths = $cert->ensure();

    expect($paths['cert'])->toBeFile()
        ->and($paths['key'])->toBeFile();

    $parsed = openssl_x509_parse((string) file_get_contents($paths['cert']));

    expect($parsed)->toBeArray()
        ->and($parsed['subject']['CN'])->toBe('127.0.0.1')
        ->and($parsed['extensions']['subjectAltName'])->toContain('127.0.0.1')
        ->and($parsed['extensions']['subjectAltName'])->toContain('localhost')
        // notAfter must be comfortably in the future.
        ->and($parsed['validTo_time_t'])->toBeGreaterThan(time() + 86400);
});

it('writes both halves 0600 inside a 0700 directory with a .gitignore', function (): void {
    $cert = new LoopbackTlsCertificate($this->tlsDir);

    $paths = $cert->ensure();

    expect(substr(sprintf('%o', fileperms($paths['key'])), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($paths['cert'])), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($this->tlsDir)), -4))->toBe('0700')
        ->and($this->tlsDir.'/.gitignore')->toBeFile()
        ->and(file_get_contents($this->tlsDir.'/.gitignore'))->toContain('*');
});

it('reuses an existing valid certificate instead of regenerating', function (): void {
    $cert = new LoopbackTlsCertificate($this->tlsDir);

    $first = $cert->ensure();
    $firstSerial = openssl_x509_parse((string) file_get_contents($first['cert']))['serialNumberHex'] ?? null;

    $second = $cert->ensure();
    $secondSerial = openssl_x509_parse((string) file_get_contents($second['cert']))['serialNumberHex'] ?? null;

    expect($secondSerial)->toBe($firstSerial);
});

it('regenerates when forced', function (): void {
    $cert = new LoopbackTlsCertificate($this->tlsDir);

    $first = $cert->ensure();
    $firstSerial = openssl_x509_parse((string) file_get_contents($first['cert']))['serialNumberHex'] ?? null;

    $second = $cert->ensure(regenerate: true);
    $secondSerial = openssl_x509_parse((string) file_get_contents($second['cert']))['serialNumberHex'] ?? null;

    expect($secondSerial)->not->toBe($firstSerial);
});
