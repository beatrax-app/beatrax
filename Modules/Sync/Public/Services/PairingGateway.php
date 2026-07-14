<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\PairingStateMachine;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use stdClass;

/**
 * Narrow Public seam letting a non-Sync module's OWN pairing-entry surface
 * drive the SAME `PairingTokenService::accept()`/`confirm()` trust boundary
 * `Modules\Sync\Internal\Http\Livewire\PairingFlowModal` already uses —
 * without reaching into `Modules\Sync\Internal\*` directly (CLAUDE.md
 * modular-architecture constraint; enforced by `App\PhpStan\Rules\
 * BoundaryRule`, which — unlike the native-facade `phpstan.neon` carve-outs
 * — has NO per-file ignore mechanism at all).
 *
 * Added for Phase 15 Plan 07 (MOBILE-01, D-01/D-02):
 * `Modules\Mobile\Internal\Pairing\QrScanBridge` and
 * `Modules\Mobile\Internal\Http\Livewire\MobilePairingScan` need the exact
 * decode-then-accept-then-derive-safety-words shape
 * `PairingFlowModal::submitCode()` implements, plus its `confirmMatch()`
 * both-confirm gate (15-PATTERNS.md §QrScanBridge/§MobilePairingScan —
 * "extend, don't rebuild"). This gateway is that one seam. Every method is
 * a thin pass-through or re-composition of the existing Internal
 * collaborators (`PairingTokenService`, `WordCodeEncoder`,
 * `DeviceIdentityLoader`, `SafetyNumberDeriver`). NO new trust decision is
 * introduced anywhere in this class: `PairingTokenService::accept()`/
 * `confirm()` remain the SOLE points where a device is admitted to
 * `device_registry` (D-07 both-confirm gate; T-15-17/T-15-18/T-15-19).
 */
final class PairingGateway
{
    /** Mirrors PairingStateMachine::AWAITING_CONFIRM (same-module re-export). */
    public const string STATE_AWAITING_CONFIRM = PairingStateMachine::AWAITING_CONFIRM;

    /** Mirrors PairingStateMachine::CONFIRMED (same-module re-export). */
    public const string STATE_CONFIRMED = PairingStateMachine::CONFIRMED;

    public function __construct(
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly PairingTokenService $tokenService,
        private readonly WordCodeEncoder $wordEncoder,
        private readonly SafetyNumberDeriver $safetyDeriver,
        private readonly DatabaseManager $db,
        private readonly DeviceIdentityService $identityService,
    ) {}

    /**
     * Enable this device's sync identity WITHOUT minting a GDK epoch —
     * identity only. Thin wrapper over
     * `DeviceIdentityService::generateAndPersist()`, added for the mobile
     * "Import from another device" fresh-device bootstrap (Phase 15
     * import-join, B2). The import path defers epoch acquisition entirely
     * to the desktop's delivered epochs (`GdkEpochControlHandler::handle()`
     * over the authenticated LAN session) — calling
     * `Modules\Core\Public\Services\EncryptionMigrationService::migrate()`
     * here (or anywhere on the import path before pairing confirms) would
     * self-mint a colliding local epoch 1 and permanently strand every
     * desktop epoch-1 entry in `gdk_decrypt_failed` quarantine
     * (`GdkEpochControlHandler`'s idempotency guard silently drops an
     * already-present epoch id). This method MUST NOT be extended to touch
     * the GDK keyring or `EncryptionMigrationService` — identity only.
     *
     * @throws \LogicException when the app-lock KEK is unavailable (D-02
     *                         weak-key-window guard — propagated from
     *                         `DeviceIdentityService::generateAndPersist()`).
     */
    public function enableSyncIdentityWithoutEpoch(int $userId, Session $session): void
    {
        $this->identityService->generateAndPersist($userId, $session);
    }

