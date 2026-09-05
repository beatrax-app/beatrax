<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

use Illuminate\Contracts\Config\Repository;
use Modules\Core\Internal\Enums\NetworkBoundaryState;

/**
 * @link ../../../../.docs/deployment.md#opening-the-loopback-boundary
 */

// Both halves of the address boundary read from here, so they cannot disagree:
// LoopbackOnly gates the interface a connection arrived on, TrustedHostGuard
// gates the name the client asked for, and each one's allowance is a method on
// this object rather than a second copy of the same policy.
final readonly class NetworkBoundary
{
    public const string CONFIG_KEY = 'selfhost.served_interfaces';

    private const string APP_URL_KEY = 'app.url';

    // The names the bundled desktop and mobile shells load the app under. The
    // bracketed IPv6 spelling is what a browser puts in a Host header and the
    // bare one is what Symfony hands back, and the two arrive by different
    // paths, so both are listed.
    /** @var list<string> */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function __construct(private Repository $config) {}

    public function state(): NetworkBoundaryState
    {
        return $this->isWidened() ? NetworkBoundaryState::Widened : NetworkBoundaryState::Loopback;
    }

    public function isWidened(): bool
    {
        foreach ($this->servedInterfaces() as $address) {
            if (! NetworkAddress::isLoopback($address)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function servedInterfaces(): array
    {
        return $this->declared()['served'];
    }

    /** @return list<string> */
    public function refusedInterfaces(): array
    {
        return $this->declared()['refused'];
    }

    // Loopback is served whatever the configuration says; past it, only an
    // address this install has recorded itself as serving on.
    public function serves(string $serverAddress): bool
    {
        if (NetworkAddress::isLoopback($serverAddress)) {
            return true;
        }

        $key = NetworkAddress::comparable($serverAddress);
        if ($key === null) {
            return false;
        }

        foreach ($this->servedInterfaces() as $address) {
            if (NetworkAddress::comparable($address) === $key) {
                return true;
            }
        }

        return false;
    }

    // FrankenPHP and the built-in server bind a real socket and publish no bind
    // address, so a widened install has no interface to check a remote peer
    // against. The recorded host stands in for it, and it has to name something
    // past loopback or every spoofed `Host: localhost` would satisfy it.
    public function servesUnderRecordedHost(string $host): bool
    {
        $authority = $this->remoteHostAuthority();

        return $this->isWidened() && $authority !== null && strtolower($host) === $authority;
    }

    // Null when APP_URL names nothing beyond loopback: a widened install in
    // that state has no evidence a request off an unnamed interface may be
    // served by, which is a misconfiguration the doctor probe reports.
    public function remoteHostAuthority(): ?string
    {
        $recorded = $this->recordedHost();

        return $recorded !== null && ! self::isLoopbackHost($recorded) ? $recorded : null;
    }

    /** @return list<string> */
    public function allowedHosts(): array
    {
        $hosts = self::LOOPBACK_HOSTS;

        $recorded = $this->recordedHost();
        if ($recorded !== null) {
            $hosts[] = $recorded;
        }

        return $hosts;
    }

    private function recordedHost(): ?string
    {
        $configured = $this->config->get(self::APP_URL_KEY);
        $host = is_string($configured) ? parse_url($configured, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array($host, self::LOOPBACK_HOSTS, true) || NetworkAddress::isLoopback($host);
    }

    /** @return array{served: list<string>, refused: list<string>} */
    private function declared(): array
    {
        $raw = $this->config->get(self::CONFIG_KEY);
        if (! is_string($raw)) {
            return ['served' => [], 'refused' => []];
        }

        $served = [];
        $refused = [];

        foreach (explode(',', $raw) as $entry) {
            $address = trim($entry);
            if ($address === '') {
                continue;
            }

            // A hostname needs DNS the gate would have to trust, a CIDR range
            // names more interfaces than the operator wrote down, and the
            // wildcard names none at all. Each is refused and reported rather
            // than resolved, expanded, or read as consent to serve everything.
            if (NetworkAddress::comparable($address) === null || NetworkAddress::isWildcard($address)) {
                $refused[] = $address;

                continue;
            }

            if (! in_array($address, $served, true)) {
                $served[] = $address;
            }
        }

        return ['served' => $served, 'refused' => $refused];
    }
}
