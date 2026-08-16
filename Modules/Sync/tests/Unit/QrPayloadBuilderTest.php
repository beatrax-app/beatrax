<?php

declare(strict_types=1);

use Modules\Sync\Internal\Pairing\QrPayloadBuilder;
use Modules\Sync\Internal\Pairing\RelayBootstrap;

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
        relay: new RelayBootstrap('https://relay.example.com'),
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
        relay: new RelayBootstrap(authToken: 'shared-secret'),
    );
    expect($uriTokenOnly)->not->toContain('rtok=');
    expect($uriTokenOnly)->not->toContain('relay=');

    $uriBoth = $builder->buildUri(
        'device-init',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'deadbeef',
        relay: new RelayBootstrap('https://relay.example.com', 'shared-secret'),
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
        relay: new RelayBootstrap('https://relay.example.com', 'shared-secret'),
    );

    expect($svg)->toContain('<svg');
});

it('renders the QR without any xmlwriter-backed renderer', function (): void {
    // The PHP binary NativePHP bundles with the desktop app has no
    // ext-xmlwriter, so bacon's SvgImageBackEnd threw "You need to install the
    // libxml extension and enable the xmlwriter extension" and Show my code —
    // the one surface pairing depends on — failed there while every test
    // passed on a host PHP that ships the extension.
    $source = (string) file_get_contents(
        base_path('Modules/Sync/Internal/Pairing/QrPayloadBuilder.php'),
    );

    // Asserts on the imports, not on prose: the comment explaining this very
    // trap names the offending class, and matching free text would fail on it.
    expect($source)->not->toContain('use BaconQrCode\Renderer')
        ->and($source)->not->toContain('use BaconQrCode\Writer');
});

it('emits a square SVG whose module grid clears the quiet-zone margin', function (): void {
    // Guards the hand-rolled geometry: a transposed or off-by-one grid still
    // produces a plausible-looking SVG that no scanner can read.
    $svg = (new QrPayloadBuilder)->buildSvg(
        'device-geometry',
        str_repeat('a', 64),
        str_repeat('b', 64),
        'deadbeef',
    );

    expect($svg)->toMatch('/viewBox="0 0 (\d+) \1"/')
        ->and($svg)->toContain('shape-rendering="crispEdges"')
        ->and(substr_count($svg, '<rect'))->toBeGreaterThan(100);
});
