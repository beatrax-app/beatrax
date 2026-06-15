<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

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
        $token = bin2hex(random_bytes(16)); // 128-bit, 32-char hex.

        $now = $this->clock->now();

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
        $tokenHash = hash('sha256', $submittedToken);
        $now = $this->clock->now();

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

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->update([
                'responder_device_id' => $responderDeviceId,
                'responder_ed25519_pub_hex' => $responderEd25519PubHex,
                'responder_x25519_pub_hex' => $responderX25519PubHex,
                'state' => PairingStateMachine::AWAITING_CONFIRM,
                'accepted_at' => $acceptedAt,
                // Extend the window so the safety-number comparison does not race
                // the original TTL (Pitfall 6).
                'expires_at' => $now->addMinutes(self::ACCEPT_GRACE_MINUTES)->toIso8601String(),
            ]);

        $accepted = $this->db->connection()->table('pairing_tokens')
            ->where('id', $row->id)
            ->where('user_id', $userId)
            ->first();

        return $accepted ?? false;
    }

    /**
     * Record a per-side safety-number confirmation (D-07). `$side` is
     * 'initiator' or 'responder'.
     */
    public function confirm(int $tokenId, string $side, int $userId): void
    {
        $column = $side === 'initiator'
            ? 'initiator_confirmed_at'
            : 'responder_confirmed_at';

        $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->update([$column => $this->clock->now()->toIso8601String()]);
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
}
