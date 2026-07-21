<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Public\Services\PairingGateway;
use Native\Mobile\Facades\Scanner;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class QrScanBridge
{
    public function __construct(private readonly PairingGateway $gateway) {}

    // Safe to call unconditionally; never touches the native facade when
    // either guard fails.
    public function isAvailable(): bool
    {
        if (! class_exists(Scanner::class)) {
            return false;
        }

        return getenv('NATIVEPHP_PLATFORM') !== false;
    }

    // Returns null on any failure (malformed envelope OR invalid/expired
    // token) - the caller cannot and must not distinguish the two,
    // mirroring the word-code path's single generic outcome.
    /**
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null
     */
    public function accept(string $decodedPayload, int $userId, Session $session): ?array
    {
        $tokenHex = $this->extractToken($decodedPayload);
        if ($tokenHex === null) {
            return null;
        }

        return $this->gateway->acceptToken($tokenHex, $userId, $session);
    }

    // Standard parse_url()/parse_str() envelope unwrap only - no
    // inspection of the ed/kx/device fields, no trust decision; a
    // malformed/non-beatrax payload simply yields null (the generic
    // invalid-code outcome).
    private function extractToken(string $decodedPayload): ?string
    {
        $parts = parse_url($decodedPayload);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'beatrax' || ! isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);
        $token = $query['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    // Unwraps the full envelope to recover the initiator's public
    // identity alongside the token (used only by the mobile import-mode
    // branch, to seed a local pairing_tokens row on a fresh device),
    // plus the optional relay/rtok params for relay auto-configuration.
    /**
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, relayEndpoint: ?string, relayAuthToken: ?string}|null
     */
    public function extractIdentity(string $decodedPayload): ?array
    {
        $parts = parse_url($decodedPayload);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'beatrax' || ! isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        $token = $query['token'] ?? null;
        $deviceId = $query['device'] ?? null;
        $ed = $query['ed'] ?? null;
        $kx = $query['kx'] ?? null;

        if (! is_string($token) || $token === ''
            || ! is_string($deviceId) || $deviceId === ''
            || ! is_string($ed) || $ed === ''
            || ! is_string($kx) || $kx === ''
        ) {
            return null;
        }

        $relay = $query['relay'] ?? null;
        $relayEndpoint = is_string($relay) && $relay !== '' ? $relay : null;

        $rtok = $query['rtok'] ?? null;
        $relayAuthToken = $relayEndpoint !== null && is_string($rtok) && $rtok !== '' ? $rtok : null;

        return [
            'token' => $token,
            'deviceId' => $deviceId,
            'ed25519PubHex' => $ed,
            'x25519PubHex' => $kx,
            'relayEndpoint' => $relayEndpoint,
            'relayAuthToken' => $relayAuthToken,
        ];
    }
}
