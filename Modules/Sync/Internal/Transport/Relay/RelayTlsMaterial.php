<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;

// Self-signed TLS material for the local relay, plus the SPKI pin a peer uses
// to trust it. No CA can vouch for a LAN address, so the QR — already an
// out-of-band channel carrying identity keys — carries the pin instead, which
// admits exactly one key rather than anything a public CA has signed.
final class RelayTlsMaterial
{
    private const string CERT_FILE = 'sync-relay-cert.pem';

    private const string KEY_FILE = 'sync-relay-key.pem';

    // Long enough that a self-hosted relay is not silently broken by an
    // expiry nobody is watching; the pin, not the validity window, is what
    // actually gates trust here.
    private const int VALID_DAYS = 3650;

    public function certPath(): string
    {
        return UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::CERT_FILE;
    }

    public function keyPath(): string
    {
        return UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::KEY_FILE;
    }

    public function exists(): bool
    {
        return is_file($this->certPath()) && is_file($this->keyPath());
    }

    // Generates the keypair and certificate if absent, then returns the SPKI
    // pin a peer needs in order to trust this relay.
    /**
     * @throws SecretFileException when the material cannot be written
     */
    public function ensure(string $commonName): string
    {
        if (! $this->exists()) {
            $this->generate($commonName);
        }

        return $this->pin();
    }

    // `sha256//<base64>` over the DER SubjectPublicKeyInfo — the exact shape
    // curl's CURLOPT_PINNEDPUBLICKEY expects, so the peer can pin without
    // parsing certificates itself. Pinning the KEY, not the certificate,
    // means re-issuing the cert on the same key keeps existing peers working.
    public function pin(): string
    {
        $certificate = (string) file_get_contents($this->certPath());
        $publicKey = openssl_pkey_get_public($certificate);

        if (! $publicKey instanceof \OpenSSLAsymmetricKey) {
            throw SecretFileException::couldNotStage($this->certPath());
        }

        $details = openssl_pkey_get_details($publicKey);

        if ($details === false || ! isset($details['key']) || ! is_string($details['key'])) {
            throw SecretFileException::couldNotStage($this->certPath());
        }

        $der = $this->pemToDer($details['key']);

        return 'sha256//'.base64_encode(hash('sha256', $der, true));
    }

    private function generate(string $commonName): void
    {
        $directory = dirname($this->certPath());

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw SecretFileException::couldNotCreateSecretsDirectory($directory);
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw SecretFileException::couldNotStage($this->keyPath());
        }

        // The CN is cosmetic: the peer pins the key and does not validate the
        // name, because a LAN address is not a name any CA could vouch for.
        // openssl_csr_new() takes the key BY REFERENCE, so it must be
        // re-narrowed below rather than trusted from the check above.
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);

        if (! $csr instanceof \OpenSSLCertificateSigningRequest || ! $key instanceof \OpenSSLAsymmetricKey) {
            throw SecretFileException::couldNotStage($this->certPath());
        }

        $certificate = openssl_csr_sign($csr, null, $key, self::VALID_DAYS, ['digest_alg' => 'sha256']);

        if (! $certificate instanceof \OpenSSLCertificate) {
            throw SecretFileException::couldNotStage($this->certPath());
        }

        $certificatePem = null;
        $keyPem = null;

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        if (! is_string($certificatePem) || ! is_string($keyPem)) {
            throw SecretFileException::couldNotStage($this->certPath());
        }

        $this->writePrivate($this->certPath(), $certificatePem);
        $this->writePrivate($this->keyPath(), $keyPem);
    }

    // Same posture as every other secret here: the file is created and locked
    // down BEFORE any content lands, so the private key is never even briefly
    // world-readable, and a chmod that fails throws instead of leaving it that
    // way silently.
    private function writePrivate(string $path, string $contents): void
    {
        if (@touch($path) === false) {
            throw SecretFileException::couldNotStage($path);
        }

        if (! @chmod($path, 0600)) {
            throw SecretFileException::couldNotLockDown($path);
        }

        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw SecretFileException::couldNotStage($path);
        }
    }

    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);

        return $der === false ? '' : $der;
    }
}
