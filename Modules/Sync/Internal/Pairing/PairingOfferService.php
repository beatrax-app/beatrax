<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Services\DeviceRegistryService;

// The half of the QR a typed word-code cannot carry: the initiator's public
// identity, looked up by the token the human typed. Public keys and a device
// id only — this endpoint is in-band on the LAN, so nothing that would let a
// listener reach a relay is handed out here (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingOfferService
{
    // A row is offerable while the handshake is still live. AWAITING_CONFIRM
    // counts as well as PENDING: a responder retrying after a lost frame is
    // asking about a row its own accept already advanced.
    private const array OFFERABLE_STATES = [
        PairingState::Pending->value,
        PairingState::AwaitingConfirm->value,
    ];

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private DeviceRegistryService $registry,
    ) {}

    /**
     * @return array{device_id: string, ed25519: string, x25519: string, name: string}|null
     */
    // Takes the token's HASH, never the token. The row is stored under
    // sha256(token), so the hash is all a lookup needs — and the token itself
    // stays on the device that typed it rather than going to whichever peer
    // answered a multicast question first.
    public function offerFor(string $tokenHash, int $userId): ?array
    {
        if ($tokenHash === '' || $userId <= 0) {
            return null;
        }

        $row = $this->offerableRow($tokenHash, $userId);

        return $row === null ? null : $this->publicIdentity($row, $userId);
    }

    // The live handshake row this hash names, or null when there is none the
    // caller may be told about.
    private function offerableRow(string $tokenHash, int $userId): ?\stdClass
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('token_hash', $tokenHash)
            ->where('user_id', $userId)
            ->whereIn('state', self::OFFERABLE_STATES)
            ->where('expires_at', '>', PairingExpiry::stamp($this->clock->now()))
            ->first();

        if ($row === null || ! PairingRowGuards::tokenHashMatches($row, $tokenHash)) {
            return null;
        }

        return $row;
    }

    // Null unless all three halves of the initiator's identity are bound: a
    // partial offer would let a peer pair against keys nobody holds.
    /**
     * @return array{device_id: string, ed25519: string, x25519: string, name: string}|null
     */
    private function publicIdentity(\stdClass $row, int $userId): ?array
    {
        $deviceId = $this->stringColumn($row, 'initiator_device_id');
        $ed25519 = $this->stringColumn($row, 'initiator_ed25519_pub_hex');
        $x25519 = $this->stringColumn($row, 'initiator_x25519_pub_hex');

        if ($deviceId === '' || $ed25519 === '' || $x25519 === '') {
            return null;
        }

        return [
            'device_id' => $deviceId,
            'ed25519' => $ed25519,
            'x25519' => $x25519,
            'name' => $this->initiatorName($row, $userId),
        ];
    }

    // The row's own initiator_name is set only on a SEEDED row, which this
    // device never serves an offer for; a row this device issued carries
    // none, so its own registry name is what the peer should be told.
    private function initiatorName(\stdClass $row, int $userId): string
    {
        $stored = $this->stringColumn($row, 'initiator_name');

        return $stored !== '' ? $stored : ($this->registry->localDeviceName($userId) ?? '');
    }

    private function stringColumn(\stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : '';
    }
}
