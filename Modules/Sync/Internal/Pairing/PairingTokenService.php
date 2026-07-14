<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Identity\DeviceNameDetector;

/**
 * Issues and validates single-use, short-expiry pairing tokens (D-06/D-13).
 *
 * Lifecycle:
 *   issue()   → a 128-bit token is minted, its SHA-256 hash stored (never the
 *               plaintext, T-12-09), state = pending, TTL = +10 minutes (D-13).
 *               The plaintext is returned once, to be shown via QR / word-code.
 *   accept()  → the responder submits the token; a timing-safe, user-scoped,
 *               not-expired, still-pending lookup binds the responder identity,
 *               moves state to awaiting_confirm, and extends the TTL by 5 minutes
 *               so the safety-number comparison window does not race the original
 *               expiry (Pitfall 6). A second accept of the same token returns
 *               false — it is no longer pending (single-use, T-12-10).
 *   confirm() → records the per-side safety-number confirmation (D-07).
 *   expire()  → cancels / cleans up a token.
 *
 * Every query is scoped with where('user_id', $userId) so a user-A token can
 * never be consumed by user B (cross-user isolation, T-12-12).
 *
 * This is the bootstrap channel only — it exchanges identities and gates trust.
 * The authenticated Noise transport is Phase 13; revocation is Phase 14 (D-10).
 */
final class PairingTokenService
{
    private const int TTL_MINUTES = 10;

