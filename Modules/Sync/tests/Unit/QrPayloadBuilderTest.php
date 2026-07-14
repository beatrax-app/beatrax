<?php

declare(strict_types=1);

use Modules\Sync\Internal\Pairing\QrPayloadBuilder;

/*
 * QrPayloadBuilderTest — PAIR-02 QR payload encoding.
 *
 * RED until Plan 03 ships QrPayloadBuilder. References the planned FQCN
 * Modules\Sync\Internal\Pairing\QrPayloadBuilder. Failure is "class not found".
 *
 * The builder must encode a `beatrax://pair?` URI carrying the token, ed and
 * kx params and render it as SVG (via the already-installed bacon/bacon-qr-code).
 */

it('builds an SVG QR encoding a beatrax://pair URI with token, ed and kx params', function (): void {
    $builder = new QrPayloadBuilder;

    $token = 'deadbeefdeadbeefdeadbeefdeadbeef';
    $edHex = str_repeat('a', 64);
    $kxHex = str_repeat('b', 64);

    $svg = $builder->buildSvg('device-init', $edHex, $kxHex, $token);

    // Output is an SVG document.
    expect($svg)->toContain('<svg');

    // The encoded URI carries the pairing scheme and all three params.
    $uri = $builder->buildUri('device-init', $edHex, $kxHex, $token);

    expect($uri)->toStartWith('beatrax://pair?');
    expect($uri)->toContain('token='.$token);
    expect($uri)->toContain('ed='.$edHex);
    expect($uri)->toContain('kx='.$kxHex);
});

/*
 * Phase 15 HIGH-01 (Task 1) — the QR now optionally carries the relay
 * endpoint (+ bearer token) so a fresh phone can bootstrap its own
 * RelayConfig before the cross-device confirm handshake needs a transport.
 * Omitting both params must produce the IDENTICAL URI as before this change
 * (no behavior change to existing callers).
 */

it('omits the relay/rtok params entirely when no relay is configured — no behavior change', function (): void {
    $builder = new QrPayloadBuilder;

    $uri = $builder->buildUri('device-init', str_repeat('a', 64), str_repeat('b', 64), 'deadbeef');

    expect($uri)->not->toContain('relay=');
    expect($uri)->not->toContain('rtok=');
});

it('appends &relay=<endpoint> when a relay endpoint is supplied', function (): void {
    $builder = new QrPayloadBuilder;

    $uri = $builder->buildUri(
        'device-init',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'deadbeef',
        relayEndpoint: 'https://relay.example.com',
    );

    expect($uri)->toContain('relay='.rawurlencode('https://relay.example.com'));
    expect($uri)->not->toContain('rtok=');
});

it('appends &rtok=<token> only when BOTH a relay endpoint AND an auth token are supplied', function (): void {
    $builder = new QrPayloadBuilder;

    // Token alone (no endpoint) must never leak the bearer token onto the wire.
    $uriTokenOnly = $builder->buildUri(
        'device-init',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'deadbeef',
        relayEndpoint: null,
        relayAuthToken: 'shared-secret',
    );
    expect($uriTokenOnly)->not->toContain('rtok=');
    expect($uriTokenOnly)->not->toContain('relay=');

    $uriBoth = $builder->buildUri(
        'device-init',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'deadbeef',
        relayEndpoint: 'https://relay.example.com',
        relayAuthToken: 'shared-secret',
    );
    expect($uriBoth)->toContain('relay='.rawurlencode('https://relay.example.com'));
    expect($uriBoth)->toContain('rtok='.rawurlencode('shared-secret'));
});

it('renders relay params into the SVG-encoded URI too', function (): void {
    $builder = new QrPayloadBuilder;

    $svg = $builder->buildSvg(
        'device-init',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'deadbeef',
        relayEndpoint: 'https://relay.example.com',
        relayAuthToken: 'shared-secret',
    );

    expect($svg)->toContain('<svg');
});
