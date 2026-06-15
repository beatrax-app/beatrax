<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Builds the pairing-channel payload (D-06): a `beatrax://pair` URI carrying the
 * issuing device's public identity (device id + Ed25519 + X25519 public keys)
 * plus the single-use one-time secret (token), and renders it as an inline SVG
 * QR for on-screen display.
 *
 * Server-side SSR only — no JS QR library, no file write. The SVG is returned as
 * a string for direct Blade embedding. The typed word-code (WordCodeEncoder) is
 * the equal first-class fallback when the QR cannot be scanned (D-05).
 */
final class QrPayloadBuilder
{
    private const int SIZE = 240;

    private const int MARGIN = 1;

    /**
     * Build the `beatrax://pair` URI carrying the public identity + one-time
     * secret. The token is the single-use secret the responder submits.
     */
    public function buildUri(
        string $deviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
        string $token,
    ): string {
        return sprintf(
            'beatrax://pair?v=1&token=%s&ed=%s&kx=%s&device=%s',
            rawurlencode($token),
            rawurlencode($ed25519PubHex),
            rawurlencode($x25519PubHex),
            rawurlencode($deviceId),
        );
    }

    /**
     * Render the pairing URI as an inline SVG QR (240px, margin 1).
     */
    public function buildSvg(
        string $deviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
        string $token,
    ): string {
        $uri = $this->buildUri($deviceId, $ed25519PubHex, $x25519PubHex, $token);

        $renderer = new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($uri);
    }
}
