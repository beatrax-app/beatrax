<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// Browses for a service by speaking mDNS on the wire rather than shelling out
// to dns-sd or avahi, which a phone has neither of. Without this a fresh device
// could only learn where the desktop is from a scanned QR, which is why the
// typed-code arm of the import flow could never succeed.
final class MulticastMdnsQuery implements PeerDiscovery
{
    public const string MULTICAST_ADDRESS = '224.0.0.251';

    public const int MULTICAST_PORT = 5353;

    private const int CLASS_INTERNET = 1;

    // RFC 6762 §5.4: setting the top bit of the question's class asks
    // responders to reply straight to this socket. Receiving the multicast
    // reply instead would need a group join, which the mobile runtimes do not
    // reliably allow and which Android gates behind a multicast lock.
    private const int UNICAST_RESPONSE_BIT = 0x8000;

    // Ethernet tops out far below this; the margin covers a jumbo frame
    // rather than letting a long response arrive truncated.
    private const int MAX_DATAGRAM_BYTES = 9000;

    // One length byte per label, so 63 is the protocol's own ceiling and a
    // longer label cannot be encoded at all.
    private const int MAX_LABEL_BYTES = 63;

    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    private const string SERVICE_DOMAIN = 'local';

    public function __construct(
        private readonly MdnsResponseParser $parser = new MdnsResponseParser,
    ) {}

    /**
     * @param  string  $serviceType  e.g. `_beatrax-sync._tcp`
     * @param  float  $timeoutSeconds  How long to keep collecting answers for.
     * @return list<DiscoveredPeer>
     */
    public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
    {
        $errorCode = 0;
        $errorMessage = '';

        // Bound to an ephemeral port rather than 5353: this is a one-shot
        // query wanting unicast answers, and claiming the mDNS port would
        // collide with any resolver the OS already runs.
        $socket = @stream_socket_server(
            'udp://0.0.0.0:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND
        );

        if (! is_resource($socket)) {
            return [];
        }

        try {
            return $this->collect($socket, $serviceType, $timeoutSeconds);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     * @return list<DiscoveredPeer>
     */
    private function collect($socket, string $serviceType, float $timeoutSeconds): array
    {
        $table = new MdnsInstanceTable;

        $sent = @stream_socket_sendto(
            $socket,
            $this->encodeQuery($serviceType.'.'.self::SERVICE_DOMAIN),
            0,
            self::MULTICAST_ADDRESS.':'.self::MULTICAST_PORT
        );

        if ($sent === false || $sent < 1) {
            return [];
        }

        stream_set_blocking($socket, false);

        $deadline = microtime(true) + $timeoutSeconds;

        while (($remaining = $deadline - microtime(true)) > 0) {
            $datagram = $this->receive($socket, $remaining, $sender);

            if ($datagram !== null) {
                $this->parser->parse($datagram, $sender, $table);
            }
        }

        return $table->peers(DiscoveryMode::Mdns);
    }

    /**
     * @param  resource  $socket
     *
     * @param-out string $sender
     */
    private function receive($socket, float $remaining, ?string &$sender): ?string
    {
        $sender = '';
        $read = [$socket];
        $write = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, 0, (int) ($remaining * self::MICROSECONDS_PER_SECOND));
        if ($ready === false || $ready < 1) {
            return null;
        }

        $peer = '';
        $datagram = @stream_socket_recvfrom($socket, self::MAX_DATAGRAM_BYTES, 0, $peer);

        if (! is_string($datagram) || $datagram === '') {
            return null;
        }

        // $peer is an out-parameter, so it is only a string once the read
        // actually succeeded; a datagram we cannot attribute is discarded.
        $sender = is_string($peer) ? $this->addressOf($peer) : '';

        return $sender === '' ? null : $datagram;
    }

    // A question with no answers: header, then the name, type and class. The
    // id is zero because mDNS matches on the question rather than the id.
    private function encodeQuery(string $name): string
    {
        $question = '';
        foreach (explode('.', $name) as $label) {
            $length = strlen($label);

            // A DNS label is one length byte, so 63 is the protocol's ceiling
            // and anything longer cannot be encoded at all. Silently wrapping
            // it through chr() would emit a different name than was asked for.
            if ($length < 1 || $length > self::MAX_LABEL_BYTES) {
                return '';
            }

            $question .= chr($length).$label;
        }

        return pack('nnnnnn', 0, 0, 1, 0, 0, 0)
            .$question
            ."\0"
            .pack('nn', DnsRecordType::Pointer->value, self::CLASS_INTERNET | self::UNICAST_RESPONSE_BIT);
    }

    // stream_socket_recvfrom reports the peer as `address:port`, and an IPv6
    // address contains colons of its own, so only the last one separates them.
    private function addressOf(string $peer): string
    {
        $separator = strrpos($peer, ':');

        return $separator === false ? $peer : substr($peer, 0, $separator);
    }
}
