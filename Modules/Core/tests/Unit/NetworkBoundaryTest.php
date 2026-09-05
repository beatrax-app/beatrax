<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Core\Internal\Enums\NetworkBoundaryState;
use Modules\Core\Internal\Support\NetworkBoundary;

// The spec calls the self-hosted shape shipped and "reached over their own
// network", while the address gate refused every non-loopback request with no
// carve-out at all. Widening it is one recorded setting, and every assertion
// below is about what that setting may NOT be talked into meaning.

function networkBoundary(string $servedInterfaces, string $appUrl = 'https://beatrax.test'): NetworkBoundary
{
    return new NetworkBoundary(new Repository([
        'app' => ['url' => $appUrl],
        'selfhost' => ['served_interfaces' => $servedInterfaces],
    ]));
}

it('serves nothing past loopback when no interface is named', function (): void {
    $boundary = networkBoundary('');

    expect($boundary->servedInterfaces())->toBe([]);
    expect($boundary->isWidened())->toBeFalse();
    expect($boundary->state())->toBe(NetworkBoundaryState::Loopback);
    expect($boundary->serves('127.0.0.1'))->toBeTrue();
    expect($boundary->serves('::1'))->toBeTrue();
    expect($boundary->serves('192.168.1.50'))->toBeFalse();
});

it('serves the address it was given and no neighbour of it', function (): void {
    $boundary = networkBoundary('192.168.1.50');

    expect($boundary->servedInterfaces())->toBe(['192.168.1.50']);
    expect($boundary->isWidened())->toBeTrue();
    expect($boundary->state())->toBe(NetworkBoundaryState::Widened);
    expect($boundary->serves('192.168.1.50'))->toBeTrue();
    expect($boundary->serves('192.168.1.51'))->toBeFalse();
    expect($boundary->serves('192.168.1.5'))->toBeFalse();
});

// A dual-stack listener reports the interface in whichever family it accepted
// on, so an operator who wrote the IPv4 spelling must not be refused when the
// SAPI publishes the mapped one.
it('matches a v4-mapped server address against the v4 address written down', function (): void {
    $boundary = networkBoundary('192.168.1.50');

    expect($boundary->serves('::ffff:192.168.1.50'))->toBeTrue();
    expect($boundary->serves('::ffff:c0a8:132'))->toBeTrue();
    expect($boundary->serves('::ffff:192.168.1.51'))->toBeFalse();
});

it('serves a named IPv6 interface in any spelling of it', function (): void {
    $boundary = networkBoundary('fd00::5');

    expect($boundary->serves('fd00::5'))->toBeTrue();
    expect($boundary->serves('fd00:0:0:0:0:0:0:5'))->toBeTrue();
    expect($boundary->serves('fd00::6'))->toBeFalse();
});

it('refuses the IPv4 wildcard rather than reading it as every interface', function (): void {
    $boundary = networkBoundary('0.0.0.0');

    expect($boundary->servedInterfaces())->toBe([]);
    expect($boundary->refusedInterfaces())->toBe(['0.0.0.0']);
    expect($boundary->isWidened())->toBeFalse();
    expect($boundary->serves('192.168.1.50'))->toBeFalse();
    expect($boundary->serves('0.0.0.0'))->toBeFalse();
});

it('refuses the IPv6 wildcard and its v4-mapped spelling too', function (): void {
    $boundary = networkBoundary('::, ::ffff:0.0.0.0');

    expect($boundary->servedInterfaces())->toBe([]);
    expect($boundary->refusedInterfaces())->toBe(['::', '::ffff:0.0.0.0']);
    expect($boundary->isWidened())->toBeFalse();
});

// A hostname would put DNS between the operator's intent and the gate, and a
// range names more interfaces than were written down. Neither is resolved or
// expanded — they are reported back so the setting cannot look honoured.
it('refuses a hostname and a CIDR range, and names them back', function (): void {
    $boundary = networkBoundary('beatrax.example.com, 192.168.1.0/24');

    expect($boundary->servedInterfaces())->toBe([]);
    expect($boundary->refusedInterfaces())->toBe(['beatrax.example.com', '192.168.1.0/24']);
    expect($boundary->isWidened())->toBeFalse();
});

