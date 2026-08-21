<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final class PairingRelayCourier
{
    // Mirrors GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP — that class belongs
    // to the crypto transport, so this is the wire string, not a reference.
    private const FOREIGN_FRAME_TYPES = 'GDK_EPOCH_WRAP';

    public function __construct(
        private readonly RelayClient $relayClient,
        private readonly RelayConfig $relayConfig,
        private readonly DeviceKeySigner $signer,
        private readonly PairingTokenService $tokenService,
        private readonly DatabaseManager $db,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // Delivers a PAIR_RESPONDER_ACCEPT frame (phone -> desktop) so the
    // desktop's LOCAL row can bind it. A relay failure throws
    // RuntimeException — the CALLER is responsible for catching it and
    // surfacing a non-blocking retry; this never silently swallows a failure.
    /**
     * @throws RuntimeException when the relay is unconfigured or the
     *                          delivery request fails.
     */
    public function sendResponderAccept(
        string $senderDid,
        string $recipientDesktopDid,
        string $tokenHash,
        string $responderEd25519Hex,
        string $responderX25519Hex,
        string $responderName = '',
    ): void {
        $frame = PairingFrame::buildResponderAccept(
            $tokenHash,
            $senderDid,
            $responderEd25519Hex,
            $responderX25519Hex,
            $responderName,
        );

        $this->relayClient->deliver($senderDid, $recipientDesktopDid, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    // $self's OWN secret key signs the frame — the relay never holds it and
    // cannot forge this. Same best-effort-at-the-caller posture as
    // sendResponderAccept() — failures propagate.
    /**
     * @throws RuntimeException when the relay is unconfigured or the
     *                          delivery request fails.
     */
    public function sendConfirm(DeviceIdentityDto $self, string $peerDid, string $tokenHash): void
    {
        // Commit to this device's own sealing key and the peer's sealing key
        // as this device holds it, so a relay that swapped the peer's X25519
        // makes the peer reconstruct a different message than this signature
        // covers — failing the peer's verify.
        $message = PairingFrame::confirmSigningMessage(
            $tokenHash,
            $self->deviceId,
            $peerDid,
            $self->x25519PublicKeyHex,
            $this->peerX25519FromRow($self->userId, $tokenHash, $peerDid),
        );
        $sigHex = $this->signer->sign($message, sodium_hex2bin($self->ed25519SecretKeyHex));

        $frame = PairingFrame::buildConfirm($tokenHash, $self->deviceId, $peerDid, $sigHex);

        $this->relayClient->deliver($self->deviceId, $peerDid, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    // The peer's bound X25519 (sealing) key from THIS device's own pairing
    // row, keyed off which side the peer device id occupies. Empty string when
    // absent: the signature then commits to '', which the peer verifier cannot
    // match against a real key — fail closed, never a silently-missing key.
    private function peerX25519FromRow(int $userId, string $tokenHash, string $peerDid): string
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->first([
                'initiator_device_id',
                'initiator_x25519_pub_hex',
                'responder_device_id',
                'responder_x25519_pub_hex',
            ]);

        if ($row === null) {
            return '';
        }

        return $this->sideX25519($row->initiator_device_id, $row->initiator_x25519_pub_hex, $peerDid)
            ?? $this->sideX25519($row->responder_device_id, $row->responder_x25519_pub_hex, $peerDid)
            ?? '';
    }

    // The sealing key bound to ONE side of the row, or null when that side is
    // not the peer being asked about. Null and '' differ here: '' means this
    // side IS the peer but holds no key, which must stop the search rather
    // than fall through and answer with the other side's key.
    private function sideX25519(mixed $sideDeviceId, mixed $sideX25519Hex, string $peerDid): ?string
    {
        if (! is_string($sideDeviceId) || ! hash_equals($sideDeviceId, $peerDid)) {
            return null;
        }

        return is_string($sideX25519Hex) ? $sideX25519Hex : '';
    }

    // Drains this device's own relay mailbox and dispatches every pending
    // frame. Never throws out of the poll — every failure is caught, logged,
    // and skipped. A row is deleted only when TERMINALLY handled (see @link).
    public function drainAndApply(int $userId): void
    {
        $credentials = $this->resolveDrainCredentials($userId);
        if ($credentials === null) {
            return;
        }

        $drainToken = $credentials['token'];
        $rows = $this->drainRows($userId, $credentials['deviceId'], $drainToken);
        if ($rows === null) {
            return;
        }

        foreach ($rows as $row) {
            try {
                $this->applyDrainedRow($userId, $drainToken, $row);
            } catch (Throwable $e) {
                $this->logger?->warning('PairingRelayCourier: failed to apply a drained pairing frame.', [
                    'user_id' => $userId,
                    'exception' => $e::class,
                    ...SafeExceptionContext::describe($e),
                ]);
            }
        }
    }

    // No self device, an unconfigured relay, and a missing drain token all
    // mean "nothing to poll" — collapsed to a single null so the caller bails
    // silently without three separate guard returns.
    /**
     * @return array{deviceId: string, token: string}|null
     */
    private function resolveDrainCredentials(int $userId): ?array
    {
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (! is_string($selfDeviceId) || $selfDeviceId === '' || ! $this->relayConfig->isConfigured()) {
            return null;
        }

        // This device presents its OWN per-device drain secret (TOFU-verified
        // by the relay), not a token every relay peer could recompute. Minting
        // it can only fail on a secrets-file I/O error — treated as "nothing to
        // poll" so a transient write failure never throws out of the poll.
        try {
            $drainToken = $this->relayConfig->deviceDrainSecret();
        } catch (Throwable $e) {
            $this->logger?->warning('PairingRelayCourier: could not resolve device drain secret.', [
                'user_id' => $userId,
                'exception' => $e::class,
                ...SafeExceptionContext::describe($e),
            ]);

            return null;
        }

        return ['deviceId' => $selfDeviceId, 'token' => $drainToken];
    }

    // A drain failure is logged and reported as null (never thrown out of the
    // poll) so a transient relay outage never aborts the caller's loop.
    /**
     * @return list<array<string, mixed>>|null
     */
    private function drainRows(int $userId, string $selfDeviceId, string $drainToken): ?array
    {
        try {
            return $this->relayClient->drain($selfDeviceId, $drainToken);
        } catch (Throwable $e) {
            $this->logger?->warning('PairingRelayCourier: drain failed.', [
                'user_id' => $userId,
                'exception' => $e::class,
                ...SafeExceptionContext::describe($e),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    /**
     * @param  array<string, mixed>  $row
     */
    private function applyDrainedRow(int $userId, string $drainToken, array $row): void
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
        $blobB64 = isset($row['blob']) && is_string($row['blob']) ? $row['blob'] : null;

        if ($id === null || $blobB64 === null) {
            return;
        }

        $blob = base64_decode($blobB64, true);

        if ($blob === false) {
            // Permanently invalid — will never become decodable on a later
            // poll, so confirm it away rather than leave it stuck forever.
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
            // Another transport's frame. Confirming it here DELETED it: GDK
            // epoch wraps wait in this same mailbox for the authenticated
            // sync session to carry them, and a pairing poll that ate one
            // left the peer permanently without that epoch's key.
            self::FOREIGN_FRAME_TYPES => false,
            default => true,
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
            return true;
        }

        // The device id arrives from an untrusted drained frame. Reject
        // anything that is not a UUIDv4 (DeviceIdentityService's format)
        // before it is persisted or signed over.
        if (! self::isValidDeviceId($responderDeviceId)) {
            return true;
        }

        // applyResponderAccept() never defers — it either binds (or
        // idempotently no-ops) or fails closed (false). Either outcome is
        // terminal for this frame.
        $responderName = isset($frame['responder_name']) && is_string($frame['responder_name'])
            ? $frame['responder_name']
            : '';

        $this->tokenService->applyResponderAccept($userId, $tokenHash, $responderDeviceId, $responderEd, $responderKx, $responderName);

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
            return true;
        }

        if (! self::isValidDeviceId($confirmingDeviceId) || ! self::isValidDeviceId($peerDeviceId)) {
            return true;
        }

        $result = $this->tokenService->applyPeerConfirm($userId, $tokenHash, $confirmingDeviceId, $peerDeviceId, $sigHex);

        // 'deferred' is the ONLY non-terminal outcome — everything else
        // (a real state string, or null for a permanent rejection) is
        // terminal for this exact frame.
        return $result !== 'deferred';
    }

    // A device id from an untrusted drained frame is only accepted when it
    // is a UUIDv4 — the exact shape DeviceIdentityService mints. This bounds
    // length + charset and structurally excludes the '|' signing-message delimiter.
    private static function isValidDeviceId(string $deviceId): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $deviceId,
        ) === 1;
    }
}
