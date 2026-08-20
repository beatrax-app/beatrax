<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Tls;

use RuntimeException;

// The loopback HTTPS listener has no certificate to present, so the consent
// redirect cannot be received at all. Internal because it never leaves the
// module: the serve command is the only caller, and it reports the failure to
// the console rather than handing it to another module.
final class LoopbackTlsException extends RuntimeException
{
    public static function couldNotWriteCertificate(string $directory): self
    {
        return new self("Unable to write the loopback TLS certificate to {$directory}.");
    }

    public static function couldNotCreateDirectory(string $directory): self
    {
        return new self("Unable to create the loopback TLS directory at {$directory}.");
    }

    public static function couldNotCreateConfig(): self
    {
        return new self('Unable to create a temporary OpenSSL config file.');
    }

    // Names the primitive that failed rather than describing the failure,
    // because openssl's own error queue supplies the description and the four
    // call sites otherwise differ only in which function they called.
    public static function opensslFailed(string $operation, string $error): self
    {
        return new self("{$operation} failed: {$error}");
    }

    public static function exportProducedNonPem(): self
    {
        return new self('Exporting the generated certificate produced non-string PEM data.');
    }
}
