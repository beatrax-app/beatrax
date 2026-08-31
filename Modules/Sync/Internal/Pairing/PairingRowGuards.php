<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Modules\Sync\Public\Enums\PairingSide;

final class PairingRowGuards
{
    // Re-checks the hash a row was located by, in constant time. The WHERE
    // already matched it, so this guards the column and the query
    // disagreeing — a stored value that is not a string, or a driver
    // comparison that is not byte-exact.
    public static function tokenHashMatches(\stdClass $row, string $tokenHash): bool
    {
        return is_string($row->token_hash) && hash_equals($row->token_hash, $tokenHash);
    }

    // Which side of the pairing a device id owns, or null when it owns
    // neither. hash_equals rather than === because the ids being compared
    // come from a frame the far side controls.
    public static function sideOwnedBy(\stdClass $row, string $deviceId): ?PairingSide
    {
        return self::sideOwnedByIds(
            is_string($row->initiator_device_id) ? $row->initiator_device_id : null,
            is_string($row->responder_device_id) ? $row->responder_device_id : null,
            $deviceId,
        );
    }

    // The PEER side's device id: an initiator confirms toward the responder
    // column and a responder toward the initiator column. The side arrives off
    // the wire as a string, so a value that is neither reads as the responder —
    // the same column it always did.
    public static function peerDeviceIdOf(\stdClass $row, string $side): ?string
    {
        $peer = PairingSide::tryFrom($side) ?? PairingSide::Responder;
        $peerDeviceId = $row->{$peer->peerPrefix().'device_id'};

        return is_string($peerDeviceId) ? $peerDeviceId : null;
    }

    // The same question asked without a row in hand — a resumed screen holds
    // the two ids but not the record they came from, and deriving the side a
    // second way is how the two derivations drift apart.
    public static function sideOwnedByIds(?string $initiator, ?string $responder, string $deviceId): ?PairingSide
    {
        return match (true) {
            $initiator !== null && hash_equals($initiator, $deviceId) => PairingSide::Initiator,
            $responder !== null && hash_equals($responder, $deviceId) => PairingSide::Responder,
            default => null,
        };
    }
}
