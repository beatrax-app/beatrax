<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// Reads an mDNS response into an instance table. Split from the socket so the
// wire format can be tested against crafted packets without a network, which
// is the only practical way to cover the hostile cases: every byte here comes
// from whoever answered on the LAN, and nothing about that is trustworthy.
/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class MdnsResponseParser
{
    // RFC 1035 §4.1.1: id, flags, then the four section counts, two bytes each.
    private const int HEADER_BYTES = 12;

    // A question is a name followed by its type and class.
    private const int QUESTION_TAIL_BYTES = 4;

    // Every record carries type, class, a 32-bit TTL and rdlength before rdata.
    private const int RECORD_PREAMBLE_BYTES = 10;

    // RFC 2782: an SRV's rdata opens with priority, weight and port.
    private const int SRV_PREAMBLE_BYTES = 6;

    private const int IPV4_BYTES = 4;

    // RFC 1035 §4.1.4 name compression: a length byte whose top two bits are
    // set is a pointer, and the low fourteen bits are the offset it points to.
    private const int LABEL_TYPE_MASK = 0xC0;

    private const int POINTER_MARKER = 0xC0;

    private const int POINTER_HIGH_BITS_MASK = 0x3F;

    private const int POINTER_BYTES = 2;

    private const int MAX_PORT = 65535;

    // RFC 1035 §2.3.4 caps a name at 255 bytes and a label at 63. Enforcing
    // both stops a crafted packet growing a name without bound.
    private const int MAX_NAME_BYTES = 255;

    private const int MAX_LABEL_BYTES = 63;

    // A responder that answered is one device. Anything past this is either
    // broken or trying to exhaust memory, and neither deserves the benefit of
    // the doubt.
    private const int MAX_RECORDS_PER_MESSAGE = 256;

    private const string TXT_DEVICE_ID_PREFIX = 'did=';

    // The device id is matched against the registry and rendered in the UI,
    // so it is held to the shape the advertiser is supposed to publish rather
    // than accepted as free text from the network.
    private const int MAX_DEVICE_ID_BYTES = 64;

    public function parse(string $datagram, string $sender, MdnsInstanceTable $table): void
    {
        if (strlen($datagram) < self::HEADER_BYTES) {
            return;
        }

        /** @var array{qd: int, an: int, ns: int, ar: int}|false $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($datagram, 0, self::HEADER_BYTES));
        if ($header === false) {
            return;
        }

        $offset = self::HEADER_BYTES;

        for ($i = 0; $i < $header['qd']; $i++) {
            if (! $this->skipQuestion($datagram, $offset)) {
                return;
            }
        }

        // Answers, authority and additional all hold records, and a responder
        // is free to put the SRV in one and the TXT in another, so all three
        // sections are read the same way.
        $records = min($header['an'] + $header['ns'] + $header['ar'], self::MAX_RECORDS_PER_MESSAGE);

        for ($i = 0; $i < $records; $i++) {
            if (! $this->readRecord($datagram, $offset, $sender, $table)) {
                return;
            }
        }
    }

    private function skipQuestion(string $datagram, int &$offset): bool
    {
        $this->readName($datagram, $offset);
        $offset += self::QUESTION_TAIL_BYTES;

        return $offset <= strlen($datagram);
    }

    // Returns false when the message is exhausted or malformed, which stops
    // the caller rather than letting it walk off the end of the buffer.
    private function readRecord(string $datagram, int &$offset, string $sender, MdnsInstanceTable $table): bool
    {
        $name = $this->readName($datagram, $offset);

        if ($offset + self::RECORD_PREAMBLE_BYTES > strlen($datagram)) {
            return false;
        }

        /** @var array{type: int, class: int, ttlHigh: int, ttlLow: int, length: int}|false $preamble */
        $preamble = unpack(
            'ntype/nclass/nttlHigh/nttlLow/nlength',
            substr($datagram, $offset, self::RECORD_PREAMBLE_BYTES)
        );

        if ($preamble === false) {
            return false;
        }

        $offset += self::RECORD_PREAMBLE_BYTES;

        // A declared rdlength that overruns the datagram is the classic
        // malformed-packet case; refuse the record rather than reading
        // whatever happens to follow in memory.
        if ($preamble['length'] < 0 || $offset + $preamble['length'] > strlen($datagram)) {
            return false;
        }

        $rdata = substr($datagram, $offset, $preamble['length']);
        $rdataOffset = $offset;
        $offset += $preamble['length'];

        $this->apply(
            DnsRecordType::tryFrom($preamble['type']),
            $name,
            $rdata,
            $datagram,
            $rdataOffset,
            $sender,
            $table
        );

        return true;
    }

    private function apply(
        ?DnsRecordType $type,
        string $name,
        string $rdata,
        string $datagram,
        int $rdataOffset,
        string $sender,
        MdnsInstanceTable $table
    ): void {
        if ($name === '') {
            return;
        }

        // A record type this module does not act on is not an error: a
        // responder is free to answer with more than was asked for.
        match ($type) {
            DnsRecordType::Service => $this->applyService($name, $rdata, $sender, $table),
            DnsRecordType::Text => $this->applyText($name, $rdata, $table),
            DnsRecordType::Address => $this->applyAddress($name, $rdata, $table),
            DnsRecordType::Pointer => $this->applyPointer($datagram, $rdataOffset, $sender, $table),
            null => null,
        };
    }

    private function applyService(string $name, string $rdata, string $sender, MdnsInstanceTable $table): void
    {
        if (strlen($rdata) < self::SRV_PREAMBLE_BYTES) {
            return;
        }

        /** @var array{priority: int, weight: int, port: int}|false $service */
        $service = unpack('npriority/nweight/nport', substr($rdata, 0, self::SRV_PREAMBLE_BYTES));

        if ($service === false || $service['port'] < 1 || $service['port'] > self::MAX_PORT) {
            return;
        }

        $table->recordPort($name, $service['port']);

        // The SRV target is a .local name and a phone has no resolver for
        // one, so the address kept is the host the datagram actually came
        // from — which is also the only one proven to be reachable.
        $table->recordAddress($name, $sender);
    }

    private function applyText(string $name, string $rdata, MdnsInstanceTable $table): void
    {
        $deviceId = $this->deviceIdFromText($rdata);

        if ($deviceId !== null) {
            $table->recordDeviceId($name, $deviceId);
        }
    }

    private function applyAddress(string $name, string $rdata, MdnsInstanceTable $table): void
    {
        if (strlen($rdata) !== self::IPV4_BYTES) {
            return;
        }

        $table->recordAddress($name, implode('.', array_map(ord(...), str_split($rdata))));
    }

    // A PTR names an instance without saying where it is. Recording the
    // sender keeps that instance addressable when its SRV and TXT arrive in a
    // later datagram, which is how larger responses are split up.
    private function applyPointer(string $datagram, int $rdataOffset, string $sender, MdnsInstanceTable $table): void
    {
        $cursor = $rdataOffset;
        $table->recordAddress($this->readName($datagram, $cursor), $sender);
    }

    // TXT rdata is a sequence of length-prefixed strings, one per key=value.
    private function deviceIdFromText(string $rdata): ?string
    {
        $cursor = 0;
        $length = strlen($rdata);

        while ($cursor < $length) {
            $size = ord($rdata[$cursor]);
            $cursor++;

            if ($size === 0 || $cursor + $size > $length) {
                return null;
            }

            $pair = substr($rdata, $cursor, $size);
            $cursor += $size;

            if (str_starts_with($pair, self::TXT_DEVICE_ID_PREFIX)) {
                return $this->acceptableDeviceId(substr($pair, strlen(self::TXT_DEVICE_ID_PREFIX)));
            }
        }

        return null;
    }

    // Anything on the network can publish a TXT record, so the value is held
    // to the advertiser's own format instead of being trusted as free text.
    private function acceptableDeviceId(string $candidate): ?string
    {
        if ($candidate === '' || strlen($candidate) > self::MAX_DEVICE_ID_BYTES) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9-]+$/', $candidate) === 1 ? $candidate : null;
    }

    // Reads a name, following compression pointers, and leaves $offset just
    // past the name as it appeared here. Pointers must point BACKWARDS: a
    // forward or self-referential pointer is the loop a hostile packet builds,
    // and refusing them ends the walk without needing a hop budget.
    private function readName(string $datagram, int &$offset): string
    {
        $labels = [];
        $nameBytes = 0;
        $cursor = $offset;
        $limit = strlen($datagram);
        $followed = false;
        $shortestPointerSeen = $limit;

        while ($cursor < $limit) {
            $length = ord($datagram[$cursor]);

            if ($length === 0) {
                $cursor++;

                break;
            }

            if (($length & self::LABEL_TYPE_MASK) === self::POINTER_MARKER) {
                if ($cursor + 1 >= $limit) {
                    break;
                }

                $target = (($length & self::POINTER_HIGH_BITS_MASK) << 8) | ord($datagram[$cursor + 1]);

                if (! $followed) {
                    $offset = $cursor + self::POINTER_BYTES;
                    $followed = true;
                }

                if ($target >= $shortestPointerSeen) {
                    break;
                }

                $shortestPointerSeen = $target;
                $cursor = $target;

                continue;
            }

            if ($length > self::MAX_LABEL_BYTES || $cursor + 1 + $length > $limit) {
                break;
            }

            $nameBytes += $length + 1;
            if ($nameBytes > self::MAX_NAME_BYTES) {
                break;
            }

            $cursor++;
            $labels[] = substr($datagram, $cursor, $length);
            $cursor += $length;
        }

        if (! $followed) {
            $offset = $cursor;
        }

        return implode('.', $labels);
    }
}
