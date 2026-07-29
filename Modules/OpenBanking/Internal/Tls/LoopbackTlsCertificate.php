<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Tls;

use RuntimeException;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class LoopbackTlsCertificate
{
    private const VALID_DAYS = 825;

    private const CERT_FILE = 'cert.pem';

    private const KEY_FILE = 'key.pem';

    public function __construct(private readonly string $directory) {}

    /**
     * @return array{cert: string, key: string}
     */
    public function ensure(bool $regenerate = false): array
    {
        $certPath = $this->directory.'/'.self::CERT_FILE;
        $keyPath = $this->directory.'/'.self::KEY_FILE;

        if (! $regenerate && is_file($certPath) && is_file($keyPath) && $this->stillValid($certPath)) {
            return ['cert' => $certPath, 'key' => $keyPath];
        }

        $this->prepareDirectory();
        [$certPem, $keyPem] = $this->generate();

        // Suppressed so the `=== false` checks decide. Unsuppressed, a failed
        // write raises E_WARNING, which Laravel's handler turns into an
        // ErrorException before either comparison runs — the guard on TLS key
        // material never fired.
        if (@file_put_contents($certPath, $certPem) === false || @file_put_contents($keyPath, $keyPem) === false) {
            throw new RuntimeException('Unable to write the loopback TLS certificate to '.$this->directory.'.');
        }

        // Both halves are owner-only. The certificate is public material and
        // 0644 would be harmless inside a 0700 directory, but nothing outside
        // this process ever reads it: the serve command hands the path straight
        // to its own stream context, and the user verifies by fingerprint.
        @chmod($keyPath, 0600);
        @chmod($certPath, 0600);

        return ['cert' => $certPath, 'key' => $keyPath];
    }

    /**
     * @return array{0: string, 1: string} [certificate PEM, private key PEM]
     */
    public function generate(): array
    {
        $opensslConfig = $this->writeOpensslConfig();

        try {
            $privateKey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'digest_alg' => 'sha256',
                'config' => $opensslConfig,
            ]);
            if (! $privateKey instanceof \OpenSSLAsymmetricKey) {
                throw new RuntimeException('openssl_pkey_new() failed: '.$this->opensslError());
            }

            // openssl_csr_new() takes the key by reference; keep a separate
            // typed handle for signing/export so it survives that call.
            $signingKey = $privateKey;

            $csr = openssl_csr_new(
                ['commonName' => '127.0.0.1'],
                $privateKey,
                [
                    'config' => $opensslConfig,
                    'req_extensions' => 'v3_req',
                    'digest_alg' => 'sha256',
                ],
            );
            if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('openssl_csr_new() failed: '.$this->opensslError());
            }

            $certificate = openssl_csr_sign(
                $csr,
                null,
                $signingKey,
                self::VALID_DAYS,
                [
                    'config' => $opensslConfig,
                    'x509_extensions' => 'v3_req',
                    'digest_alg' => 'sha256',
                ],
                random_int(1, PHP_INT_MAX),
            );
            if (! $certificate instanceof \OpenSSLCertificate) {
                throw new RuntimeException('openssl_csr_sign() failed: '.$this->opensslError());
            }

            $certPem = '';
            $keyPem = '';
            if (! openssl_x509_export($certificate, $certPem) || ! openssl_pkey_export($signingKey, $keyPem, null, ['config' => $opensslConfig])) {
                throw new RuntimeException('Exporting the generated certificate failed: '.$this->opensslError());
            }
            if (! is_string($certPem) || ! is_string($keyPem)) {
                throw new RuntimeException('Exporting the generated certificate produced non-string PEM data.');
            }

            return [$certPem, $keyPem];
        } finally {
            @unlink($opensslConfig);
        }
    }

    private function stillValid(string $certPath): bool
    {
        $pem = @file_get_contents($certPath);
        if ($pem === false || $pem === '') {
            return false;
        }

        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed)) {
            return false;
        }

        $notAfter = $parsed['validTo_time_t'] ?? 0;
        // Treat a cert inside its final day as expired so a long-lived UAT
        // session never trips over the boundary mid-flow.
        if (! is_int($notAfter) || $notAfter <= time() + 86400) {
            return false;
        }

        $extensions = $parsed['extensions'] ?? null;
        $san = is_array($extensions) ? ($extensions['subjectAltName'] ?? '') : '';

        return is_string($san) && str_contains($san, '127.0.0.1');
    }

    private function prepareDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the loopback TLS directory at '.$this->directory.'.');
        }

        @chmod($this->directory, 0700);

        $gitignore = $this->directory.'/.gitignore';
        if (! is_file($gitignore)) {
            file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }

    private function writeOpensslConfig(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ob-tls-openssl-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary OpenSSL config file.');
        }

        $config = <<<'CONF'
        [req]
        distinguished_name = req_dn
        req_extensions = v3_req
        prompt = no

        [req_dn]
        CN = 127.0.0.1

        [v3_req]
        basicConstraints = CA:FALSE
        keyUsage = digitalSignature, keyEncipherment
        extendedKeyUsage = serverAuth
        subjectAltName = @alt_names

        [alt_names]
        IP.1 = 127.0.0.1
        DNS.1 = localhost
        DNS.2 = 127.0.0.1
        CONF;

        file_put_contents($path, $config);

        return $path;
    }

    private function opensslError(): string
    {
        $messages = [];
        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return $messages === [] ? 'unknown error' : implode('; ', $messages);
    }
}
