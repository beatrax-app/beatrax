<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;

// Points this device at its own `relay:serve` when nothing else is configured.
// Pairing frames travel only over the relay courier, so with no endpoint the
// handshake had nowhere to deliver and the phone polled forever.
final readonly class LocalRelayProvisioner
{
    public function __construct(
        private RelayConfig $config,
        private RelayTlsMaterial $tls,
    ) {}

    // Returns the endpoint this device should advertise, or null when no LAN
    // address could be determined. An operator-set endpoint always wins — a
    // self-hosted relay must never be clobbered by the local one.
    public function ensureConfigured(int $port): ?string
    {
        $existing = $this->config->endpointUrl();

        // An operator's own relay is configuration this device does not own:
        // never re-point it, never mint a token for it, never pin it. Only a
        // LAN endpoint is ours to keep correct.
        if ($existing !== null && ! $this->config->isLanEndpoint($existing)) {
            return $existing;
        }

        $address = $this->lanAddress();

        // Offline, or no routable interface. An endpoint already stored is
        // still the best answer available — dropping it here would strip the
        // relay from the QR every time the machine was off the network.
        if ($address === null) {
            return $existing;
        }

        // Unconditional, not first-run-only. A device that stored the endpoint
        // before the pin existed spent the rest of its life dialling a relay
        // whose certificate it could not verify, which on screen is pairing
        // that simply never completes.
        $pin = $this->tls->ensure($address);

        // The LAN address, not 127.0.0.1: this same value is what the QR hands
        // the phone, and loopback there would point it at itself. Re-derived
        // rather than kept, so a laptop moving between networks does not leave
        // peers holding an address that now belongs to another machine.
        $endpoint = 'https://'.$address.':'.$port;

        if ($existing !== $endpoint) {
            $this->config->setEndpointUrl($endpoint);
        }

        if ($this->config->pin() !== $pin) {
            $this->config->setPin($pin);
        }

        return $endpoint;
    }

    // Reads the address the OS would use to reach the outside world. The UDP
    // "connect" sends no packet — it only asks the routing table which local
    // interface owns the default route, which is the one peers can reach.
    private function lanAddress(): ?string
    {
        $socket = @stream_socket_client('udp://198.51.100.1:53', $errno, $errstr, 1);

        if ($socket === false) {
            return null;
        }

        $name = @stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || $name === '') {
            return null;
        }

        $address = substr($name, 0, (int) strrpos($name, ':'));

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            ? null
            : $address;
    }
}