    private const int ACCEPT_GRACE_MINUTES = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly PairingStateMachine $stateMachine,
        private readonly SafetyNumberDeriver $safetyNumberDeriver,
        private readonly DeviceNameDetector $deviceNameDetector,
    ) {}

    /**
     * Mint a pairing token for the initiator and persist its hash. Returns the
     * plaintext token — displayed once, never stored.
     */
    public function issue(
        int $userId,
        string $initiatorDeviceId,
        string $ed25519PubHex,
        string $x25519PubHex,
    ): string {
        // Reject malformed key material at the trust boundary (WR-01) so the
        // stored *_pub_hex columns are always valid 32-byte hex and the later
        // safety-number derivation can never throw a raw SodiumException.
        SafetyNumberDeriver::hexToRawKey($ed25519PubHex);
        SafetyNumberDeriver::hexToRawKey($x25519PubHex);

        $now = $this->clock->now();

        // Prune stale handshake rows for this user before minting a fresh one
        // (WR-04). pairing_tokens is a transient scratch table — abandoned,
        // expired, or terminal (confirmed/expired) rows are swept here so the
        // table never accumulates stale initiator key material indefinitely.
        $this->prune($userId, $now);

        $token = bin2hex(random_bytes(16)); // 128-bit, 32-char hex.

        $this->db->connection()->table('pairing_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token), // never the plaintext (T-12-09).
            'initiator_device_id' => $initiatorDeviceId,
            'initiator_ed25519_pub_hex' => $ed25519PubHex,
            'initiator_x25519_pub_hex' => $x25519PubHex,
            'state' => PairingStateMachine::PENDING,
            'expires_at' => $now->addMinutes(self::TTL_MINUTES)->toIso8601String(),
            'created_at' => $now->toIso8601String(),
        ]);

        return $token;
    }

    /**
     * Seed a LOCAL `pairing_tokens` row from a scanned QR's initiator
     * identity (Phase 15 import-join, G1) — the cross-device counterpart of
     * `issue()`. `accept()` looks up a PENDING row by `token_hash` on THIS
     * device's own local database; on a genuinely fresh phone that row only
     * ever existed on the desktop that called `issue()`, so `accept()` would
     * always return `false`. This method creates that row locally, using the
     * initiator identity + token the QR itself carried (an out-of-band
     * physically-scanned channel), so the SAME `accept()`/`confirm()` trust
     * boundary every other pairing path uses can proceed unmodified.
     *
     * `initiator_seeded_at` is set (unlike a plain `issue()` row, where it
     * stays NULL) — this is the marker `confirm()`'s `admitInitiatorDevice()`
     * branch below gates on, so this new cross-device admission path can
     * never fire for a normal same-database `issue()`-created row (every
     * existing single-database pairing test depends on that).
     *
     * Idempotent: a pending row already seeded for this EXACT token_hash +
     * user is returned as-is rather than duplicated — a re-rendered QR poll
     * or a retried scan of the same physical code must not create two rows.
     *
     * @return object|false The seeded (or already-seeded) row, or `false`
     *                      when the initiator's key material is malformed
     *                      (WR-01 — same posture as `issue()`/`accept()`).
     */
    public function seedFromInitiator(
        int $userId,
        string $initiatorDeviceId,
        string $initiatorEd25519PubHex,
        string $initiatorX25519PubHex,
        string $token,
    ): object|false {
        try {
            SafetyNumberDeriver::hexToRawKey($initiatorEd25519PubHex);
            SafetyNumberDeriver::hexToRawKey($initiatorX25519PubHex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $now = $this->clock->now();

        // WR-04: prune stale handshake rows for this user before seeding a
        // fresh one — mirrors issue()'s own pruning discipline.
        $this->prune($userId, $now);

        $tokenHash = hash('sha256', $token);

        $existing = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->where('state', PairingStateMachine::PENDING)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->db->connection()->table('pairing_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $tokenHash, // never the plaintext (T-12-09).
            'initiator_device_id' => $initiatorDeviceId,
            'initiator_ed25519_pub_hex' => $initiatorEd25519PubHex,
            'initiator_x25519_pub_hex' => $initiatorX25519PubHex,
            'state' => PairingStateMachine::PENDING,
            'expires_at' => $now->addMinutes(self::TTL_MINUTES)->toIso8601String(),
            'initiator_seeded_at' => $now->toIso8601String(),
            'created_at' => $now->toIso8601String(),
        ]);

        $seeded = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->first();

        return $seeded ?? false;
    }

    /**
     * Validate a submitted token and bind the responder identity.
     *
     * Returns the updated token row on success, or false when the token is
     * unknown, already used, expired, or belongs to another user.
     */
    public function accept(
        string $submittedToken,
        int $userId,
        string $responderDeviceId,
        string $responderEd25519PubHex,
        string $responderX25519PubHex,
    ): object|false {
        // The responder keys are attacker-controllable — validate them at the
        // trust boundary (WR-01) so a non-hex / wrong-length key is rejected as
        // a clean "invalid code" rather than persisted and exploded on later
        // safety-number derivation. Treated as a handled accept-failure (false),
        // matching the caller's existing invalid-code flash.
        try {
            SafetyNumberDeriver::hexToRawKey($responderEd25519PubHex);
            SafetyNumberDeriver::hexToRawKey($responderX25519PubHex);
        } catch (InvalidPublicKeyException) {
            return false;
        }

        $tokenHash = hash('sha256', $submittedToken);
        $now = $this->clock->now();

        // IN-01: expires_at is a TEXT column compared LEXICALLY here. This is
        // correct ONLY because every expires_at is written via toIso8601String()
        // in UTC — identical fixed-width offset, so lexical order == chronological
        // order. Any code that writes a mixed-offset (e.g. +02:00) value here
        // would break this comparison; keep all writes UTC.
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('token_hash', $tokenHash)
            ->where('user_id', $userId)
            ->where('state', PairingStateMachine::PENDING)
            ->where('expires_at', '>', $now->toIso8601String())
            ->first();

        if ($row === null) {
            return false;
        }

        // Timing-safe comparison (ElectronUpdateChannel discipline, T-12-13).
        if (! is_string($row->token_hash) || ! hash_equals($row->token_hash, $tokenHash)) {
            return false;
        }

        $acceptedAt = $now->toIso8601String();

        // Extend the window so the safety-number comparison does not race the
        // original TTL (Pitfall 6). CR-02: a "grace extension" must only ever
        // GROW the lifetime — take max(existing expiry, now + grace floor) so
        // an early accept (the common case) never shortens the live handshake.
        $graceExpiry = $now->addMinutes(self::ACCEPT_GRACE_MINUTES);
        $existingExpiry = is_string($row->expires_at)
            ? CarbonImmutable::parse($row->expires_at)
            : $graceExpiry;
        $newExpiry = $graceExpiry->greaterThan($existingExpiry) ? $graceExpiry : $existingExpiry;

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->update([
                'responder_device_id' => $responderDeviceId,
                'responder_ed25519_pub_hex' => $responderEd25519PubHex,
                'responder_x25519_pub_hex' => $responderX25519PubHex,
                'state' => PairingStateMachine::AWAITING_CONFIRM,
                'accepted_at' => $acceptedAt,
                'expires_at' => $newExpiry->toIso8601String(),
            ]);

        $accepted = $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->first();

        return $accepted ?? false;
    }

    /**
     * Record a per-side safety-number confirmation (D-07).
     *
     * The confirming side is NOT trusted from a client-supplied string — it is
     * derived from the caller's OWN device id ($confirmingDeviceId, read from the
     * unlocked local key-file) by matching it against the token's
     * initiator_device_id / responder_device_id. A device can therefore only
     * ever confirm the side it actually owns; a single device cannot drive both
     * columns and self-admit (CR-01). When the device id matches neither side,
     * the confirmation is rejected (no column is written).
     *
     * Once BOTH sides have confirmed, the token transitions to CONFIRMED and the
     * responder device is admitted to the trusted device_registry with
     * confirmed_at set — the moment its Ed25519 key joins the OpLogReplayer's
     * deviceKeys map (the both-screen trust gate, D-07/T-12-11). This write is
     * the single point where an unconfirmed device becomes trusted, so it is
     * guarded by bothConfirmed() rather than performed on the first confirm.
     */
    /**
     * Returns the resulting pairing state so the caller never re-reads the row
     * and re-derives bothConfirmed() — the service is the single source of truth
     * for the trust decision (IN-04). Returns null when the caller is not a
     * participant of this token (token missing, or the device owns neither
     * side); in that case nothing is confirmed.
     */
    public function confirm(int $tokenId, int $userId, string $confirmingDeviceId): ?string
    {
        $token = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['initiator_device_id', 'responder_device_id']);

        if ($token === null) {
            return null;
        }

        $initiatorDeviceId = is_string($token->initiator_device_id) ? $token->initiator_device_id : null;
        $responderDeviceId = is_string($token->responder_device_id) ? $token->responder_device_id : null;

        // Derive the side from the caller's actual device identity (CR-01).
        // hash_equals keeps the comparison timing-safe and resistant to a
        // crafted device id that merely shares a prefix.
        if ($initiatorDeviceId !== null && hash_equals($initiatorDeviceId, $confirmingDeviceId)) {
            $column = 'initiator_confirmed_at';
        } elseif ($responderDeviceId !== null && hash_equals($responderDeviceId, $confirmingDeviceId)) {
            $column = 'responder_confirmed_at';
        } else {
            // The caller owns neither side of this token — never confirm.
            return null;
        }

        $now = $this->clock->now()->toIso8601String();

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->update([$column => $now]);

        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return null;
        }

        $initiatorConfirmedAt = is_string($row->initiator_confirmed_at) ? $row->initiator_confirmed_at : null;
        $responderConfirmedAt = is_string($row->responder_confirmed_at) ? $row->responder_confirmed_at : null;

        if (! $this->stateMachine->bothConfirmed($initiatorConfirmedAt, $responderConfirmedAt)) {
            // This side confirmed; the peer has not yet. Return the unchanged
            // intermediate state (awaiting_confirm) — not yet trusted.
            return is_string($row->state) ? $row->state : PairingStateMachine::AWAITING_CONFIRM;
        }

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->update(['state' => PairingStateMachine::CONFIRMED]);

        // Unconditional, unchanged (every existing desktop-side pairing test
        // depends on this) — the responder is admitted into WHICHEVER local
        // registry observes bothConfirmed(), same as before Phase 15.
        $this->admitResponderDevice($row, $userId);

        // Phase 15 import-join (G2): ALSO admit the initiator into the
        // local registry — but ONLY for a row that was seeded from a
        // genuinely scanned QR (`initiator_seeded_at IS NOT NULL`, set
        // exclusively by seedFromInitiator()). This makes admission
        // SYMMETRIC for real cross-device pairing (each device admits the
        // peer it does not own) WITHOUT changing behavior for any
        // `issue()`-created row — every existing single-database test
        // (PairingFlowTest, PairingTrustGateTest, MobilePairingScanTest)
        // passes a placeholder initiator_device_id that is never a real
        // device and must never become a phantom device_registry row.
        if (is_string($row->initiator_seeded_at)) {
            $this->admitInitiatorDevice($row, $userId);
        }

        return PairingStateMachine::CONFIRMED;
    }

    /**
     * Admit the responder device to the trusted registry with confirmed_at set.
     * Idempotent: a re-confirm updates the existing (user_id, device_id) row
     * rather than inserting a duplicate (the composite key is UNIQUE).
     */
    private function admitResponderDevice(\stdClass $row, int $userId): void
    {
        $deviceId = is_string($row->responder_device_id) ? $row->responder_device_id : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;
        $responderKx = is_string($row->responder_x25519_pub_hex) ? $row->responder_x25519_pub_hex : null;
        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;

        if ($deviceId === null || $responderEd === null || $responderKx === null || $initiatorEd === null) {
            return;
        }

        // WR-05: never admit a responder whose device_id collides with this
        // user's own self device. A crafted responder_device_id equal to the
        // self-row id would otherwise overwrite the local identity's public keys.
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (is_string($selfDeviceId) && hash_equals($selfDeviceId, $deviceId)) {
            return;
        }

        $safetyWords = implode(' ', $this->safetyNumberDeriver->deriveWords($initiatorEd, $responderEd));
        $now = $this->clock->now()->toIso8601String();

        $registry = $this->db->connection()->table('device_registry');

        // Scope the lookup/update to NON-self rows (WR-05) — defense-in-depth so
        // an admit can never mutate the local self-row even if the collision
        // guard above is ever bypassed.
        $existing = $registry
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('is_self', 0)
            ->first();

        if ($existing !== null) {
            $this->db->connection()->table('device_registry')
                ->where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->where('is_self', 0)
                ->update([
                    'ed25519_public_key_hex' => $responderEd,
                    'x25519_public_key_hex' => $responderKx,
                    'safety_number_words' => $safetyWords,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $this->deviceNameDetector->detect(),
            'ed25519_public_key_hex' => $responderEd,
            'x25519_public_key_hex' => $responderKx,
            'safety_number_words' => $safetyWords,
            'is_self' => 0,
            'paired_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Admit the initiator device to the trusted registry with confirmed_at
     * set — the Phase 15 import-join (G2) counterpart of
     * {@see admitResponderDevice()}, structurally identical (same WR-05
     * self-collision guard, same safety-number derivation, same
     * insert/update-by-(user_id,device_id,is_self=0) shape). Only ever
     * called from `confirm()`'s `initiator_seeded_at IS NOT NULL` branch —
     * i.e. only for a row seeded from a genuinely scanned QR
     * ({@see seedFromInitiator()}), never for a placeholder
     * `issue()`-created row.
     *
     * Security note: admission still happens ONLY inside the
     * `bothConfirmed()` branch above — never on a first confirm — so a
     * one-sided confirm admits nobody. The admitted keys come from the
     * token row that was seeded from the physically-scanned QR (an
     * out-of-band authenticated channel) + verified by the safety-number
     * both-confirm ceremony (D-07) — no wire-trust is introduced.
     */
    private function admitInitiatorDevice(\stdClass $row, int $userId): void
    {
        $deviceId = is_string($row->initiator_device_id) ? $row->initiator_device_id : null;
        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $initiatorKx = is_string($row->initiator_x25519_pub_hex) ? $row->initiator_x25519_pub_hex : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($deviceId === null || $initiatorEd === null || $initiatorKx === null || $responderEd === null) {
            return;
        }

        // WR-05 symmetric guard: never admit an initiator whose device_id
        // collides with this LOCAL user's own self device.
        $selfDeviceId = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        if (is_string($selfDeviceId) && hash_equals($selfDeviceId, $deviceId)) {
            return;
        }

        $safetyWords = implode(' ', $this->safetyNumberDeriver->deriveWords($initiatorEd, $responderEd));
        $now = $this->clock->now()->toIso8601String();

        $existing = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('is_self', 0)
            ->first();

        if ($existing !== null) {
            $this->db->connection()->table('device_registry')
                ->where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->where('is_self', 0)
                ->update([
                    'ed25519_public_key_hex' => $initiatorEd,
                    'x25519_public_key_hex' => $initiatorKx,
                    'safety_number_words' => $safetyWords,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $this->deviceNameDetector->detect(),
            'ed25519_public_key_hex' => $initiatorEd,
            'x25519_public_key_hex' => $initiatorKx,
            'safety_number_words' => $safetyWords,
            'is_self' => 0,
            'paired_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Cancel / clean up a token (user-tappable cancel or expiry sweep).
     */
    public function expire(int $tokenId, int $userId): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->update(['state' => PairingStateMachine::EXPIRED]);
    }

    /**
     * Delete stale pairing_tokens rows for a user (WR-04): anything past its TTL
     * or already in a terminal state (confirmed/expired). pairing_tokens is a
     * transient scratch table — its permanent trust store is device_registry, so
     * pruning never loses trust state. Called on issue() so a single-user, local
     * app needs no separate scheduled sweep.
     */
    public function prune(int $userId, CarbonImmutable $now): void
    {
        $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->where('expires_at', '<', $now->toIso8601String())
                    ->orWhereIn('state', [
                        PairingStateMachine::CONFIRMED,
                        PairingStateMachine::EXPIRED,
                    ]);
            })
            ->delete();
    }
}
