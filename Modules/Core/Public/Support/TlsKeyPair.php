<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use OpenSSLAsymmetricKey;

// Present is not usable. Binding a TLS listener reads neither half — a
// `tls://` server with a missing certificate and a garbage key binds with
// errno 0 — so a key that does not open its certificate is discovered by the
// first client, as a reset connection with nothing in any log.
final class TlsKeyPair
{
    public static function opensCertificate(string $certificatePath, string $keyPath): bool
    {
        if (! is_file($certificatePath) || ! is_file($keyPath)) {
            return false;
        }

        $certificate = @file_get_contents($certificatePath);
        $key = @file_get_contents($keyPath);

        if ($certificate === false || $key === false) {
            return false;
        }

        // Suppressed so the guard decides: unsuppressed, Laravel's handler
        // turns the E_WARNING a non-PEM file raises into an ErrorException
        // before either check runs.
        $private = @openssl_pkey_get_private($key);

        return $private instanceof OpenSSLAsymmetricKey
            && @openssl_x509_check_private_key($certificate, $private);
    }
}
