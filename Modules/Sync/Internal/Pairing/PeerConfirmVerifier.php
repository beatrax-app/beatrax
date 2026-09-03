<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Enums\PairingSide;
use Psr\Log\LoggerInterface;

final readonly class PeerConfirmVerifier
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private DeviceKeySigner $deviceKeySigner,
        private ?LoggerInterface $logger = null,
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
            ->whereIn('state', [PairingState::AwaitingConfirm->value, PairingState::Confirmed->value])
            ->where('expires_at', '>', Instant::zulu($now))
            ->first();

        if ($row === null) {
            $this->refused('no live pairing row holds this token');

            return null;
        }

        if (! PairingRowGuards::tokenHashMatches($row, $tokenHash)) {
            $this->refused('the located row disagrees about the token hash');

            return null;
        }

        $localSide = $this->localSideForAddressedFrame($row, $userId, $peerDeviceId);

        // $localSide is checked here rather than inside the callee so the
        // signature check below is never reached without a known local side —
        // the peer columns it reads are chosen by that side.
        if ($localSide === null) {
            $this->refused('the frame is not addressed to this device');

            return null;
        }

        if (! $this->peerSignatureAuthentic($row, $localSide, $tokenHash, $confirmingDeviceId, $peerDeviceId, $sigHex)) {
            return null;
        }

        return $this->peerConfirmContextFor($row, $localSide);
    }

    // Which side of this handshake the local device owns, but only for a frame
    // actually addressed to it. $peerDeviceId is populated by the SENDER from
    // ITS OWN view of who the recipient is, so on receipt it must equal THIS
    // device's self identity or the frame was never meant for this device.
    private function localSideForAddressedFrame(\stdClass $row, int $userId, string $peerDeviceId): ?PairingSide
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
        PairingSide $localSide,
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): bool {
        $peerBoundDeviceId = $this->peerSideColumn($row, $localSide, 'device_id');
        $peerPublicKeyHex = $this->peerSideColumn($row, $localSide, 'ed25519_pub_hex');

        // The confirming device is the peer side; the frame's peer_device_id is
        // THIS device. Bind both sealing keys as THIS row holds them, so a relay
        // that swapped the peer's X25519 makes this reconstruction diverge from
        // what the honest peer signed — the verify below then fails.
        $confirmingDeviceKxHex = $this->peerSideColumn($row, $localSide, 'x25519_pub_hex');
        $peerDeviceKxHex = $this->localSideColumn($row, $localSide, 'x25519_pub_hex');

        // A frame purporting to be from some other device id is rejected even
        // before touching sodium.
        if ($peerBoundDeviceId === null
            || $peerPublicKeyHex === null
            || $confirmingDeviceKxHex === null
            || $peerDeviceKxHex === null) {
            $this->refused('this row never bound a key for one of the two sides');

            return false;
        }

        if (! hash_equals($peerBoundDeviceId, $confirmingDeviceId)) {
            $this->refused('the frame claims a device id this row never bound');

            return false;
        }

        try {
            $peerPublicKeyBin = SafetyNumberDeriver::hexToRawKey($peerPublicKeyHex);
        } catch (InvalidPublicKeyException) {
            $this->refused('the peer public key bound to this row will not decode');

            return false;
        }

        // Deliberately NOT verifyAny() against a legacy no-X25519 payload:
        // accepting the old shape would re-open the sealing-key substitution
        // this closes. A cross-version pairing fails closed and retries once
        // both devices update — no persistent trust state is stranded.
        $message = PairingFrame::confirmSigningMessage(
            $tokenHash,
            $confirmingDeviceId,
            $peerDeviceId,
            $confirmingDeviceKxHex,
            $peerDeviceKxHex,
        );

        if (! $this->deviceKeySigner->verify($message, $sigHex, $peerPublicKeyBin)) {
            $this->refused('the signature does not match the key this row bound');

            return false;
        }

        return true;
    }

    // Every refusal here is fail-closed and terminal, and no road that carries
    // a frame reports one, so a handshake that stops on a real device leaves
    // nothing to read. The gate is named; no device id, key or token joins it,
    // because none of those belongs in a log file.
    private function refused(string $gate): void
    {
        $this->logger?->warning('PeerConfirmVerifier: refused a peer confirm.', ['gate' => $gate]);
    }

    // Reads one of the peer side's columns — whichever side the local device
    // is NOT. Both sides store the same column suffixes under their own prefix.
    private function peerSideColumn(\stdClass $row, PairingSide $localSide, string $suffix): ?string
    {
        $value = $row->{$localSide->peerPrefix().$suffix} ?? null;

        return is_string($value) ? $value : null;
    }

    // The mirror of peerSideColumn(): reads the LOCAL side's own column, used
    // to bind this device's own X25519 into the confirm-signature reconstruction.
    private function localSideColumn(\stdClass $row, PairingSide $localSide, string $suffix): ?string
    {
        $value = $row->{$localSide->columnPrefix().$suffix} ?? null;

        return is_string($value) ? $value : null;
    }

    private function peerConfirmContextFor(\stdClass $row, PairingSide $localSide): PeerConfirmContext
    {
        $localConfirmedColumn = $localSide->confirmedAtColumn();

        return new PeerConfirmContext(
            row: $row,
            rowId: is_numeric($row->id) ? (int) $row->id : 0,
            peerConfirmedColumn: $localSide->peerConfirmedAtColumn(),
            localConfirmedAt: is_string($row->{$localConfirmedColumn}) ? $row->{$localConfirmedColumn} : null,
        );
    }
}
