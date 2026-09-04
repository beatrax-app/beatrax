<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Tls;

use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;

final readonly class LoopbackTlsCertificate
{
    private const int VALID_DAYS = 825;

    private const string CERT_FILE = 'cert.pem';

    private const string KEY_FILE = 'key.pem';

    public function __construct(
        private string $directory,
        private OwnerOnlyPath $ownerOnly = new OwnerOnlyPath,
    ) {}

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

        // Both halves owner-only BEFORE the PEM lands. Written first and
        // narrowed after, the private key spends the whole write at the umask
        // default of 0644, and a private key read once is read forever. The
        // certificate follows it because only this process ever reads either.
        if (! $this->ownerOnly->file($keyPath) || ! $this->ownerOnly->file($certPath)) {
            throw LoopbackTlsException::couldNotWriteCertificate($this->directory);
        }

        // Suppressed so the `=== false` checks decide: unsuppressed, Laravel's
        // handler turns the E_WARNING into an ErrorException before either
        // comparison runs, and this guard on key material never fires.
        if (@file_put_contents($certPath, $certPem) === false || @file_put_contents($keyPath, $keyPem) === false) {
            throw LoopbackTlsException::couldNotWriteCertificate($this->directory);
        }

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
                throw LoopbackTlsException::opensslFailed('openssl_pkey_new()', $this->opensslError());
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
                throw LoopbackTlsException::opensslFailed('openssl_csr_new()', $this->opensslError());
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
                throw LoopbackTlsException::opensslFailed('openssl_csr_sign()', $this->opensslError());
            }

            $certPem = '';
            $keyPem = '';
            if (! openssl_x509_export($certificate, $certPem) || ! openssl_pkey_export($signingKey, $keyPem, null, ['config' => $opensslConfig])) {
                throw LoopbackTlsException::opensslFailed('Exporting the generated certificate', $this->opensslError());
            }
            if (! is_string($certPem) || ! is_string($keyPem)) {
                throw LoopbackTlsException::exportProducedNonPem();
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
        $extensions = $parsed['extensions'] ?? null;
        $san = is_array($extensions) ? ($extensions['subjectAltName'] ?? '') : '';

        // A cert inside its final day counts as expired, so a long UAT session
        // cannot cross the boundary mid-flow. The 127.0.0.1 SAN is required.
        return is_int($notAfter) && $notAfter > time() + Duration::Day->seconds()
            && is_string($san) && str_contains($san, '127.0.0.1');
    }

    private function prepareDirectory(): void
    {
        if (! $this->ownerOnly->directory($this->directory)) {
            throw LoopbackTlsException::couldNotCreateDirectory($this->directory);
        }

        $gitignore = $this->directory.'/.gitignore';
        if (! is_file($gitignore)) {
            file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }

    private function writeOpensslConfig(): string
    {
        // Beside the certificate rather than /tmp: the content is not secret,
        // but one exempt call site is how the no-/tmp rule erodes.
        $dir = rtrim(UserDataPathService::appPath('tmp-tls'), '/');

        $path = $this->ownerOnly->directory($dir) ? tempnam($dir, 'ob-tls-openssl-') : false;
        if ($path === false) {
            throw LoopbackTlsException::couldNotCreateConfig();
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
