<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;

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

// ensure() regenerates only when stillValid() says no, so each way of saying no
// is pinned. Getting it wrong is quiet: the browser refuses the redirect and
// the consent dance fails with nothing in the logs naming the certificate.
it('regenerates rather than presenting a certificate it cannot read', function (string $replacement): void {
    $cert = new LoopbackTlsCertificate($this->tlsDir);
    $paths = $cert->ensure();
    file_put_contents($paths['cert'], $replacement);

    $regenerated = (new LoopbackTlsCertificate($this->tlsDir))->ensure();

    $parsed = openssl_x509_parse((string) file_get_contents($regenerated['cert']));

    expect($parsed)->toBeArray()
        ->and($parsed['subject']['CN'])->toBe('127.0.0.1');
})->with([
    'an empty file' => [''],
    'text that is not a certificate' => ['not a certificate at all'],
    'a PEM header with no body' => ["-----BEGIN CERTIFICATE-----\n-----END CERTIFICATE-----\n"],
]);

// A certificate inside its final day is treated as expired, so a long UAT
// session cannot cross the boundary halfway through a consent dance.
it('regenerates a certificate that expires within the day', function (): void {
    $cert = new LoopbackTlsCertificate($this->tlsDir);
    $paths = $cert->ensure();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($key)->not->toBeFalse();
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
    expect($csr)->not->toBeFalse();
    $shortLived = openssl_csr_sign($csr, null, $key, 1);
    expect($shortLived)->not->toBeFalse();
    openssl_x509_export($shortLived, $shortLivedPem);
    file_put_contents($paths['cert'], $shortLivedPem);

    $regenerated = (new LoopbackTlsCertificate($this->tlsDir))->ensure();
    $parsed = openssl_x509_parse((string) file_get_contents($regenerated['cert']));

    expect($parsed)->toBeArray()
        ->and($parsed['validTo_time_t'])->toBeGreaterThan(time() + 86400);
});

it('refuses when the certificate directory cannot be created', function (): void {
    // A plain file where the directory belongs: is_dir() false, mkdir() failing.
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ob-tls-blocked-'.bin2hex(random_bytes(6));
    file_put_contents($path, 'not a directory');

    expect(fn () => (new LoopbackTlsCertificate($path))->ensure())
        ->toThrow(RuntimeException::class, 'Unable to create the loopback TLS directory');

    @unlink($path);
});
