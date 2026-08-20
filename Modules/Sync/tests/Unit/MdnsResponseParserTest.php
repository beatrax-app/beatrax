<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\MdnsInstanceTable;
use Modules\Sync\Internal\Transport\Discovery\MdnsResponseParser;

/*
 * Every byte the parser sees came from whoever answered a multicast question
 * on the local network, which is anyone attached to it. These cover the wire
 * format it is supposed to read and, at least as importantly, the packets a
 * hostile or broken responder can send: compression loops, lengths that
 * overrun the buffer, and TXT values that are not what they claim to be.
 */

const MDNS_TYPE_A = 1;
const MDNS_TYPE_PTR = 12;
const MDNS_TYPE_TXT = 16;
const MDNS_TYPE_SRV = 33;

const MDNS_INSTANCE = 'Beatrax-abc._beatrax-sync._tcp.local';
const MDNS_DEVICE_ID = 'c6df0124-4006-46e1-8166-415b1484e71c';
const MDNS_SENDER = '192.168.178.66';

function mdnsName(string $name): string
{
    $encoded = '';
    foreach (explode('.', $name) as $label) {
        $encoded .= chr(strlen($label)).$label;
    }

    return $encoded."\0";
}

function mdnsRecord(string $name, int $type, string $rdata, ?int $declaredLength = null): string
{
    return mdnsName($name)
        .pack('nnNn', $type, 1, 120, $declaredLength ?? strlen($rdata))
        .$rdata;
}

function mdnsSrv(int $port, string $target = 'desktop.local'): string
{
    return pack('nnn', 0, 0, $port).mdnsName($target);
}

function mdnsTxt(string ...$pairs): string
{
    $rdata = '';
    foreach ($pairs as $pair) {
        $rdata .= chr(strlen($pair)).$pair;
    }

    return $rdata;
}

function mdnsMessage(string $records, int $answerCount): string
{
    return pack('nnnnnn', 0, 0x8400, 0, $answerCount, 0, 0).$records;
}

function mdnsParse(string $datagram, string $sender = MDNS_SENDER): array
{
    $table = new MdnsInstanceTable;
    (new MdnsResponseParser)->parse($datagram, $sender, $table);

    return $table->peers(DiscoveryMode::Mdns);
}

function mdnsFullAnswer(int $port = 51337, string $deviceId = MDNS_DEVICE_ID): string
{
    return mdnsMessage(
        mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv($port))
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_TXT, mdnsTxt('did='.$deviceId)),
        2
    );
}

it('reads a service and its device id into one addressable peer', function (): void {
    $peers = mdnsParse(mdnsFullAnswer());

    expect($peers)->toHaveCount(1);
    expect($peers[0]->deviceId)->toBe(MDNS_DEVICE_ID);
    expect($peers[0]->port)->toBe(51337);
    expect($peers[0]->isConnectable())->toBeTrue();
});

it('addresses the peer by the host the datagram came from, not the SRV target', function (): void {
    // The SRV target is a .local name and a phone has no resolver for one, so
    // an answer naming an unreachable host must still yield a usable address.
    $peers = mdnsParse(mdnsFullAnswer());

    expect($peers[0]->host)->toBe(MDNS_SENDER);
});

it('keeps the sending host even when a later A record claims another address', function (): void {
    $datagram = mdnsMessage(
        mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv(51337))
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_TXT, mdnsTxt('did='.MDNS_DEVICE_ID))
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_A, "\x0a\x00\x00\x01"),
        3
    );

    // 10.0.0.1 never sent anything. Only the address a packet actually
    // arrived from is known to be reachable, so it is the one that stands.
    expect(mdnsParse($datagram)[0]->host)->toBe(MDNS_SENDER);
});

it('drops an instance that announced no device id', function (): void {
    $datagram = mdnsMessage(mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv(51337)), 1);

    expect(mdnsParse($datagram))->toBe([]);
});

it('drops an instance that announced no port', function (): void {
    $datagram = mdnsMessage(
        mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_TXT, mdnsTxt('did='.MDNS_DEVICE_ID)),
        1
    );

    expect(mdnsParse($datagram))->toBe([]);
});

it('reads the device id past other TXT pairs', function (): void {
    $datagram = mdnsMessage(
        mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv(51337))
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_TXT, mdnsTxt('v=1', 'name=Mac', 'did='.MDNS_DEVICE_ID)),
        2
    );

    expect(mdnsParse($datagram)[0]->deviceId)->toBe(MDNS_DEVICE_ID);
});

it('refuses a device id that is not the shape an advertiser publishes', function (string $hostile): void {
    $datagram = mdnsMessage(
        mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv(51337))
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_TXT, mdnsTxt('did='.$hostile)),
        2
    );

    expect(mdnsParse($datagram))->toBe([]);
})->with([
    'path traversal' => '../../etc/passwd',
    'markup' => '<script>alert(1)</script>',
    'sql quote' => "abc' or '1'='1",
    'newline injection' => "abc\ndid=other",
    'null byte' => "abc\0def",
    'space' => 'abc def',
    'overlong' => 'a-very-long-value-that-keeps-going-and-going-well-past-any-real-device-identifier',
]);

