<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Carries the two cross-device pairing handshake frames ({@see PairingFrame})
 * over the zero-knowledge relay (Phase 15 HIGH-01, Task 2) — the transport
 * that makes the Phase 12 both-confirm ceremony reach a genuinely separate
 * physical device.
 *
 * This class NEVER decides trust; it dispatches drained frames verbatim to
 * {@see PairingTokenService}'s cross-device apply methods, which own every
 * gate (identity binding, Ed25519 signature verification, the local-confirm
 * precondition). The relay itself sees only routing metadata + opaque blobs
 * (ZK invariant, {@see RelayMailbox}).
 */
final class PairingRelayCourier
{
    public function __construct(
        private readonly RelayClient $relayClient,
        private readonly RelayConfig $relayConfig,
        private readonly DeviceKeySigner $signer,
        private readonly PairingTokenService $tokenService,
        private readonly DatabaseManager $db,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Deliver a `PAIR_RESPONDER_ACCEPT` frame (phone → desktop): $senderDid's
     * own responder identity, addressed to $recipientDesktopDid, so the
     * desktop's LOCAL row can bind it via
     * {@see PairingTokenService::applyResponderAccept()}.
     *
     * Best-effort at the transport level: a relay failure throws
     * `RuntimeException` (from {@see RelayClient::deliver()}) — the CALLER
     * (the pairing UI's confirm-step wiring) is responsible for catching it
     * and surfacing a non-blocking retry indicator, mirroring
     * `PairingFlowModal::fanOutToNewlyConfirmedDevice()`'s established
     * try/catch shape. This method never silently swallows a delivery
     * failure — the caller must know delivery did not happen.
     *
     * @throws RuntimeException when the relay is unconfigured or the
     *                          delivery request fails.
     */
    public function sendResponderAccept(
        string $senderDid,
        string $recipientDesktopDid,
        string $tokenHash,
        string $responderEd25519Hex,
        string $responderX25519Hex,
    ): void {
        $frame = PairingFrame::buildResponderAccept($tokenHash, $senderDid, $responderEd25519Hex, $responderX25519Hex);

        $this->relayClient->deliver($senderDid, $recipientDesktopDid, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    /**
     * Deliver a `PAIR_CONFIRM` frame: an Ed25519-signed statement that
     * $self has confirmed the safety-number match, addressed to $peerDid.
     * $self's OWN secret key signs the frame — the relay never holds it and
     * cannot forge this (T2/T3 in the threat model).
     *
     * Same best-effort-at-the-caller posture as
     * {@see self::sendResponderAccept()} — failures propagate.
     *
     * @throws RuntimeException when the relay is unconfigured or the
     *                          delivery request fails.
     */
    public function sendConfirm(DeviceIdentityDto $self, string $peerDid, string $tokenHash): void
    {
        $message = PairingFrame::confirmSigningMessage($tokenHash, $self->deviceId, $peerDid);
        $sigHex = $this->signer->sign($message, sodium_hex2bin($self->ed25519SecretKeyHex));

        $frame = PairingFrame::buildConfirm($tokenHash, $self->deviceId, $peerDid, $sigHex);

        $this->relayClient->deliver($self->deviceId, $peerDid, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    /**
     * Drain this device's own relay mailbox and dispatch every pending frame
     * to {@see PairingTokenService}'s apply methods. Never throws out of the
     * poll (LOW-02 posture) — every failure (relay unreachable, a single
     * malformed/erroring row) is caught, logged (ids/counts only — NEVER key
     * material or signatures), and skipped so one bad row can never abort an
     * otherwise-healthy poll tick.
     *
     * A row is deleted (`RelayClient::confirm()`) only when it was
     * TERMINALLY handled — successfully applied, or permanently invalid
     * (malformed JSON, unknown type, no matching local row, a bad
     * signature). A valid-but-not-yet-applicable `PAIR_CONFIRM` (the local
     * side has not confirmed yet — {@see PairingTokenService::applyPeerConfirm()}'s
     * `'deferred'` sentinel) is left pending so the NEXT poll re-drains it
     * once the local human confirms.
     */
    public function drainAndApply(int $userId): void
    {
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (! is_string($selfDeviceId) || $selfDeviceId === '') {
            // No local identity yet — nothing to drain for.
            return;
        }

        if (! $this->relayConfig->isConfigured()) {
            return;
        }

        $drainToken = $this->relayConfig->deriveDeviceToken($selfDeviceId);
        if ($drainToken === null) {
            return;
        }

        try {
            $rows = $this->relayClient->drain($selfDeviceId, $drainToken);
        } catch (Throwable $e) {
            $this->logger?->warning('PairingRelayCourier: drain failed.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);

            return;
        }

        foreach ($rows as $row) {
            try {
                $this->applyDrainedRow($userId, $drainToken, $row);
            } catch (Throwable $e) {
                $this->logger?->warning('PairingRelayCourier: failed to apply a drained pairing frame.', [
                    'user_id' => $userId,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyDrainedRow(int $userId, string $drainToken, array $row): void
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
        $blobB64 = isset($row['blob']) && is_string($row['blob']) ? $row['blob'] : null;

        if ($id === null || $blobB64 === null) {
            // No relay-row id to confirm/delete against — nothing safe to do.
            return;
        }

        $blob = base64_decode($blobB64, true);

        if ($blob === false) {
            // Permanently invalid — never becomes decodable on a later poll.
            $this->relayClient->confirm($id, $drainToken);

            return;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($blob, true);

        if (! is_array($decoded) || ! isset($decoded['type']) || ! is_string($decoded['type'])) {
            $this->relayClient->confirm($id, $drainToken);

            return;
        }

        /** @var array<string, mixed> $decoded */
        $terminal = match ($decoded['type']) {
            PairingFrame::TYPE_RESPONDER_ACCEPT => $this->applyResponderAcceptFrame($userId, $decoded),
            PairingFrame::TYPE_CONFIRM => $this->applyConfirmFrame($userId, $decoded),
            default => true, // unknown type — permanently invalid.
        };

        if ($terminal) {
            $this->relayClient->confirm($id, $drainToken);
        }
        // else: a valid-but-deferred PAIR_CONFIRM — leave the row pending
        // for the next poll to redeliver, once the local side confirms.
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function applyResponderAcceptFrame(int $userId, array $frame): bool
    {
        $tokenHash = isset($frame['token_hash']) && is_string($frame['token_hash']) ? $frame['token_hash'] : null;
        $responderDeviceId = isset($frame['responder_device_id']) && is_string($frame['responder_device_id']) ? $frame['responder_device_id'] : null;
        $responderEd = isset($frame['responder_ed25519_pub_hex']) && is_string($frame['responder_ed25519_pub_hex']) ? $frame['responder_ed25519_pub_hex'] : null;
        $responderKx = isset($frame['responder_x25519_pub_hex']) && is_string($frame['responder_x25519_pub_hex']) ? $frame['responder_x25519_pub_hex'] : null;

        if ($tokenHash === null || $responderDeviceId === null || $responderEd === null || $responderKx === null) {
            return true; // malformed — permanently invalid.
        }

        // LOW-03 (15-crossdevice-pairing-REVIEW.md): the device id arrives from
        // an untrusted drained frame. Reject anything that is not a UUIDv4
        // (DeviceIdentityService's format) before it is persisted or signed
        // over — bounds length + charset and excludes the '|' signing delimiter.
        if (! self::isValidDeviceId($responderDeviceId)) {
            return true; // malformed device id — permanently invalid.
        }

        // applyResponderAccept() never defers — it either binds (or
        // idempotently no-ops) or fails closed (false). Either outcome is
        // terminal for this frame.
        $this->tokenService->applyResponderAccept($userId, $tokenHash, $responderDeviceId, $responderEd, $responderKx);

        return true;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function applyConfirmFrame(int $userId, array $frame): bool
    {
        $tokenHash = isset($frame['token_hash']) && is_string($frame['token_hash']) ? $frame['token_hash'] : null;
        $confirmingDeviceId = isset($frame['confirming_device_id']) && is_string($frame['confirming_device_id']) ? $frame['confirming_device_id'] : null;
        $peerDeviceId = isset($frame['peer_device_id']) && is_string($frame['peer_device_id']) ? $frame['peer_device_id'] : null;
        $sigHex = isset($frame['sig_hex']) && is_string($frame['sig_hex']) ? $frame['sig_hex'] : null;

        if ($tokenHash === null || $confirmingDeviceId === null || $peerDeviceId === null || $sigHex === null) {
            return true; // malformed — permanently invalid.
        }

        // LOW-03: reject non-UUID device ids from the untrusted frame (see
        // applyResponderAcceptFrame).
        if (! self::isValidDeviceId($confirmingDeviceId) || ! self::isValidDeviceId($peerDeviceId)) {
            return true;
        }

        $result = $this->tokenService->applyPeerConfirm($userId, $tokenHash, $confirmingDeviceId, $peerDeviceId, $sigHex);

        // 'deferred' is the ONLY non-terminal outcome — everything else
        // (a real state string, or null for a permanent rejection) is
        // terminal for this exact frame.
        return $result !== 'deferred';
    }

    /**
     * A device id from an untrusted drained frame is only accepted when it is a
     * UUIDv4 — the exact shape `DeviceIdentityService` mints. This bounds length
     * + charset and structurally excludes the '|' signing-message delimiter
     * (LOW-01/LOW-03, 15-crossdevice-pairing-REVIEW.md).
     */
    private static function isValidDeviceId(string $deviceId): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $deviceId,
        ) === 1;
    }
}
