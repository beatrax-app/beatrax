<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

final class QrPayloadBuilder
{
    private const int SIZE = 240;

    private const int MARGIN = 1;

    /**
     * @param  ?RelayBootstrap  $relay  What this device can tell a fresh
     *                                  responder about reaching a relay.
     *                                  Omitted when none is configured here —
     *                                  the QR then carries no relay params.
     */
    public function buildUri(
        string $deviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
        string $token,
        ?string $deviceName = null,
        ?RelayBootstrap $relay = null,
    ): string {
        $uri = sprintf(
            'beatrax://pair?v=1&token=%s&ed=%s&kx=%s&device=%s',
            rawurlencode($token),
            rawurlencode($ed25519PubHex),
            rawurlencode($x25519PubHex),
            rawurlencode($deviceId),
        );

        // The initiator's own name, so the responder can label this peer
        // with what it calls itself instead of a placeholder — the mirror of
        // the name the responder already sends on its accept frame.
        if ($deviceName !== null && $deviceName !== '') {
            $uri .= '&name='.rawurlencode($deviceName);
        }

        if ($relay !== null && $relay->isAdvertisable()) {
            $uri .= '&relay='.rawurlencode((string) $relay->endpoint);

            if ($relay->authToken !== null && $relay->authToken !== '') {
                $uri .= '&rtok='.rawurlencode($relay->authToken);
            }

            // The pin is what makes a self-signed relay certificate
            // trustworthy: the QR is an out-of-band channel the network
            // cannot touch, so the key it names is the only one accepted.
            if ($relay->pin !== null && $relay->pin !== '') {
                $uri .= '&rpin='.rawurlencode($relay->pin);
            }
        }

        return $uri;
    }

    /**
     * @param  ?RelayBootstrap  $relay  See {@see self::buildUri()}.
     */
    public function buildSvg(
        string $deviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
        string $token,
        ?string $deviceName = null,
        ?RelayBootstrap $relay = null,
    ): string {
        $uri = $this->buildUri($deviceId, $ed25519PubHex, $x25519PubHex, $token, $deviceName, $relay);

        return $this->renderSvg($uri);
    }

    // Hand-rolls the SVG because bacon's SVG back end drives ext-xmlwriter,
    // absent from the PHP binary NativePHP bundles with the desktop app.
    // Rendering there threw and Show my code failed on the one surface
    // pairing depends on, while every test passed on a host PHP that has it.
    private function renderSvg(string $uri): string
    {
        // ErrorCorrectionLevel::L() matches bacon's own Writer default, so the
        // symbol is identical to the one this method replaces.
        $matrix = Encoder::encode($uri, ErrorCorrectionLevel::L(), Encoder::DEFAULT_BYTE_MODE_ECODING)
            ->getMatrix();

        $modules = $matrix->getWidth();
        $extent = $modules + (self::MARGIN * 2);

        $rects = '';

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $rects .= sprintf(
                        '<rect x="%d" y="%d" width="1" height="1"/>',
                        $x + self::MARGIN,
                        $y + self::MARGIN,
                    );
                }
            }
        }

        // The viewBox is in module units so the modules stay on exact integer
        // boundaries; crispEdges then keeps the scanner-visible contrast sharp
        // at any rendered size.
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
            .'shape-rendering="crispEdges"><rect width="%d" height="%d" fill="#ffffff"/>'
            .'<g fill="#000000">%s</g></svg>',
            self::SIZE,
            self::SIZE,
            $extent,
            $extent,
            $extent,
            $extent,
            $rects,
        );
    }
}
