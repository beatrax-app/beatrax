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
 *
 * ## Cross-device relay bootstrap (Phase 15 HIGH-01, Task 1)
 *
 * A fresh phone scanning this QR has no relay configured — the cross-device
 * pairing handshake (`PairingRelayCourier`) has no transport until one is set.
 * `$relayEndpoint`/`$relayAuthToken` (both optional, default null) append
 * `&relay=<endpoint>` and, when a token is configured, `&rtok=<token>` to the
 * URI so the phone can auto-configure its own `RelayConfig`
 * (`PairingGateway::configureRelayFromQr()`) before the handshake needs a
 * transport. Omitting both params (the existing 4-arg call shape) produces the
 * IDENTICAL URI as before this change — no behavior change for any existing
 * caller (C1, "no behavior change to existing pairing").
 *
 * Security note (OQ-1, human-reviewed sign-off): the relay bearer token is a
 * transport-access secret for a zero-knowledge relay, not a data-encryption
 * key. It travels only inside the QR — the same out-of-band, physically
 * scanned channel already carrying the one-time pairing token — so this never
 * widens key exposure.
 */
final class QrPayloadBuilder
{
    private const int SIZE = 240;

    private const int MARGIN = 1;

    /**
     * Build the `beatrax://pair` URI carrying the public identity + one-time
     * secret. The token is the single-use secret the responder submits.
     *
     * @param  ?string  $relayEndpoint  Optional relay endpoint URL to bootstrap
     *                                  a fresh responder's `RelayConfig` from
     *                                  (Phase 15 HIGH-01, Task 1). Omitted
     *                                  (null) when no relay is configured on
     *                                  this device — the QR carries no `relay`
     *                                  param, matching every existing caller.
     * @param  ?string  $relayAuthToken  Optional relay bearer token, appended
     *                                   only when both this AND $relayEndpoint
     *                                   are non-null.
     */
    public function buildUri(
        string $deviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
        string $token,
        ?string $relayEndpoint = null,
        ?string $relayAuthToken = null,
    ): string {
        $uri = sprintf(
            'beatrax://pair?v=1&token=%s&ed=%s&kx=%s&device=%s',
            rawurlencode($token),
            rawurlencode($ed25519PubHex),
            rawurlencode($x25519PubHex),
            rawurlencode($deviceId),
        );

        if ($relayEndpoint !== null && $relayEndpoint !== '') {
            $uri .= '&relay='.rawurlencode($relayEndpoint);

            if ($relayAuthToken !== null && $relayAuthToken !== '') {
                $uri .= '&rtok='.rawurlencode($relayAuthToken);
            }
        }

        return $uri;
    }

    /**
     * Render the pairing URI as an inline SVG QR (240px, margin 1).
     *
     * @param  ?string  $relayEndpoint  See {@see self::buildUri()}.
     * @param  ?string  $relayAuthToken  See {@see self::buildUri()}.
     */
    public function buildSvg(
        string $deviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
        string $token,
        ?string $relayEndpoint = null,
        ?string $relayAuthToken = null,
    ): string {
        $uri = $this->buildUri($deviceId, $ed25519PubHex, $x25519PubHex, $token, $relayEndpoint, $relayAuthToken);

        $renderer = new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($uri);
    }
}
