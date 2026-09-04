<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Public\Enums\PairingSide;

// The local human's tap and the three refusals standing in front of it. They
// live together because each one only means anything in the order below: a
// ceremony nobody may still finish, then a device that owns neither side,
// then a key pair that no longer derives the words that were compared.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#what-a-confirmation-is-bound-to
 */
final readonly class LocalConfirmRecorder
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SafetyNumberDeriver $safetyDeriver,
        private CeremonyWindow $window,
    ) {}

    // The row carrying this side's fresh stamp, or null for any of the three
    // refusals — all of which the caller renders the same way, because none of
    // them may be reported to the reader as silence.
    public function record(int $tokenId, int $userId, string $confirmingDeviceId, string $expectedSafetyDigest): ?\stdClass
    {
        // A ceremony the reader cancelled, or one whose window has run out, is
        // not one a later tap may still finish into a confirmed device_registry
        // row. Every other door into that admission was already gated; this one
        // was not, and PairingState's own comment claimed it was.
        if (! $this->ceremonyIsLive($tokenId, $userId)) {
            return null;
        }

        // An unknown token and a device that owns neither side are the same
        // refusal: this device has nothing it may confirm on this token.
        $side = $this->confirmableSideFor($tokenId, $userId, $confirmingDeviceId);

        // The tap confirms the keys behind the words on screen, not whatever the
        // row says now: without this a responder rebinding between the reading
        // and the tap inherits a confirmation nobody gave it (see @link).
        $comparedKeys = $side === null
            ? null
            : $this->keysBehindDigest($tokenId, $userId, $expectedSafetyDigest);

        return $side === null || $comparedKeys === null
            ? null
            : $this->stampSideConfirmation($tokenId, $userId, $side, $comparedKeys);
    }

    // Whether this token still names a ceremony a tap may finish: in flight and
    // inside its window. `expired` is what expire() and expireUnfinished()
    // write, so both the countdown hitting zero and the reader cancelling land
    // here, and neither may be walked back by a confirm arriving afterwards.
    private function ceremonyIsLive(int $tokenId, int $userId): bool
    {
        return $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->whereIn('state', PairingState::inFlightValues())
            ->where('expires_at', '>', Instant::zulu($this->clock->now()))
            ->exists();
    }

    // The side this device may confirm on this token, or null when the token
    // is unknown or the device owns neither side.
    private function confirmableSideFor(int $tokenId, int $userId, string $deviceId): ?PairingSide
    {
        $token = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['initiator_device_id', 'responder_device_id']);

        return $token === null ? null : PairingRowGuards::sideOwnedBy($token, $deviceId);
    }

    // The two Ed25519 keys the compared words were derived from, or null when
    // the row's current pair no longer produces that digest — which means the
    // pair being confirmed is not the pair that was read aloud.
    /**
     * @return array{initiator: string, responder: string}|null
     */
    private function keysBehindDigest(int $tokenId, int $userId, string $expectedSafetyDigest): ?array
    {
        if ($expectedSafetyDigest === '') {
            return null;
        }

        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        if ($row === null
            || ! is_string($row->initiator_ed25519_pub_hex)
            || ! is_string($row->responder_ed25519_pub_hex)) {
            return null;
        }

        $pair = ['initiator' => $row->initiator_ed25519_pub_hex, 'responder' => $row->responder_ed25519_pub_hex];

        return $this->pairDerivesDigest($pair, $expectedSafetyDigest) ? $pair : null;
    }

    // Key material the deriver refuses derives nothing, so an unusable pair is
    // a plain mismatch rather than an exception out of the confirm path.
    /**
     * @param  array{initiator: string, responder: string}  $pair
     */
    private function pairDerivesDigest(array $pair, string $expectedSafetyDigest): bool
    {
        try {
            $current = $this->safetyDeriver->digestFor($pair['initiator'], $pair['responder']);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        return hash_equals($current, $expectedSafetyDigest);
    }

    // Stamping and reading back are one step: the stamp is conditional on the
    // compared keys still being the bound ones, so only the row that comes back
    // carrying it is evidence that this side was recorded.
    /**
     * @param  array{initiator: string, responder: string}  $comparedKeys
     */
    private function stampSideConfirmation(int $tokenId, int $userId, PairingSide $side, array $comparedKeys): ?\stdClass
    {
        $column = $side->confirmedAtColumn();
        $now = $this->clock->now();

        // The tap is the proof a human is still in this ceremony, so it moves
        // the window the peer's own confirm is checked against. Without it the
        // side that has finished times out the side it is waiting for, and the
        // arriving frame is refused as expired by a row nobody had abandoned.
        $extended = $this->window->extendedFrom(
            $this->db->connection()->table('pairing_tokens')
                ->where('id', $tokenId)
                ->where('user_id', $userId)
                ->value('expires_at'),
            $now,
        );

        // Those keys ride in the WHERE rather than being checked and then
        // trusted: a rebind landing in between matches no row, so nothing is
        // stamped and the re-read below sees an unconfirmed side.
        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->where('initiator_ed25519_pub_hex', $comparedKeys['initiator'])
            ->where('responder_ed25519_pub_hex', $comparedKeys['responder'])
            ->update([$column => Instant::zulu($now), 'expires_at' => Instant::zulu($extended)]);

        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first();

        // Read back rather than trusting an affected-row count, which a driver
        // may report as zero for a second tap writing the value already there.
        if ($row === null || ! is_string($row->{$column})) {
            return null;
        }

        return $row;
    }
}
