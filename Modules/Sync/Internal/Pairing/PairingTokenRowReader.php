<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

// Every read PairingGateway makes against a pairing_tokens row, in one place.
// The ceremony's state lives in that row, so the queries answering "which row,
// which state, whose devices, which words" are one subject — and each of them
// is user-scoped, which is the property that must not vary between them.
final readonly class PairingTokenRowReader
{
    public function __construct(
        private DatabaseManager $db,
        private SafetyNumberDeriver $safetyDeriver,
        private Clock $clock,
    ) {}

    // Null when the id names no row this user owns, or the row carries no
    // bound hash yet.
    public function tokenHash(int $tokenId, int $userId): ?string
    {
        $tokenHash = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->value('token_hash');

        return is_string($tokenHash) && $tokenHash !== '' ? $tokenHash : null;
    }

    public function state(int $tokenId, int $userId): ?string
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['state']);

        return $row !== null && is_string($row->state) ? $row->state : null;
    }

    // Either name may be null on a row written before that column existed.
    /**
     * @return array{initiator: ?string, responder: ?string}
     */
    public function deviceNames(int $pairingTokenId, int $userId): array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', $pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_name', 'responder_name']);

        return [
            'initiator' => $row !== null && is_string($row->initiator_name) ? $row->initiator_name : null,
            'responder' => $row !== null && is_string($row->responder_name) ? $row->responder_name : null,
        ];
    }

    // A ceremony underway, which `pending` counts toward and inFlight() does
    // not: a code has been shown and the peer may be dialling it right now,
    // even though nothing has bound itself to the row yet.
    public function hasLiveHandshake(int $userId): bool
    {
        return $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->whereIn('state', [PairingState::Pending->value, PairingState::AwaitingConfirm->value])
            ->where('expires_at', '>', PairingExpiry::stamp($this->clock->now()))
            ->exists();
    }

    // The live ceremony for this user, newest first.
    /**
     * @return array{id: int, state: string, safety_words: list<string>, token_hash: string, peer_device_id: string, initiator_device_id: string|null, responder_device_id: string|null}|null
     */
    public function inFlight(int $userId): ?array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->whereIn('state', [PairingState::AwaitingConfirm->value, PairingState::Confirmed->value])
            ->where('expires_at', '>', PairingExpiry::stamp($this->clock->now()))
            ->orderByDesc('id')
            ->first(['id', 'state', 'token_hash', 'initiator_device_id', 'responder_device_id']);

        if ($row === null || ! is_numeric($row->id) || ! is_string($row->state)) {
            return null;
        }

        // Derived, not read: reading a `safety_number_words` column here 500'd every
        // GET of the pairing screen — that column is on device_registry, and
        // pairing_tokens has never had one.
        $words = $this->safetyWords((string) $row->id, $userId);

        // token_hash and the device ids ride along so a resumed screen can re-emit its
        // responder accept; without them the one retry that heals a lost frame is
        // disarmed exactly when a frame is most likely to have been lost.
        return [
            'id' => (int) $row->id,
            'state' => $row->state,
            'safety_words' => $words,
            'token_hash' => is_string($row->token_hash) ? $row->token_hash : '',
            'peer_device_id' => is_string($row->initiator_device_id) ? $row->initiator_device_id : '',
            // peer_device_id stays the initiator's; the phone is always the responder.
            'initiator_device_id' => is_string($row->initiator_device_id) ? $row->initiator_device_id : null,
            'responder_device_id' => is_string($row->responder_device_id) ? $row->responder_device_id : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function safetyWords(string $pairingTokenId, int $userId): array
    {
        $row = $this->db->connection()->table('pairing_tokens')
            ->where('id', (int) $pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        $initiatorEd = $row !== null && is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $responderEd = $row !== null && is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($initiatorEd === null || $responderEd === null) {
            return [];
        }

        try {
            return $this->safetyDeriver->deriveWords($initiatorEd, $responderEd);
        } catch (InvalidPublicKeyException) {
            return [];
        }
    }
}
