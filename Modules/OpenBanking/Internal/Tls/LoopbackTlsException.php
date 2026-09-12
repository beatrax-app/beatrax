<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Tls;

use RuntimeException;

// The loopback listener has no certificate to present, so the consent redirect
// cannot be received. Internal: the serve command is the only caller.
final class LoopbackTlsException extends RuntimeException
{
    public static function couldNotWriteCertificate(string $directory): self
    {
        return new self(sprintf('Unable to write the loopback TLS certificate to %s.', $directory));
    }

    public static function couldNotCreateDirectory(string $directory): self
    {
        return new self(sprintf('Unable to create the loopback TLS directory at %s.', $directory));
    }

    public static function couldNotCreateConfig(): self
    {
        return new self('Unable to create a temporary OpenSSL config file.');
    }

    // Names the primitive rather than the failure: openssl's error queue
    // supplies the description, and the call sites differ only in which.
    public static function opensslFailed(string $operation, string $error): self
    {
        return new self(sprintf('%s failed: %s', $operation, $error));
    }

    public static function exportProducedNonPem(): self
    {
        return new self('Exporting the generated certificate produced non-string PEM data.');
    }
}
