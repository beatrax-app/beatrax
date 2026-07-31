<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class PeerConfirmVerifier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly DeviceKeySigner $deviceKeySigner,
    ) {}

    // The full PAIR_CONFIRM gate sequence, in the order the gates depend on
    // each other: locate the row, establish which side this device is, then
    // authenticate the frame against the identity that row bound. A null
    // return is a fail-closed rejection at any one of them.
    public function authenticatePeerConfirm(
        int $userId,
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): ?PeerConfirmContext {
        $now = $this->clock->now();

        $row = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->whereIn('state', [PairingStateMachine::AWAITING_CONFIRM, PairingStateMachine::CONFIRMED])
            ->where('expires_at', '>', $now->toIso8601String())
            ->first();

        if ($row === null || ! PairingRowGuards::tokenHashMatches($row, $tokenHash)) {
            return null;
        }

        $localSide = $this->localSideForAddressedFrame($row, $userId, $peerDeviceId);

        // $localSide is checked here rather than inside the callee so the
        // signature check below is never reached without a known local side —
        // the peer columns it reads are chosen by that side.
        if ($localSide === null
            || ! $this->peerSignatureAuthentic($row, $localSide, $tokenHash, $confirmingDeviceId, $peerDeviceId, $sigHex)) {
            return null;
        }

        return $this->peerConfirmContextFor($row, $localSide);
    }

    // Which side of this handshake the local device owns, but only for a frame
    // actually addressed to it. $peerDeviceId is populated by the SENDER from
    // ITS OWN view of who the recipient is, so on receipt it must equal THIS
    // device's self identity or the frame was never meant for this device.
    private function localSideForAddressedFrame(\stdClass $row, int $userId, string $peerDeviceId): ?string
    {
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (! is_string($selfDeviceId) || $selfDeviceId === '' || ! hash_equals($selfDeviceId, $peerDeviceId)) {
            return null;
        }

        return PairingRowGuards::sideOwnedBy($row, $selfDeviceId);
    }

    // The anti-forgery gate: the frame must be signed by the key this row
    // bound for the peer side, and must claim to come from that same bound
    // device id. Both are checked against the ROW, never against the frame's
    // own assertions about who it is.
    private function peerSignatureAuthentic(
        \stdClass $row,
        string $localSide,
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): bool {
        $peerBoundDeviceId = $this->peerSideColumn($row, $localSide, 'device_id');
        $peerPublicKeyHex = $this->peerSideColumn($row, $localSide, 'ed25519_pub_hex');

        // A frame purporting to be from some other device id is rejected even
        // before touching sodium.
        if ($peerBoundDeviceId === null
            || $peerPublicKeyHex === null
            || ! hash_equals($peerBoundDeviceId, $confirmingDeviceId)) {
            return false;
        }

        try {
            $peerPublicKeyBin = SafetyNumberDeriver::hexToRawKey($peerPublicKeyHex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $message = PairingFrame::confirmSigningMessage($tokenHash, $confirmingDeviceId, $peerDeviceId);

        return $this->deviceKeySigner->verify($message, $sigHex, $peerPublicKeyBin);
    }

    // Reads one of the peer side's columns — whichever side the local device
    // is NOT. Both sides store the same column suffixes under their own prefix.
    private function peerSideColumn(\stdClass $row, string $localSide, string $suffix): ?string
    {
        $prefix = $localSide === 'initiator' ? 'responder_' : 'initiator_';
        $value = $row->{$prefix.$suffix} ?? null;

        return is_string($value) ? $value : null;
    }

    private function peerConfirmContextFor(\stdClass $row, string $localSide): PeerConfirmContext
    {
        $localConfirmedColumn = $localSide === 'initiator' ? 'initiator_confirmed_at' : 'responder_confirmed_at';
        $peerConfirmedColumn = $localSide === 'initiator' ? 'responder_confirmed_at' : 'initiator_confirmed_at';

        return new PeerConfirmContext(
            row: $row,
            rowId: is_numeric($row->id) ? (int) $row->id : 0,
            peerConfirmedColumn: $peerConfirmedColumn,
            localConfirmedAt: is_string($row->{$localConfirmedColumn}) ? $row->{$localConfirmedColumn} : null,
        );
    }
}
