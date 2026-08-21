<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Public\Services\PairingGateway;
use Native\Mobile\Scanner;

final class QrScanBridge
{
    // The pairing envelope is only ever carried in a QR code, so the
    // scanner is narrowed to that symbology rather than the plugin's
    // default barcode set.
    private const SCAN_FORMAT = 'qr';

    public function __construct(private readonly PairingGateway $gateway) {}

    // Safe to call unconditionally; never touches the native class when
    // either guard fails.
    public function isAvailable(): bool
    {
        if (! class_exists(Scanner::class)) {
            return false;
        }

        return UserDataPathService::isMobileRuntime();
    }

    // Presents the OS camera scanner. Returns false when the runtime cannot
    // open it, which is the caller's signal to fall through to the typed
    // word-code step rather than strand the user on a dead viewfinder. The
    // decode comes back asynchronously as the CodeScanned event, not here.
    public function open(string $prompt): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        // Re-checked (not just isAvailable()'s own guard) so PHPStan's
        // class_exists() narrowing applies within THIS method's scope -
        // the guard must sit in the same method as the call it guards.
        if (! class_exists(Scanner::class)) {
            return false;
        }

        Scanner::scan()
            ->prompt($prompt)
            ->formats([self::SCAN_FORMAT])
            ->scan();

        return true;
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
     * @return array{token: string, deviceId: string, ed25519PubHex: string, x25519PubHex: string, deviceName: ?string, relayEndpoint: ?string, relayAuthToken: ?string, relayPin: ?string}|null
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

        // Cosmetic only, exactly like the responder's name: it labels a row
        // and grants nothing, so a forged name is a wrong caption and never
        // a trust decision.
        $name = $query['name'] ?? null;
        $deviceName = is_string($name) && trim($name) !== '' ? trim($name) : null;

        $relay = $query['relay'] ?? null;
        $relayEndpoint = is_string($relay) && $relay !== '' ? $relay : null;

        $rtok = $query['rtok'] ?? null;
        $relayAuthToken = $relayEndpoint !== null && is_string($rtok) && $rtok !== '' ? $rtok : null;

        // The pinned relay key. Only meaningful alongside an endpoint, and
        // only trustworthy because the QR itself is out-of-band.
        $rpin = $query['rpin'] ?? null;
        $relayPin = $relayEndpoint !== null && is_string($rpin) && $rpin !== '' ? $rpin : null;

        return [
            'token' => $token,
            'deviceId' => $deviceId,
            'ed25519PubHex' => $ed,
            'x25519PubHex' => $kx,
            'deviceName' => $deviceName,
            'relayEndpoint' => $relayEndpoint,
            'relayAuthToken' => $relayAuthToken,
            'relayPin' => $relayPin,
        ];
    }
}
