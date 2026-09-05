<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Public\Enums\PairingFrameSend;
use Psr\Log\LoggerInterface;
use Throwable;

// The three things a pairing screen owes the other device: the accept frame,
// the confirm frame, and every keyring epoch once that device is admitted.
// All three are best-effort under one rule — a failure is logged and never
// flashed, because nothing here may undo a pairing that already completed.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#redelivery-must-not-depend-on-an-open-screen
 */
final readonly class PairingPeerErrands
{
    public function __construct(
        private DatabaseManager $db,
        private PairingPeerLink $peerLink,
        private PairingFrameCourier $frameCourier,
        private GdkRotationService $rotation,
        private LoggerInterface $logger,
    ) {}

    // The initiator's row is on the initiator's own machine, and nothing the
    // responder's accept wrote is visible there. Without this frame that device
    // sits on its own show-code step until the token lapses, while this one
    // waits at the trust gate for a comparison the peer has never been shown.
    public function announceResponderAccept(int $userId, string $tokenHash, string $initiatorDeviceId, Session $session): void
    {
        try {
            // Read rather than dropped. The one caller loads the identity in
            // this same request and refuses before reaching here, so this arm
            // means a second caller skipped that check — a line in the log
            // rather than the silence it used to be.
            if ($this->peerLink->sendResponderAccept($userId, $tokenHash, $initiatorDeviceId, $session) === PairingFrameSend::NoUsableIdentity) {
                $this->logger->warning('Pairing: PAIR_RESPONDER_ACCEPT was not sent — this device holds no identity to sign it with.', [
                    'user_id' => $userId,
                ]);
            }
        } catch (Throwable $e) {
            // The trust gate is already rendered, and the courier holds the
            // frame for the peer to collect on every road but the one where
            // nothing is open at all.
            $this->logger->warning('Pairing: cross-device PAIR_RESPONDER_ACCEPT delivery failed.', [
                'user_id' => $userId,
                'exception' => $e::class,
            ]);
        }
    }

    // No relay check: the courier tries the LAN, then the relay, then holds the
    // frame for collection, so returning early on an unconfigured relay left a
    // LAN-only pairing confirmed on one device and not the other.
    public function sendConfirm(DeviceIdentityDto $identity, int $tokenId, int $userId, string $side): void
    {
        // Scoped even though every writer of the token id is user-scoped, so no
        // reachable state makes this cross-user. A read of a user-owned table
        // that does not say whose it is reads as an oversight to the next
        // person, and its twin in Mobile carries it.
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['token_hash', 'initiator_device_id', 'responder_device_id']);

        if ($row === null) {
            return;
        }

        $tokenHash = is_string($row->token_hash) ? $row->token_hash : null;
        $peerDeviceId = PairingRowGuards::peerDeviceIdOf($row, $side);

        if ($tokenHash === null || $peerDeviceId === null) {
            return;
        }

        try {
            $this->frameCourier->sendConfirm($identity, $peerDeviceId, $tokenHash);
        } catch (Throwable $e) {
            $this->logger->warning('Pairing: cross-device PAIR_CONFIRM relay delivery failed.', [
                'pairing_token_id' => $tokenId,
                'exception' => $e::class,
            ]);
        }
    }

    // Asks the permanent device_registry rather than the transient token row:
    // prune() drops that row on the next issue(), so resolving the recipient
    // from it delivered nothing once the ceremony outlived its own token.
    /**
     * @return bool whether every confirmed peer took its epochs — false also
     *              when there was no confirmed peer to deliver to at all, which
     *              leaves that peer unable to decrypt anything
     */
    public function fanOutEpochsToConfirmedPeers(int $userId, Session $session): bool
    {
        $recipients = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->whereNotNull('confirmed_at')
            ->pluck('id');

        if ($recipients->isEmpty()) {
            $this->logger->warning('GDK epoch fan-out found no confirmed peer to deliver to — the peer cannot decrypt anything until it is admitted.', [
                'user_id' => $userId,
            ]);

            return false;
        }

        $delivered = true;

        foreach ($recipients as $deviceRegistryId) {
            if (! is_numeric($deviceRegistryId)) {
                continue;
            }

            try {
                $this->rotation->fanOutAllEpochsToDevice($userId, (int) $deviceRegistryId, $session);
            } catch (Throwable $e) {
                $delivered = false;
                $this->logger->warning('GDK epoch fan-out to newly-confirmed device failed.', [
                    'user_id' => $userId,
                    'device_registry_id' => (int) $deviceRegistryId,
                    'exception' => $e::class,
                ]);
            }
        }

        return $delivered;
    }
}