it('refuses a port outside the addressable range', function (int $port): void {
    expect(mdnsParse(mdnsFullAnswer($port)))->toBe([]);
})->with(['zero' => 0]);

it('accepts the highest addressable port', function (): void {
    expect(mdnsParse(mdnsFullAnswer(65535))[0]->port)->toBe(65535);
});

it('survives a datagram whose declared rdata length overruns the buffer', function (): void {
    // The classic malformed packet: a length field promising far more than
    // the datagram holds, inviting a read past the end of the buffer.
    $datagram = mdnsMessage(
        mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv(51337), declaredLength: 60000),
        1
    );

    expect(mdnsParse($datagram))->toBe([]);
});

it('survives a truncated or empty datagram', function (string $datagram): void {
    expect(mdnsParse($datagram))->toBe([]);
})->with([
    'empty' => '',
    'one byte' => "\x00",
    'header cut short' => "\x00\x00\x84\x00\x00\x00\x00\x01",
    'header only' => "\x00\x00\x84\x00\x00\x00\x00\x01\x00\x00\x00\x00",
    'random bytes' => "\xff\xfe\xfd\xfc\xfb\xfa\xf9\xf8\xf7\xf6\xf5\xf4\xf3\xf2\xf1",
]);

it('terminates on a name whose compression pointer points at itself', function (): void {
    // A pointer to its own offset is an infinite loop for any reader that
    // simply follows pointers, and it costs one crafted packet to send.
    $header = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0);
    $datagram = $header."\xc0\x0c".pack('nnNn', MDNS_TYPE_SRV, 1, 120, 6).mdnsSrv(51337);

    $timer = microtime(true);
    $peers = mdnsParse($datagram);

    expect(microtime(true) - $timer)->toBeLessThan(1.0, 'a self-referential pointer stalled the parser');
    expect($peers)->toBe([]);
});

it('terminates on a name whose compression pointers form a cycle', function (): void {
    // Two pointers aimed at each other. Refusing any target that is not
    // strictly earlier than the last one ends the walk without a hop budget.
    $header = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0);
    $body = "\xc0\x0e"."\xc0\x0c";
    $datagram = $header.$body.pack('nnNn', MDNS_TYPE_SRV, 1, 120, 6).mdnsSrv(51337);

    $timer = microtime(true);
    $peers = mdnsParse($datagram);

    expect(microtime(true) - $timer)->toBeLessThan(1.0, 'a pointer cycle stalled the parser');
    expect($peers)->toBe([]);
});

it('terminates on a forward compression pointer', function (): void {
    // Forward pointers are not legal compression and are how a packet builds
    // a loop that a backwards-only reader cannot be led into.
    $header = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0);
    $datagram = $header."\xc0\xff".pack('nnNn', MDNS_TYPE_SRV, 1, 120, 6).mdnsSrv(51337);

    $timer = microtime(true);
    mdnsParse($datagram);

    expect(microtime(true) - $timer)->toBeLessThan(1.0, 'a forward pointer stalled the parser');
});

it('does not read more records than the datagram can hold', function (): void {
    // The counts are attacker-controlled: this one claims 65535 answers in a
    // datagram holding one, so the reader must stop at the data, not the count.
    $datagram = pack('nnnnnn', 0, 0x8400, 0, 65535, 0, 0)
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_SRV, mdnsSrv(51337))
        .mdnsRecord(MDNS_INSTANCE, MDNS_TYPE_TXT, mdnsTxt('did='.MDNS_DEVICE_ID));

    $timer = microtime(true);
    $peers = mdnsParse($datagram);

    expect(microtime(true) - $timer)->toBeLessThan(1.0, 'an inflated answer count stalled the parser');
    expect($peers)->toHaveCount(1);
});

it('refuses a label longer than the format allows', function (): void {
    $header = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0);
    $datagram = $header.chr(200).str_repeat('a', 8).pack('nnNn', MDNS_TYPE_SRV, 1, 120, 6).mdnsSrv(51337);

    expect(mdnsParse($datagram))->toBe([]);
});

it('ignores an A record that is not four bytes', function (): void {
    $datagram = mdnsMessage(
        mdnsRecord('other._beatrax-sync._tcp.local', MDNS_TYPE_A, "\x0a\x00"),
        1
    );

    expect(mdnsParse($datagram))->toBe([]);
});

it('carries no key material, so a spoofed answer cannot authenticate anything', function (): void {
    // Anyone on the network can answer, and nothing about an answer is
    // trustworthy. Discovery may only ever produce a candidate address; the
    // Noise handshake and the safety number are what establish who a peer is.
    $peer = mdnsParse(mdnsFullAnswer())[0];

    $exposed = array_keys(get_object_vars($peer));
    sort($exposed);

    expect($exposed)->toBe(['deviceId', 'discoveryMode', 'host', 'port']);
});
