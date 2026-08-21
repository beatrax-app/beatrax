<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// Reads an mDNS response into an instance table. Split from the socket so the
// wire format can be tested against crafted packets without a network, which
// is the only practical way to cover the hostile cases: every byte here comes
// from whoever answered on the LAN, and nothing about that is trustworthy.
final class MdnsResponseParser
{
    // Wire-format sizes, all RFC 1035 except the SRV preamble (RFC 2782):
    // the header is an id, flags and four section counts; a question is a name
    // then its type and class; a record adds a 32-bit TTL and an rdlength; and
    // an SRV opens its rdata with a priority, a weight and a port.
    private const int HEADER_BYTES = 12;

    private const int QUESTION_TAIL_BYTES = 4;

    private const int RECORD_PREAMBLE_BYTES = 10;

    private const int SRV_PREAMBLE_BYTES = 6;

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

        $this->readSections($datagram, $header, $sender, $table);
    }

    // The questions are walked only to get past them; the answer, authority
    // and additional sections all hold records, and a responder is free to put
    // the SRV in one and the TXT in another, so all three are read the same
    // way. A section that will not parse stops the walk where it stands.
    /**
     * @param  array{qd: int, an: int, ns: int, ar: int}  $header
     */
    private function readSections(string $datagram, array $header, string $sender, MdnsInstanceTable $table): void
    {
        $offset = self::HEADER_BYTES;

        for ($i = 0; $i < $header['qd']; $i++) {
            if (! $this->skipQuestion($datagram, $offset)) {
                return;
            }
        }

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
        $preamble = $this->readPreamble($datagram, $offset);

        if ($preamble === null) {
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

    // Leaves $offset at the start of the rdata, and answers null when the
    // record header is truncated or declares an rdlength that overruns the
    // datagram — the classic malformed-packet case, refused rather than
    // reading whatever happens to follow in memory.
    /**
     * @return array{type: int, class: int, ttlHigh: int, ttlLow: int, length: int}|null
     */
    private function readPreamble(string $datagram, int &$offset): ?array
    {
        if ($offset + self::RECORD_PREAMBLE_BYTES > strlen($datagram)) {
            return null;
        }

        /** @var array{type: int, class: int, ttlHigh: int, ttlLow: int, length: int}|false $preamble */
        $preamble = unpack(
            'ntype/nclass/nttlHigh/nttlLow/nlength',
            substr($datagram, $offset, self::RECORD_PREAMBLE_BYTES)
        );

        if ($preamble === false) {
            return null;
        }

        $offset += self::RECORD_PREAMBLE_BYTES;

        return $preamble['length'] >= 0 && $offset + $preamble['length'] <= strlen($datagram)
            ? $preamble
            : null;
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
            DnsRecordType::Pointer => $this->applyPointer($datagram, $rdataOffset, $sender, $table),
            DnsRecordType::Address, null => null,
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

    // An A record is deliberately NOT read for addressing. Its owner name is
    // attacker-chosen, so a record naming the instance and placed BEFORE the
    // SRV would win the first-wins slot and point the fetch at any address on
    // the internet. Only the host a datagram actually arrived from is used.

    // A PTR names an instance without saying where it is. Recording the
    // sender keeps that instance addressable when its SRV and TXT arrive in a
    // later datagram, which is how larger responses are split up.
    private function applyPointer(string $datagram, int $rdataOffset, string $sender, MdnsInstanceTable $table): void
    {
        $cursor = $rdataOffset;
        $table->recordAddress($this->readName($datagram, $cursor), $sender);
    }

    // TXT rdata is a sequence of length-prefixed strings, one per key=value
    // pair, so the wanted key is found by walking them rather than parsing.
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
                $target = $this->followPointer($datagram, $cursor, $shortestPointerSeen, $offset, $followed);

                if ($target === null) {
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

    // The offset a pointer at $cursor names, or null when the walk must stop:
    // its second byte is missing, or it does not point strictly backwards.
    // $offset lands past the FIRST well-formed pointer, since that is where
    // the name ended as it appeared here rather than where it continues.
    private function followPointer(
        string $datagram,
        int $cursor,
        int $shortestPointerSeen,
        int &$offset,
        bool &$followed,
    ): ?int {
        if ($cursor + 1 >= strlen($datagram)) {
            return null;
        }

        $target = ((ord($datagram[$cursor]) & self::POINTER_HIGH_BITS_MASK) << 8) | ord($datagram[$cursor + 1]);

        if (! $followed) {
            $offset = $cursor + self::POINTER_BYTES;
            $followed = true;
        }

        return $target < $shortestPointerSeen ? $target : null;
    }
}
