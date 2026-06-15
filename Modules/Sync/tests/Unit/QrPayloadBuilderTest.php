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
