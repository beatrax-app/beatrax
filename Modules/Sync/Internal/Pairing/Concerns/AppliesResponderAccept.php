<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing\Concerns;

use Carbon\CarbonImmutable;
use Modules\Sync\Internal\Clock\ZuluTimestamp;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\PairingRowGuards;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

// PairingTokenService's inbound half: a responder identity arriving as a frame
// rather than through accept(). Every refusal in here is a fail-closed one, and
// none of them is reachable from any other entry point on the service, which is
// what makes it a subsystem rather than a slice.
/**
 * @link ../../../../../.docs/features/sync/pairing-handshake.md#the-two-frames
 */
trait AppliesResponderAccept
{
    // Applies a relayed PAIR_RESPONDER_ACCEPT frame, binding the phone's
    // responder identity onto the DESKTOP's own local row (see @link). No
    // new trust decision. Idempotent for a redelivered frame binding the
    // SAME responder; refuses a DIFFERENT responder (first binding wins).
    /**
     * @return object|false The bound (or already-bound, idempotent) row, or
     *                      `false` when the local row is unknown/expired/
     *                      already terminal, or the responder key material
     *                      is malformed — fail closed in every case.
     */
    public function applyResponderAccept(
        int $userId,
        string $tokenHash,
        string $responderDeviceId,
        string $responderEd25519Hex,
        string $responderX25519Hex,
        string $responderName = '',
    ): object|false {
        if (! $this->responderKeysWellFormed($responderEd25519Hex, $responderX25519Hex)) {
            return false;
        }

        $now = $this->clock->now();
        $row = $this->bindableRowFor($userId, $tokenHash, $responderDeviceId, $now);

        if ($row === null) {
            return false;
        }

        $state = is_string($row->state) ? $row->state : '';

        return match ($state) {
            // Already advanced. A redelivery of the SAME responder is
            // idempotent; a different one may still take the slot, but only
            // while nobody has confirmed anything yet.
            PairingState::AwaitingConfirm->value => $this->rebindOrIdempotent(
                $row,
                $userId,
                $now,
                $responderDeviceId,
                $responderEd25519Hex,
                $responderX25519Hex,
                $responderName,
            ),
            PairingState::Pending->value => $this->bindResponderOntoRow(
                $row,
                $userId,
                $now,
                $responderDeviceId,
                $responderEd25519Hex,
                $responderX25519Hex,
                $responderName,
            ),
            // Terminal, expired-into-another-state, or unrecognized: fail
            // closed rather than re-opening a handshake that has moved on.
            default => false,
        };
    }

    private function bindableRowFor(int $userId, string $tokenHash, string $responderDeviceId, CarbonImmutable $now): ?\stdClass
    {
        // No state filter here (unlike accept()): a redelivered frame must
        // be recognizable as idempotent even after the row has already
        // advanced past PENDING, so the row is located first and the
        // legal-state branching is left to the caller.
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', ZuluTimestamp::stamp($now))
            ->first();

        if ($row === null || ! PairingRowGuards::tokenHashMatches($row, $tokenHash)) {
            return null;
        }

        // A device is never its own responder, and a frame that would take the
        // side this device already occupies locks it out of its own pairing —
        // one hostile answer to the phone's own pull, and it can never confirm.
        return $this->touchesOwnSide($row, $userId, $responderDeviceId) ? null : $row;
    }

    // True when applying the frame would name this device as the responder, or
    // would overwrite a responder slot this device itself occupies. Neither can
    // arrive from an honest peer: a device binds its own side through accept().
    private function touchesOwnSide(\stdClass $row, int $userId, string $responderDeviceId): bool
    {
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (! is_string($selfDeviceId) || $selfDeviceId === '') {
            return false;
        }

        $existingResponder = is_string($row->responder_device_id) ? $row->responder_device_id : null;

        return hash_equals($selfDeviceId, $responderDeviceId)
            || ($existingResponder !== null && hash_equals($existingResponder, $selfDeviceId));
    }

    // Both responder keys must decode before the row is touched, so a
    // malformed frame cannot half-bind an identity onto the local row.
    private function responderKeysWellFormed(string $ed25519Hex, string $x25519Hex): bool
    {
        try {
            SafetyNumberDeriver::hexToRawKey($ed25519Hex);
            SafetyNumberDeriver::hexToRawKey($x25519Hex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        return true;
    }

    // A binding nobody has confirmed is not yet a decision, so a later accept may
    // replace it — absolute first-binding-wins let anyone on the network hold the
    // slot forever. What stops a replacement becoming a capture is confirm()
    // binding the tap to the compared keys (see @link).
    private function rebindOrIdempotent(
        \stdClass $row,
        int $userId,
        CarbonImmutable $now,
        string $responderDeviceId,
        string $responderEd25519Hex,
        string $responderX25519Hex,
        string $responderName,
    ): object|false {
        $existingResponder = is_string($row->responder_device_id) ? $row->responder_device_id : null;

        if ($existingResponder !== null && hash_equals($existingResponder, $responderDeviceId)) {
            return $row;
        }

        if (self::eitherSideConfirmed($row)) {
            return false;
        }

        return $this->bindResponderOntoRow(
            $row,
            $userId,
            $now,
            $responderDeviceId,
            $responderEd25519Hex,
            $responderX25519Hex,
            $responderName,
        );
    }

    private static function eitherSideConfirmed(\stdClass $row): bool
    {
        return is_string($row->initiator_confirmed_at ?? null)
            || is_string($row->responder_confirmed_at ?? null);
    }

    // The PENDING -> AWAITING_CONFIRM transition: binds the responder identity
    // and extends the window, never shortening an expiry that is already later.
    private function bindResponderOntoRow(
        \stdClass $row,
        int $userId,
        CarbonImmutable $now,
        string $responderDeviceId,
        string $responderEd25519Hex,
        string $responderX25519Hex,
        string $responderName,
    ): object|false {
        $newExpiry = $this->extendedExpiry($row, $now);
        $rowId = is_numeric($row->id) ? (int) $row->id : 0;

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $rowId)
            ->where('user_id', $userId)
            ->update([
                'responder_device_id' => $responderDeviceId,
                'responder_ed25519_pub_hex' => $responderEd25519Hex,
                'responder_x25519_pub_hex' => $responderX25519Hex,
                // Cosmetic label only, carried from accept to admission;
                // see the migration for why it rides on this row.
                'responder_name' => $responderName !== '' ? $responderName : null,
                'state' => PairingState::AwaitingConfirm->value,
                'accepted_at' => $now->toIso8601String(),
                'expires_at' => ZuluTimestamp::stamp($newExpiry),
            ]);

        $accepted = $this->db->connection()->table('pairing_tokens')
            ->where('id', $rowId)
            ->where('user_id', $userId)
            ->first();

        return $accepted ?? false;
    }}
