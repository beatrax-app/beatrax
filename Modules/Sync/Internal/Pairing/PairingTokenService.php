<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Pairing\Concerns\AppliesResponderAccept;
use Modules\Sync\Public\Dto\PairingPeerIdentity;

/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
final readonly class PairingTokenService
{
    use AppliesResponderAccept;

    private const int TTL_MINUTES = 10;

    // The absolute age past which a ceremony is dead however many times a human
    // moment renewed it. Two people comparing six words do not need an hour;
    // anything still unconfirmed after one is a row nobody is attending.
    private const int CEREMONY_MAX_AGE_MINUTES = 60;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private PairingStateMachine $stateMachine,
        private PairedDeviceAdmitter $admitter,
        private PeerConfirmVerifier $peerConfirmVerifier,
        private LocalConfirmRecorder $localConfirm,
        private HeldPeerConfirm $heldPeerConfirm,
        private CeremonyWindow $window,
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
            'expires_at' => Instant::zulu($now->addMinutes(self::TTL_MINUTES)),
            'created_at' => Instant::zulu($now),
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
    public function seedFromInitiator(int $userId, PairingPeerIdentity $initiator, string $token): object|false
    {
        try {
            SafetyNumberDeriver::hexToRawKey($initiator->ed25519PubHex);
            SafetyNumberDeriver::hexToRawKey($initiator->x25519PubHex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $now = $this->clock->now();
        $this->prune($userId, $now);

        $tokenHash = hash('sha256', $token);

        // Idempotent for a ceremony still in flight, not only a `pending` one:
        // once accept() has moved the row to awaiting_confirm, a second scan of
        // the code the initiator is still showing fell through to insert() and
        // hit the token_hash UNIQUE index — a 500 on the phone's own screen.
        $existing = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->whereIn('state', PairingState::inFlightValues())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->db->connection()->table('pairing_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'initiator_device_id' => $initiator->deviceId,
            'initiator_ed25519_pub_hex' => $initiator->ed25519PubHex,
            'initiator_x25519_pub_hex' => $initiator->x25519PubHex,
            'state' => PairingState::Pending->value,
            'expires_at' => Instant::zulu($now->addMinutes(self::TTL_MINUTES)),
            'initiator_seeded_at' => Instant::zulu($now),
            'initiator_name' => $initiator->deviceName,
            'initiator_lan_host' => $initiator->lanHost,
            'initiator_lan_port' => $initiator->lanPort,
            'created_at' => Instant::zulu($now),
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
            ->where('expires_at', '>', Instant::zulu($now))
            ->first();

        if ($row === null || ! PairingRowGuards::tokenHashMatches($row, $tokenHash)) {
            return false;
        }

        $acceptedAt = Instant::zulu($now);
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
                'expires_at' => Instant::zulu($newExpiry),
            ]);

        $accepted = $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->first();

        return $accepted ?? false;
    }

    private function extendedExpiry(\stdClass $row, CarbonImmutable $now): CarbonImmutable
    {
        return $this->window->extendedFrom($row->expires_at, $now);
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
        $stamped = $this->localConfirm->record($tokenId, $userId, $confirmingDeviceId, $expectedSafetyDigest);

        return $stamped === null ? null : $this->stateAfterAnyHeldPeerConfirm($stamped, $userId)->value;
    }

    // The tap is what makes a held peer confirm actionable, so this is where it
    // gets its second chance. Without it the ceremony finished on the peer and
    // never here: a peer that reaches `confirmed` stops re-emitting, and the
    // frame it sent while this side was still comparing was answered and gone.
    /**
     * @link ../../../../.docs/features/sync/pairing-handshake.md#a-deferred-confirm-is-held-not-dropped
     */
    private function stateAfterAnyHeldPeerConfirm(\stdClass $stamped, int $userId): PairingState
    {
        $held = $this->heldPeerConfirm->on($stamped);
        $tokenHash = is_string($stamped->token_hash) ? $stamped->token_hash : '';

        // Replayed through the whole gate sequence, never trusted for having
        // been stored: a responder that rebound in between signed for keys this
        // row no longer binds, and the verify fails exactly as it would inbound.
        $replayed = $held === null || $tokenHash === '' ? null : $this->applyPeerConfirm(
            $userId,
            $tokenHash,
            $held['confirming_device_id'],
            $held['peer_device_id'],
            $held['sig_hex'],
        );

        return $replayed?->stateApplied() ?? $this->finalizeIfBothConfirmed($stamped, $userId);
    }

    // Applies a relayed, Ed25519-SIGNED PAIR_CONFIRM frame from the bound
    // peer identity — the anti-forgery gate the whole cross-device
    // propagation rests on (full gate sequence documented at @link,
    // including the load-bearing local-human-confirmed-first gate).
    public function applyPeerConfirm(
        int $userId,
        string $tokenHash,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): ?PeerConfirmResult {
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
            $context->localConfirmedAt === null => $this->holdUntilLocalHumanConfirms($context, $userId, $confirmingDeviceId, $peerDeviceId, $sigHex),
            default => $this->recordPeerConfirmAndFinalize($context, $userId),
        };
    }

    // Held on the row as well as left for redelivery, because only one of the
    // two roads redelivers: the relay keeps its copy, while a LAN push is
    // answered 202 and gone, and the sender stops re-emitting the moment its
    // own side reaches confirmed.
    private function holdUntilLocalHumanConfirms(
        PeerConfirmContext $context,
        int $userId,
        string $confirmingDeviceId,
        string $peerDeviceId,
        string $sigHex,
    ): PeerConfirmResult {
        $this->heldPeerConfirm->hold($context->rowId, $userId, $confirmingDeviceId, $peerDeviceId, $sigHex);

        return PeerConfirmResult::deferred();
    }

    // Stamps the peer's confirmation if it is not already recorded, then
    // re-reads the row so the state decision is made against what is actually
    // persisted rather than against the pre-update copy.
    private function recordPeerConfirmAndFinalize(PeerConfirmContext $context, int $userId): ?PeerConfirmResult
    {
        $peerConfirmedAt = is_string($context->row->{$context->peerConfirmedColumn})
            ? $context->row->{$context->peerConfirmedColumn}
            : null;

        if ($peerConfirmedAt === null) {
            // The held copy is spent the moment its stamp lands: leaving it
            // would keep a signature on the row long after the column it
            // authorises was written.
            $this->db->connection()->table('pairing_tokens')
                ->where('id', $context->rowId)
                ->where('user_id', $userId)
                ->update([
                    $context->peerConfirmedColumn => Instant::zulu($this->clock->now()),
                    HeldPeerConfirm::COLUMN => null,
                ]);
        }

        $freshRow = $this->db->connection()->table('pairing_tokens')
            ->where('id', $context->rowId)
            ->where('user_id', $userId)
            ->first();

        return $freshRow === null
            ? null
            : PeerConfirmResult::applied($this->finalizeIfBothConfirmed($freshRow, $userId));
    }

    // Shared tail of confirm() and applyPeerConfirm(): both reach the exact
    // same admission semantics — bothConfirmed() -> set CONFIRMED ->
    // admitResponderDevice() -> conditional admitInitiatorDevice() (see @link).
    private function finalizeIfBothConfirmed(\stdClass $row, int $userId): PairingState
    {
        $initiatorConfirmedAt = is_string($row->initiator_confirmed_at) ? $row->initiator_confirmed_at : null;
        $responderConfirmedAt = is_string($row->responder_confirmed_at) ? $row->responder_confirmed_at : null;

        if (! $this->stateMachine->bothConfirmed($initiatorConfirmedAt, $responderConfirmedAt)) {
            // tryFrom, never from(): a `state` column this build cannot read
            // must not throw out of the confirm path, and a row still short of
            // one side is awaiting_confirm whatever the column happens to say.
            return (is_string($row->state) ? PairingState::tryFrom($row->state) : null)
                ?? PairingState::AwaitingConfirm;
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

        return PairingState::Confirmed;
    }

    // Gives a ceremony the reader has come back to enough window left to make
    // the comparison, and never shortens one that still has longer to run. The
    // caller proves the session is unlocked by holding a device id at all (see
    // @link for what that widens and what still closes it).
    /**
     * @link ../../../../.docs/features/sync/pairing-handshake.md#a-pairing-outlives-the-lock-that-interrupts-it
     */
    public function extendCeremonyAcrossLock(int $userId, string $selfDeviceId): bool
    {
        // Renewed from two human moments and bounded by neither, a ceremony
        // nobody completed was revived forever — one row from a phone wiped two
        // rounds ago kept hasLiveHandshake() true, and that is what suppressed
        // the daemon credentialling a new code needs (see @link).
        $this->retireCeremoniesPastTheirCeiling($userId, $this->clock->now());

        // No expiry filter: reviving a row whose TTL lapsed while the app sat
        // locked is the whole point. `pending` is excluded because it binds no
        // responder, so there is nothing to compare and a longer window buys
        // only a longer race for the slot.
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('state', PairingState::AwaitingConfirm->value)
            ->orderByDesc('id')
            ->first();

        if ($row === null || PairingRowGuards::sideOwnedBy($row, $selfDeviceId) === null) {
            return false;
        }

        $this->db->connection()->table('pairing_tokens')
            ->where('id', is_numeric($row->id) ? (int) $row->id : 0)
            ->where('user_id', $userId)
            ->where('state', PairingState::AwaitingConfirm->value)
            ->update(['expires_at' => Instant::zulu($this->extendedExpiry($row, $this->clock->now()))]);

        return true;
    }

    public function expire(int $tokenId, int $userId): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->update(['state' => PairingState::Expired->value]);
    }

    // Ends every unfinished ceremony this user has, whatever its id. The modal's
    // cancel could only reach a row it had resumed, and a `pending` one it never
    // resumes — inFlight() excludes that state — so the one row blocking the next
    // attempt was the one row no UI could clear.
    public function expireUnfinished(int $userId): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->whereIn('state', PairingState::inFlightValues())
            ->update(['state' => PairingState::Expired->value]);
    }

    // Read off created_at, never expires_at: expires_at is the column the
    // revival moves, so a ceiling measured against it would move with every
    // renewal and never arrive.
    private function retireCeremoniesPastTheirCeiling(int $userId, CarbonImmutable $now): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->whereIn('state', PairingState::inFlightValues())
            ->where('created_at', '<', Instant::zulu($now->subMinutes(self::CEREMONY_MAX_AGE_MINUTES)))
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
                $query->where('expires_at', '<', Instant::zulu($now))
                    ->orWhereIn('state', [
                        PairingState::Confirmed->value,
                        PairingState::Expired->value,
                    ]);
            })
            ->delete();
    }
}
