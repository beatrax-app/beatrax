<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Support;

use Modules\Core\Public\Support\PatternScan;

// A peer's `host:port` as a reader types it. An address, never a URL: the dial
// builds its own `ws://` around this, so a scheme or a path accepted here would
// be carried into a string nothing can parse back out.
final readonly class PeerAddress
{
    private function __construct(public string $host, public int $port) {}

    // Null for anything the dial could not build a socket from, so a caller
    // reports one refusal instead of storing an address that fails later, on a
    // screen with less to say about it.
    public static function parse(string $typed): ?self
    {
        $typed = trim($typed);

        // The LAST colon, which is the port separator on every form this
        // accepts, and the only one on all of them.
        $at = strrpos($typed, ':');

        if ($at === false || $at === 0 || $at === strlen($typed) - 1) {
            return null;
        }

        $host = substr($typed, 0, $at);
        $port = substr($typed, $at + 1);

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return null;
        }

        return self::isDialableHost($host) ? new self($host, (int) $port) : null;
    }

    public function value(): string
    {
        return $this->host.':'.$this->port;
    }

    private static function isDialableHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return PatternScan::matches(
            '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
            $host,
        );
    }
}
