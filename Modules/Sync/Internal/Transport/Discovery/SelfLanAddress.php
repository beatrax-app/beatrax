<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// The address a peer on this network can dial this device at, for the QR to
// carry to a responder that cannot browse for it (see @link). An IP, not the
// `.local` name the SRV record uses: resolving that is itself multicast, so a
// phone that cannot browse could not resolve one either.
/**
 * @link ../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
final readonly class SelfLanAddress
{
    // The reserved documentation range, which is never routed and never
    // replied to. Connecting a UDP socket sends nothing; it only asks the
    // kernel which interface a packet would leave by, and the local name of
    // that socket is that interface's address.
    private const string ROUTE_PROBE = 'udp://192.0.2.1:9';

    private const float PROBE_TIMEOUT_SECONDS = 1.0;

    public function detect(): ?string
    {
        $socket = @stream_socket_client(self::ROUTE_PROBE, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name)) {
            return null;
        }

        $separator = strrpos($name, ':');
        $host = $separator === false ? '' : substr($name, 0, $separator);

        // What the probe reports on a host with no route out, which names no
        // machine a responder could dial.
        return $host === '' || $host === '0.0.0.0' ? null : $host;
    }
}
