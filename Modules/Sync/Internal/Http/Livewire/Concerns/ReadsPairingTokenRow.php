<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Pairing\InvalidPublicKeyException;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

// Everything the pairing flow reads back off its own in-flight pairing_tokens
// row: which id it is, whose device sits on the far side, and the safety words
// both parties' bound keys derive to. They key off the component's own
// $pairingTokenId and $side, which is why they travel with it rather than apart.
trait ReadsPairingTokenRow
{
    private function tokenRowId(DatabaseManager $db, int $userId, string $token): int
    {
        $row = $db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('token_hash', hash('sha256', $token))
            ->first(['id']);

        return $row !== null && is_numeric($row->id) ? (int) $row->id : 0;
    }

    // Selects the PEER side's device id from the in-flight token row: an
    // initiator confirms toward the responder column and a responder toward
    // the initiator column. Null until that peer column is a bound string.
    private function peerDeviceId(\stdClass $row): ?string
    {
        $peerDeviceId = match ($this->side) {
            'initiator' => $row->responder_device_id,
            default => $row->initiator_device_id,
        };

        return is_string($peerDeviceId) ? $peerDeviceId : null;
    }

    /**
     * @return list<string>
     */
    private function deriveSafetyWords(
        DatabaseManager $db,
        SafetyNumberDeriver $safetyDeriver,
        int $userId,
    ): array {
        $keyPair = $this->pairingKeyPair($db, $userId);

        if ($keyPair === null) {
            return [];
        }

        // The stored hex is validated at the accept/issue trust boundary,
        // but guard the decode anyway: a malformed key yields the generic
        // invalid-code flash instead of an uncaught SodiumException 500.
        try {
            return $safetyDeriver->deriveWords($keyPair[0], $keyPair[1]);
        } catch (InvalidPublicKeyException) {
            return [];
        }
    }

    // Reads both parties' Ed25519 public-key hex from the in-flight token,
    // returning null when the row or either bound key is absent — collapsing
    // two failure guards so deriveSafetyWords keeps a single fallible decode.
    /**
     * @return array{0: string, 1: string}|null
     */
    private function pairingKeyPair(DatabaseManager $db, int $userId): ?array
    {
        $row = $db->connection()->table('pairing_tokens')
            ->where('id', (int) $this->pairingTokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        if ($row === null) {
            return null;
        }

        $initiatorEd = is_string($row->initiator_ed25519_pub_hex) ? $row->initiator_ed25519_pub_hex : null;
        $responderEd = is_string($row->responder_ed25519_pub_hex) ? $row->responder_ed25519_pub_hex : null;

        if ($initiatorEd === null || $responderEd === null) {
            return null;
        }

        return [$initiatorEd, $responderEd];
    }
}
