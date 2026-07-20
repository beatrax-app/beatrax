<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class QrPayloadBuilder
{
    private const int SIZE = 240;

    private const int MARGIN = 1;

    /**
     * @param  ?string  $relayEndpoint  Optional relay endpoint URL to bootstrap
     *                                  a fresh responder's `RelayConfig` from.
     *                                  Omitted (null) when no relay is
     *                                  configured on this device — the QR
     *                                  carries no `relay` param.
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
