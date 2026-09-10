<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Support;

// Whether a host names a machine that cannot be reached from off this network.
// Discovery answers this structurally — the address kept for an mDNS instance is
// the one its datagram physically arrived from — and a scanned `host=` has no
// such proof behind it, so it is held to the rule instead (see @link).
/**
 * @link ../../../../.docs/features/sync/lan-discovery-trust-model.md#an-address-that-came-from-a-scan-answers-the-same-question
 */
final class LanOnlyHost
{
    // The relay's own endpoint may be `localhost` -- the desktop hosting it is
    // this machine. A PEER at localhost is this machine too, and that is not a
    // peer, so the scanned-address callers ask admits() and the relay asks this.
    public static function admitsOrIsThisMachine(string $host): bool
    {
        return $host === 'localhost' || self::admits($host);
    }

    public static function admits(string $host): bool
    {
        // A name is never admitted. DNS resolves to wherever it likes and may
        // resolve differently between this check and the dial, so a hostname
        // carries no claim at all about which network it lands on.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // Loopback and RFC 1918 only, which is the rule the relay endpoint out
        // of the same QR is already held to. Link-local (169.254 — APIPA, and
        // the 169.254.169.254 metadata endpoint) and every other reserved range
        // are refused rather than treated as "close enough to local".
        return str_starts_with($host, '127.') || self::isPrivateIpv4($host);
    }

    // NO_PRIV_RANGE fails only for the private ranges, so a failure here is
    // precisely the RFC 1918 LAN case (reserved and link-local addresses pass
    // it and are therefore rejected).
    private static function isPrivateIpv4(string $host): bool
    {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE,
        ) === false;
    }
}
