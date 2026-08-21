<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final class PairingFrameCourier
{
    private const string FOREIGN_FRAME_TYPE = GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP;

    public function __construct(
        private readonly RelayClient $relayClient,
        private readonly RelayConfig $relayConfig,
        private readonly DeviceKeySigner $signer,
        private readonly PairingFrameApplier $applier,
        private readonly LanPairingFrameCourier $lanCourier,
        private readonly PairingPeerOutbox $outbox,
        private readonly DatabaseManager $db,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // Delivers a PAIR_RESPONDER_ACCEPT frame (phone -> desktop) so the
    // desktop's LOCAL row can bind it. The CALLER is responsible for catching
    // the throw and surfacing a non-blocking retry; this never silently
    // swallows a failure.
    /**
     * @throws Throwable see {@see self::deliver()}.
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

        $this->deliver($senderDid, $recipientDesktopDid, $frame);
    }

    // $self's OWN secret key signs the frame — the relay never holds it and
    // cannot forge this. Same best-effort-at-the-caller posture as
    // sendResponderAccept() — failures propagate.
    /**
     * @throws Throwable see {@see self::deliver()}.
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

        $this->deliver($self->deviceId, $peerDid, $frame);
    }

    // LAN first, relay second, then held for collection. Silent by design: which
    // road a frame took is not something the reader chose or can act on.
    /**
     * @param  array<string, mixed>  $frame
     *
     * @throws Throwable when no road home is open at all — the LAN peer was
     *                   unreachable, the relay refused or could not be dialled,
     *                   and the peer's holding space is full.
     */
    private function deliver(string $senderDid, string $recipientDid, array $frame): void
    {
        if ($this->lanCourier->deliver($recipientDid, $frame)) {
            return;
        }

        try {
            $this->relayClient->deliver($senderDid, $recipientDid, json_encode($frame, JSON_THROW_ON_ERROR));

            return;
        } catch (Throwable $e) {
            // Neither road is open: the peer runs no listener to push to — a
            // phone never does — and the relay is unconfigured, refusing, or
            // simply not answering. A transport failure used to escape this
            // catch, which is the one case the holding space exists for.
            if (! $this->outbox->queueFor($senderDid, $recipientDid, $frame)) {
                throw $e;
            }
        }
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
                $this->logger?->warning('PairingFrameCourier: failed to apply a drained pairing frame.', [
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
            $this->logger?->warning('PairingFrameCourier: could not resolve device drain secret.', [
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
            $this->logger?->warning('PairingFrameCourier: drain failed.', [
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
            // Another transport's frame. Confirming it here DELETED it: GDK
            // epoch wraps wait in this same mailbox for the authenticated
            // sync session to carry them, and a pairing poll that ate one
            // left the peer permanently without that epoch's key.
            self::FOREIGN_FRAME_TYPE => false,
            // Deferred is the only outcome worth redelivering: the frame is
            // valid but the local human has not confirmed yet. Applied and
            // Refused are both done with this copy of it.
            default => $this->applier->apply($userId, $decoded) !== PairingFrameOutcome::Deferred,
        };

        if ($terminal) {
            $this->relayClient->confirm($id, $drainToken);
        }
        // else: a valid-but-deferred PAIR_CONFIRM — leave the row pending
        // for the next poll to redeliver, once the local side confirms.
    }
}