it('keeps the readable entries when one entry beside them is refused', function (): void {
    $boundary = networkBoundary(' 192.168.1.50 , 0.0.0.0 , 10.0.0.4 ');

    expect($boundary->servedInterfaces())->toBe(['192.168.1.50', '10.0.0.4']);
    expect($boundary->refusedInterfaces())->toBe(['0.0.0.0']);
    expect($boundary->serves('10.0.0.4'))->toBeTrue();
    expect($boundary->serves('192.168.1.50'))->toBeTrue();
});

it('does not read a loopback entry as widening anything', function (): void {
    $boundary = networkBoundary('127.0.0.1, ::1');

    expect($boundary->servedInterfaces())->toBe(['127.0.0.1', '::1']);
    expect($boundary->isWidened())->toBeFalse();
    expect($boundary->state())->toBe(NetworkBoundaryState::Loopback);
    expect($boundary->serves('192.168.1.50'))->toBeFalse();
});

it('lists one address once however many times it is written', function (): void {
    expect(networkBoundary('10.0.0.4, 10.0.0.4')->servedInterfaces())->toBe(['10.0.0.4']);
});

// Where the runtime publishes no bind address there is no interface to check,
// so the recorded host is the whole authority — and a recorded host of
// `localhost` would be satisfied by the Host header of any caller on the LAN.
it('authorises a remote request only under a recorded host past loopback', function (): void {
    $boundary = networkBoundary('192.168.1.50', 'https://beatrax.example.com');

    expect($boundary->remoteHostAuthority())->toBe('beatrax.example.com');
    expect($boundary->servesUnderRecordedHost('beatrax.example.com'))->toBeTrue();
    expect($boundary->servesUnderRecordedHost('BEATRAX.EXAMPLE.COM'))->toBeTrue();
    expect($boundary->servesUnderRecordedHost('localhost'))->toBeFalse();
    expect($boundary->servesUnderRecordedHost('127.0.0.1'))->toBeFalse();
    expect($boundary->servesUnderRecordedHost('evil.example'))->toBeFalse();
});

it('authorises no host while APP_URL still names loopback', function (): void {
    $boundary = networkBoundary('192.168.1.50', 'http://localhost:8000');

    expect($boundary->isWidened())->toBeTrue();
    expect($boundary->remoteHostAuthority())->toBeNull();
    expect($boundary->servesUnderRecordedHost('localhost'))->toBeFalse();
    expect($boundary->servesUnderRecordedHost('192.168.1.50'))->toBeFalse();
});

it('authorises no host at all while the boundary is closed', function (): void {
    $boundary = networkBoundary('', 'https://beatrax.example.com');

    expect($boundary->remoteHostAuthority())->toBe('beatrax.example.com');
    expect($boundary->servesUnderRecordedHost('beatrax.example.com'))->toBeFalse();
});

it('allow-lists the loopback names and the recorded host, in that order', function (): void {
    expect(networkBoundary('', 'https://beatrax.example.com')->allowedHosts())
        ->toBe(['localhost', '127.0.0.1', '::1', '[::1]', 'beatrax.example.com']);

    expect(networkBoundary('')->allowedHosts())
        ->toBe(['localhost', '127.0.0.1', '::1', '[::1]', 'beatrax.test']);
});

it('reads a configuration value that is not a string as no configuration', function (): void {
    $boundary = new NetworkBoundary(new Repository([
        'app' => ['url' => 'https://beatrax.test'],
        'selfhost' => ['served_interfaces' => ['192.168.1.50']],
    ]));

    expect($boundary->servedInterfaces())->toBe([]);
    expect($boundary->isWidened())->toBeFalse();
    expect($boundary->serves('192.168.1.50'))->toBeFalse();
});
