<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\PairingRelayCourier;
use Modules\Sync\Internal\Pairing\PairingStateMachine;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Pairing\WordCodeEncoder;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use stdClass;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class PairingGateway
{
    public const string STATE_AWAITING_CONFIRM = PairingStateMachine::AWAITING_CONFIRM;

    public const string STATE_CONFIRMED = PairingStateMachine::CONFIRMED;

    public function __construct(
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly PairingTokenService $tokenService,
        private readonly WordCodeEncoder $wordEncoder,
        private readonly SafetyNumberDeriver $safetyDeriver,
        private readonly DatabaseManager $db,
        private readonly DeviceIdentityService $identityService,
        private readonly GdkRotationService $rotationService,
        private readonly RelayConfig $relayConfig,
        private readonly PairingRelayCourier $relayCourier,
    ) {}

    // Auto-configure this device's relay endpoint (+ optional bearer token)
    // from a scanned QR's relay/rtok params — a fresh phone has no relay
    // configured otherwise. No-op when $endpoint is null. No new trust
    // decision: the human-verified safety words remain the sole trust anchor.
    public function configureRelayFromQr(?string $endpoint, ?string $authToken): void
    {
        if ($endpoint === null || $endpoint === '') {
            return;
        }

        // $endpoint arrives from an untrusted scanned QR. Never persist an
        // insecure endpoint, and only bootstrap a FRESH device — never
        // clobber an existing working relay. Only overwrite the token when
        // the QR actually carries one.
        if (! str_starts_with($endpoint, 'https://')) {
            return;
        }

        if ($this->relayConfig->endpointUrl() !== null) {
            return;
        }

        $this->relayConfig->setEndpointUrl($endpoint);

        if ($authToken !== null && $authToken !== '') {
            $this->relayConfig->setAuthToken($authToken);
        }
    }

    // Deliver this device's PAIR_RESPONDER_ACCEPT frame (phone -> desktop)
    // over the relay. Loads this device's own identity first — the
    // responder's OWN keys always come from the LOCAL identity, never wire
    // content. No-op (silent) when the local identity is locked/unavailable.
    /**
     * @throws \RuntimeException when the relay is unconfigured or the
     *                           delivery request fails — the caller must
     *                           catch and surface a non-blocking retry.
     */
    public function sendResponderAccept(int $userId, string $tokenHash, string $desktopDeviceId, Session $session): void
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return;
        }

        $this->relayCourier->sendResponderAccept(
            $identity->deviceId,
            $desktopDeviceId,
            $tokenHash,
            $identity->ed25519PublicKeyHex,
            $identity->x25519PublicKeyHex,
        );
    }

    // Deliver this device's Ed25519-signed PAIR_CONFIRM frame to
    // $peerDeviceId over the relay. Reads the token's token_hash from the
    // local row so the caller never reconstructs trust state itself. No-op
    // when the local identity is locked, or $tokenId resolves to no row.
    /**
     * @throws \RuntimeException see {@see self::sendResponderAccept()}.
     */
    public function sendConfirm(int $userId, int $tokenId, string $peerDeviceId, Session $session): void
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return;
        }

        $tokenHash = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->value('token_hash');

        if (! is_string($tokenHash) || $tokenHash === '') {
            return;
        }

        $this->relayCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);
    }

    // Drain this device's own relay mailbox and apply every pending pairing
    // frame. Callers invoke this at the TOP of their poll handler, before
    // re-reading local pairing state. Never throws out of the poll — every
    // failure inside the courier's drain loop is caught, logged, and skipped.
    public function drainPairingFrames(int $userId, Session $session): void
    {
        $this->relayCourier->drainAndApply($userId);
    }

    // Identity only — the import path defers epoch acquisition entirely to
    // the desktop's delivered epochs; self-minting here would permanently
    // strand desktop epoch-1 entries (see architecture doc). MUST NOT be
    // extended to touch the GDK keyring or EncryptionMigrationService.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function enableSyncIdentityWithoutEpoch(int $userId, Session $session): void
    {
        $this->identityService->generateAndPersist($userId, $session);
    }

    // Fan out every epoch in $userId's GDK keyring to $newDeviceRegistryId's
    // confirmed device over the relay. Callers MUST reach this only from
    // the CONFIRMED both-confirm transition — see architecture doc for the
    // full trust-gate ordering and channel-authentication precondition.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     */
    public function deliverAllEpochsToDevice(int $userId, int $newDeviceRegistryId, Session $session): void
    {
        $this->rotationService->fanOutAllEpochsToDevice($userId, $newDeviceRegistryId, $session);
    }

    // Seed a LOCAL pending pairing_tokens row from a scanned QR's initiator
    // identity — closes the gap where acceptToken() finds no local row on a
    // fresh device database. No new trust decision: the seeded row still
    // requires the full acceptToken() + both-confirm ceremony.
    public function seedResponderToken(
        string $tokenHex,
        string $initiatorDeviceId,
        string $initiatorEd25519Hex,
        string $initiatorX25519Hex,
        int $userId,
    ): void {
        $this->tokenService->seedFromInitiator($userId, $initiatorDeviceId, $initiatorEd25519Hex, $initiatorX25519Hex, $tokenHex);
    }

    // Accept a pairing token already in raw hex form — the QR path. The QR
    // carries the token directly (no base32 round-trip); the word-code path
    // base32-encodes the same raw hex purely so a human can type it.
    /**
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null
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

    // Accept a typed word-code (base32) — decodes then delegates to the
    // same acceptToken() trust boundary above. Used by the mobile
    // enter_code fallback step.
    /**
     * @return array{pairingTokenId: string, safetyWords: list<string>}|null
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

    // Record this side's safety-number confirmation — pass-through to the
    // sole gate admitting a device to device_registry. Null when the
    // caller owns neither side of the token.
    public function confirm(int $tokenId, int $userId, string $confirmingDeviceId): ?string
    {
        return $this->tokenService->confirm($tokenId, $userId, $confirmingDeviceId);
    }

    public function expire(int $tokenId, int $userId): void
    {
        $this->tokenService->expire($tokenId, $userId);
    }

    // Load THIS device's own identity (device id only) — the confirming
    // side must be derived from the caller's own identity, never a
    // client-supplied value. Null when locked / sync never enabled.
    public function currentDeviceId(int $userId, Session $session): ?string
    {
        return $this->identityLoader->load($userId, $session)?->deviceId;
    }

    // Read the current state of a pairing token — lets a poll-driven step
    // machine detect a peer-side confirm without re-deriving trust logic.
    public function tokenState(int $tokenId, int $userId): ?string
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['state']);

        return $row !== null && is_string($row->state) ? $row->state : null;
    }

    /**
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
