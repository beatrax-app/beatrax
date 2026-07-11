<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Public\Services\PairingGateway;
use Native\Mobile\Facades\Scanner;

/**
 * Bridges the `nativephp/mobile-scanner` decoded-QR content into the
 * EXISTING Phase 12 pairing trust boundary (D-01/D-02, 15-PATTERNS.md
 * §QrScanBridge).
 *
 * This class is the ONLY place in the codebase that calls
 * `Native\Mobile\Facades\Scanner`. Its job is narrow and mechanical:
 *
 *   1. extractToken() unwraps the `beatrax://pair?v=1&token=<hex>&ed=...
 *      &kx=...&device=...` envelope (`Modules\Sync\Internal\Pairing\
 *      QrPayloadBuilder::buildUri()`) to recover the SAME raw hex token
 *      `WordCodeEncoder::decode()` recovers from a typed word-code — this
 *      is the QR-format ANALOG of that decode step, not a new trust
 *      decision (15-RESEARCH.md V5: "the mobile scan bridge must not
 *      parse/trust the QR payload itself, only hand the raw decoded
 *      string to the existing service"). Only the `token` parameter is
 *      read; the `ed`/`kx`/`device` fields the QR also carries are
 *      IGNORED here exactly as `PairingFlowModal::submitCode()` ignores
 *      them today for the word-code path — the responder's own identity
 *      always comes from THIS device's local `DeviceIdentityLoader`
 *      (reached only via `PairingGateway`), never from QR content
 *      (T-15-18: no bespoke trust logic in the bridge).
 *   2. accept() hands the extracted token to `PairingGateway::acceptToken()`
 *      — the narrow Public seam wrapping the SAME `WordCodeEncoder`/
 *      `PairingTokenService::accept()` validation the word-code fallback
 *      step uses (T-15-17: the QR carries only the same one-time token a
 *      word-code would; it grants no extra trust). `Modules\Sync\Internal\*`
 *      is off-limits to this module directly — `App\PhpStan\Rules\
 *      BoundaryRule` has no per-file ignore mechanism, so `PairingGateway`
 *      (added alongside this plan, mirrors `MobileLockGateway`'s Plan 06
 *      precedent) is the one seam.
 *
 * An invalid/malformed/non-`beatrax://pair` payload, or an expired/unknown
 * token, surfaces the SAME null "invalid or expired" outcome as a bad
 * word-code — the caller (`MobilePairingScan`) renders the identical flash
 * message for both paths.
 *
 * Guarded on `class_exists(Scanner::class)` so non-mobile contexts (repo-
 * root tests, plain web, CI) never fatal — `nativephp/mobile-scanner` is
 * installed ONLY under `mobile-app/vendor` (Plan 03), unreachable from this
 * toolchain. `phpstan.neon` already allow-lists
 * `Native\Mobile\Facades\(Network|Biometrics|Scanner)` for
 * `Modules/Mobile/Internal/Pairing/*.php` (Plan 01).
 */
final class QrScanBridge
{
    public function __construct(private readonly PairingGateway $gateway) {}

    /**
     * Returns true only when the mobile-scanner facade is resolvable AND the
     * on-device mobile runtime signal is present (15-SPIKE-FINDINGS.md Spike
     * B) — mirrors `BiometricUnlockBridge::isAvailable()`'s identical guard
     * shape. Safe to call unconditionally; never touches the native facade
     * when either guard fails.
     */
    public function isAvailable(): bool
    {
        if (! class_exists(Scanner::class)) {
            return false;
        }

        return getenv('NATIVEPHP_PLATFORM') !== false;
    }

    /**
     * Hand a raw decoded QR string (the scanner-event envelope's content)
     * into the existing pairing validation boundary. Returns the SAME shape
     * `PairingGateway::acceptToken()` returns; null on ANY failure
     * (malformed envelope OR invalid/expired token) — the caller cannot and
     * must not distinguish the two, mirroring the word-code path's single
     * generic "invalid or expired" outcome (no oracle for a QR-vs-token
     * distinction is exposed to the UI).
     *
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

    /**
     * Unwrap the `beatrax://pair?...&token=<hex>&...` envelope to recover
     * the raw hex token — the QR-format counterpart of
     * `Modules\Sync\Internal\Pairing\WordCodeEncoder::decode()` (which
     * recovers the same raw token from its base32 word-code encoding).
     * Standard `parse_url()`/`parse_str()` envelope unwrap only — no
     * inspection of the `ed`/`kx`/`device` fields, no trust decision; a
     * malformed/non-`beatrax` payload simply yields null (the generic
     * invalid-code outcome), exactly as a non-base32 word-code does today.
     */
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
}