    /**
     * Accept a pairing token already in raw hex form — the QR path. The QR's
     * `token` query parameter (`Modules\Sync\Internal\Pairing\
     * QrPayloadBuilder::buildUri()`) IS the same raw hex
     * `WordCodeEncoder::decode()` returns for a typed word-code; no base32
     * round-trip applies here — the QR carries the token directly, whereas
     * the word-code base32-encodes it purely so a human can type it.
     *
     * Mirrors `PairingFlowModal::submitCode()`'s accept-then-derive-safety-
     * words shape exactly.
     *
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null null on invalid/expired token or a locked/unavailable local identity.
     */
    public function acceptToken(string $tokenHex, int $userId, Session $session): ?array
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return null;
        }

        $accepted = $this->tokenService->accept(
            $tokenHex,
            $userId,
            $identity->deviceId,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );

        if ($accepted === false || ! $accepted instanceof stdClass) {
            return null;
        }

        $pairingTokenId = (string) (is_numeric($accepted->id) ? (int) $accepted->id : 0);

        return [
            'pairingTokenId' => $pairingTokenId,
            'safetyWords' => $this->deriveSafetyWords($pairingTokenId, $userId),
        ];
    }

    /**
     * Accept a typed word-code (base32) — decodes via `WordCodeEncoder`
     * first, IDENTICAL to `PairingFlowModal::submitCode()`'s word-code path,
     * then delegates to the SAME `acceptToken()` trust boundary above. Used
     * by the mobile `enter_code` fallback step (D-02).
     *
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null null on invalid/expired code.
     */
    public function acceptWordCode(string $wordCode, int $userId, Session $session): ?array
    {
        try {
            $tokenHex = $this->wordEncoder->decode($wordCode);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->acceptToken($tokenHex, $userId, $session);
    }

    /**
     * Record this side's safety-number confirmation (D-07 trust gate) — thin
     * pass-through to `PairingTokenService::confirm()`, the SOLE gate
     * admitting a device to `device_registry`. Returns the resulting
     * pairing state (see `STATE_*` constants), or null when the caller owns
     * neither side of the token.
     */
    public function confirm(int $tokenId, int $userId, string $confirmingDeviceId): ?string
    {
        return $this->tokenService->confirm($tokenId, $userId, $confirmingDeviceId);
    }

    /**
     * Cancel/expire an in-flight token (mirrors
     * `PairingFlowModal::cancelPairing()`'s expire call).
     */
    public function expire(int $tokenId, int $userId): void
    {
        $this->tokenService->expire($tokenId, $userId);
    }

    /**
     * Load THIS device's own identity (device id only) — callers need the
     * confirming device's real id to call `confirm()` (CR-01: the confirming
     * side is derived from the caller's own device identity, never a
     * client-supplied value). Returns null when locked / sync never enabled
     * for this user.
     */
    public function currentDeviceId(int $userId, Session $session): ?string
    {
        return $this->identityLoader->load($userId, $session)?->deviceId;
    }

    /**
     * Read the current state of a pairing token (user-scoped) — lets a
     * poll-driven step machine detect a peer-side confirm without
     * re-deriving any trust logic locally. Mirrors
     * `PairingFlowModal::checkPairingState()`'s read shape.
     */
    public function tokenState(int $tokenId, int $userId): ?string
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['state']);

        return $row !== null && is_string($row->state) ? $row->state : null;
    }

    /**
     * Derive the shared 6-word safety-number from BOTH stored public keys of
     * the in-flight token (D-07/D-08) — identical shape to
     * `PairingFlowModal::deriveSafetyWords()`.
     *
     * @return list<string>
     */
    private function deriveSafetyWords(string $pairingTokenId, int $userId): array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', (int) $pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        if ($row === null) {
            return [];
        }

        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($initiatorEd === null || $responderEd === null) {
            return [];
        }

        try {
            return $this->safetyDeriver->deriveWords($initiatorEd, $responderEd);
        } catch (InvalidPublicKeyException) {
            return [];
        }
    }
}
