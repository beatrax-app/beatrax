<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// A relay request the client declined to make. Nothing was sent and no retry
// helps: the endpoint is unset or not HTTPS, or the blob exceeds what the
// relay will carry. Each needs a change by the caller or the operator before
// the request could succeed.
final class RelayRefusedException extends RuntimeException
{
    public static function blobTooLarge(int $bytes, int $maximum): self
    {
        return new self("Relay blob too large ({$bytes} bytes). Maximum is {$maximum} bytes.");
    }

    public static function notConfigured(): self
    {
        return new self(
            'No relay endpoint configured. Set an endpoint URL via '
            .'RelayConfig::setEndpointUrl() before using the relay.',
        );
    }

    // Routing metadata — which device is talking to which — is visible to
    // anything on the path even though the payload is not, which is the whole
    // reason the transport insists on TLS to the relay.
    public static function endpointNotHttps(): self
    {
        return new self(
            'Relay endpoint must use HTTPS to protect routing metadata. '
            .'The configured endpoint appears to use plain HTTP.',
        );
    }

    // Unreachable while isConfigured() and the endpoint accessor agree. Kept
    // because the alternative to raising here is sending a bearer-bearing
    // request to a null endpoint.
    public static function endpointVanished(): self
    {
        return new self('Relay endpoint unexpectedly null after isConfigured() check.');
    }
}
