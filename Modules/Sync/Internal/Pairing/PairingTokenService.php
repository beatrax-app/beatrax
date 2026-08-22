<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Clock\ZuluTimestamp;
use Modules\Sync\Internal\Pairing\Concerns\AppliesResponderAccept;
use Modules\Sync\Public\Enums\PairingSide;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final class PairingTokenService
{
    use AppliesResponderAccept;

    private const int TTL_MINUTES = 10;

    private const int ACCEPT_GRACE_MINUTES = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly PairingStateMachine $stateMachine,
        private readonly PairedDeviceAdmitter $admitter,
        private readonly PeerConfirmVerifier $peerConfirmVerifier,
        private readonly SafetyNumberDeriver $safetyDeriver,
    ) {}

    // Mints a pairing token for the initiator and persists its hash. Returns
    // the plaintext token — displayed once, never stored.
    public function issue(
        int $userId,
        string $initiatorDeviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
    ): string {
        // Reject malformed key material at the trust boundary so the stored
        // *_pub_hex columns are always valid 32-byte hex and later
        // safety-number derivation can never throw a raw SodiumException.
        SafetyNumberDeriver::hexToRawKey($ed25519PubHex);
        SafetyNumberDeriver::hexToRawKey($x25519PubHex);

        $now = $this->clock->now();

        // Prune stale handshake rows for this user before minting a fresh
        // one — pairing_tokens is a transient scratch table swept here so it
        // never accumulates stale initiator key material indefinitely.
        $this->prune($userId, $now);

        $token = bin2hex(random_bytes(16));

        $this->db->connection()->table('pairing_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'initiator_device_id' => $initiatorDeviceId,
            'initiator_ed25519_pub_hex' => $ed25519PubHex,
            'initiator_x25519_pub_hex' => $x25519PubHex,
            'state' => PairingState::Pending->value,
            'expires_at' => ZuluTimestamp::stamp($now->addMinutes(self::TTL_MINUTES)),
            'created_at' => $now->toIso8601String(),
        ]);

        return $token;
    }

    // Seeds a LOCAL pairing_tokens row from a scanned QR's initiator identity
    // — the cross-device counterpart of issue(), needed because accept()
    // only ever binds a responder against a row THIS database already holds
    // (see @link for why this row-seeding shape is necessary).
    /**
     * @return object|false The seeded (or already-seeded) row, or `false`
     *                      when the initiator's key material is malformed.
     */
    public function seedFromInitiator(
        int $userId,
        string $initiatorDeviceId,
        string $initiatorEd25519PubHex,
        string $initiatorX25519PubHex,
        string $token,
        ?string $initiatorName = null,
    ): object|false {
        try {
            SafetyNumberDeriver::hexToRawKey($initiatorEd25519PubHex);
            SafetyNumberDeriver::hexToRawKey($initiatorX25519PubHex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $now = $this->clock->now();
        $this->prune($userId, $now);

        $tokenHash = hash('sha256', $token);

        // Idempotent: a pending row already seeded for this exact
        // token_hash + user is returned as-is rather than duplicated.
        $existing = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->where('state', PairingState::Pending->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->db->connection()->table('pairing_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'initiator_device_id' => $initiatorDeviceId,
            'initiator_ed25519_pub_hex' => $initiatorEd25519PubHex,
            'initiator_x25519_pub_hex' => $initiatorX25519PubHex,
            'state' => PairingState::Pending->value,
            'expires_at' => ZuluTimestamp::stamp($now->addMinutes(self::TTL_MINUTES)),
            'initiator_seeded_at' => $now->toIso8601String(),
            'initiator_name' => $initiatorName,
            'created_at' => $now->toIso8601String(),
        ]);

        $seeded = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->first();

        return $seeded ?? false;
    }

    // Returns the updated token row on success, or false when the token is
    // unknown, already used, expired, or belongs to another user.
    public function accept(
        string $submittedToken,
        int $userId,
        string $responderDeviceId,
        string $responderEd25519PubHex,
        string $responderX25519PubHex,
    ): object|false {
        // The responder keys are attacker-controllable — validate them at
        // the trust boundary so a non-hex/wrong-length key is rejected as a
        // clean "invalid code" rather than persisted and exploded later.
        try {
            SafetyNumberDeriver::hexToRawKey($responderEd25519PubHex);
            SafetyNumberDeriver::hexToRawKey($responderX25519PubHex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $tokenHash = hash('sha256', $submittedToken);
        $now = $this->clock->now();

        $row = $this->db->connection()->table('pairing_tokens')
            ->where('token_hash', $tokenHash)
            ->where('user_id', $userId)
            ->where('state', PairingState::Pending->value)
            ->where('expires_at', '>', ZuluTimestamp::stamp($now))
            ->first();

        if ($row === null || ! PairingRowGuards::tokenHashMatches($row, $tokenHash)) {
            return false;
        }

        $acceptedAt = $now->toIso8601String();
        $newExpiry = $this->extendedExpiry($row, $now);

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->update([
                'responder_device_id' => $responderDeviceId,
                'responder_ed25519_pub_hex' => $responderEd25519PubHex,
                'responder_x25519_pub_hex' => $responderX25519PubHex,
                'state' => PairingState::AwaitingConfirm->value,
                'accepted_at' => $acceptedAt,
                'expires_at' => ZuluTimestamp::stamp($newExpiry),
            ]);

        $accepted = $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->first();

        return $accepted ?? false;
    }

    // A "grace extension" must only ever GROW the lifetime — take
    // max(existing expiry, now + grace floor) so an early accept never
    // shortens the live handshake. Both transports reach the responder
    // binding, and neither may reach it with its own version of this rule.
    private function extendedExpiry(\stdClass $row, CarbonImmutable $now): CarbonImmutable
    {
        $graceExpiry = $now->addMinutes(self::ACCEPT_GRACE_MINUTES);
        $existingExpiry = is_string($row->expires_at)
            ? CarbonImmutable::parse($row->expires_at)
            : $graceExpiry;

        return $graceExpiry->greaterThan($existingExpiry) ? $graceExpiry : $existingExpiry;
    }

    // The confirming side is derived from the caller's OWN device id, not a
    // client-supplied string, so a device can only confirm the side it
    // actually owns. Returns the resulting pairing state (see @link).
    /**
     * @param  string  $expectedSafetyDigest  Fingerprint of the six words the
     *                                        human actually compared, taken
     *                                        when they were shown.
     */
    public function confirm(int $tokenId, int $userId, string $confirmingDeviceId, string $expectedSafetyDigest): ?string
    {
        // An unknown token and a device that owns neither side are the same
        // refusal: this device has nothing it may confirm on this token.
        $side = $this->confirmableSideFor($tokenId, $userId, $confirmingDeviceId);
        if ($side === null) {
            return null;
        }

        // The tap confirms the keys behind the words on screen, not whatever the
        // row says now: without this a responder rebinding between the reading
        // and the tap inherits a confirmation nobody gave it (see @link).
        $comparedKeys = $this->keysBehindDigest($tokenId, $userId, $expectedSafetyDigest);
        if ($comparedKeys === null) {
            return null;
        }

        $stamped = $this->stampSideConfirmation($tokenId, $userId, $side, $comparedKeys);

        return $stamped === null ? null : $this->finalizeIfBothConfirmed($stamped, $userId);
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

        // Those keys ride in the WHERE rather than being checked and then
        // trusted: a rebind landing in between matches no row, so nothing is
        // stamped and the re-read below sees an unconfirmed side.
        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->where('initiator_ed25519_pub_hex', $comparedKeys['initiator'])
            ->where('responder_ed25519_pub_hex', $comparedKeys['responder'])
            ->update([$column => $this->clock->now()->toIso8601String()]);

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

    // Applies a relayed, Ed25519-SIGNED PAIR_CONFIRM frame from the bound
    // peer identity — the anti-forgery gate the whole cross-device
    // propagation rests on (full gate sequence documented at @link,
    // including the load-bearing local-human-confirmed-first gate).
    /**
     * @return string|null Returns the resulting pairing state
     *                     (`'awaiting_confirm'`/`'confirmed'`) on success,
     *                     the literal string `'deferred'` when the frame is
     *                     valid but the local side has not yet confirmed, or
     *                     `null` on any fail-closed rejection.
     */
    public function applyPeerConfirm(
        int $userId,
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): ?string {
        $context = $this->peerConfirmVerifier->authenticatePeerConfirm(
            $userId,
            $tokenHash,
            $confirmingDeviceId,
            $peerDeviceId,
            $sigHex,
        );

        return match (true) {
            $context === null => null,
            // The load-bearing gate: a validly-signed peer confirm CANNOT by
            // itself drive this row toward CONFIRMED — the local human must
            // have already visually matched the safety words and tapped
            // confirm. Leave the relay row pending for redelivery until then.
            $context->localConfirmedAt === null => 'deferred',
            default => $this->recordPeerConfirmAndFinalize($context, $userId),
        };
    }

    // Stamps the peer's confirmation if it is not already recorded, then
    // re-reads the row so the state decision is made against what is actually
    // persisted rather than against the pre-update copy.
    private function recordPeerConfirmAndFinalize(PeerConfirmContext $context, int $userId): ?string
    {
        $peerConfirmedAt = is_string($context->row->{$context->peerConfirmedColumn})
            ? $context->row->{$context->peerConfirmedColumn}
            : null;

        if ($peerConfirmedAt === null) {
            $this->db->connection()->table('pairing_tokens')
                ->where('id', $context->rowId)
                ->where('user_id', $userId)
                ->update([$context->peerConfirmedColumn => $this->clock->now()->toIso8601String()]);
        }

        $freshRow = $this->db->connection()->table('pairing_tokens')
            ->where('id', $context->rowId)
            ->where('user_id', $userId)
            ->first();

        return $freshRow === null ? null : $this->finalizeIfBothConfirmed($freshRow, $userId);
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

    // Shared tail of confirm() and applyPeerConfirm(): both reach the exact
    // same admission semantics — bothConfirmed() -> set CONFIRMED ->
    // admitResponderDevice() -> conditional admitInitiatorDevice() (see @link).
    private function finalizeIfBothConfirmed(\stdClass $row, int $userId): string
    {
        $initiatorConfirmedAt = is_string($row->initiator_confirmed_at) ? $row->initiator_confirmed_at : null;
        $responderConfirmedAt = is_string($row->responder_confirmed_at) ? $row->responder_confirmed_at : null;

        if (! $this->stateMachine->bothConfirmed($initiatorConfirmedAt, $responderConfirmedAt)) {
            return is_string($row->state) ? $row->state : PairingState::AwaitingConfirm->value;
        }

        $rowId = is_numeric($row->id) ? (int) $row->id : 0;

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $rowId)
            ->where('user_id', $userId)
            ->update(['state' => PairingState::Confirmed->value]);

        $this->admitter->admitResponderDevice($row, $userId);

        // ALSO admit the initiator into the local registry — but ONLY for a
        // row seeded from a genuinely scanned QR (initiator_seeded_at IS NOT
        // NULL, set exclusively by seedFromInitiator()) — never for a
        // placeholder issue()-created row's device id.
        if (is_string($row->initiator_seeded_at)) {
            $this->admitter->admitInitiatorDevice($row, $userId);
        }

        return PairingState::Confirmed->value;
    }

    public function expire(int $tokenId, int $userId): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->update(['state' => PairingState::Expired->value]);
    }

    // Deletes stale pairing_tokens rows: past TTL or already terminal.
    // pairing_tokens is a transient scratch table — its permanent trust
    // store is device_registry, so pruning never loses trust state.
    public function prune(int $userId, CarbonImmutable $now): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->where('expires_at', '<', ZuluTimestamp::stamp($now))
                    ->orWhereIn('state', [
                        PairingState::Confirmed->value,
                        PairingState::Expired->value,
                    ]);
            })
            ->delete();
    }
}
