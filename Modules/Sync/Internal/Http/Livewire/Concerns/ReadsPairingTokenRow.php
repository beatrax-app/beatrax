<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Public\Enums\PairingSide;

// What the pairing flow reads back off its own in-flight pairing_tokens row
// and nothing else can answer for it: which id the typed token names, and
// whose device sits on the far side. The second keys off the component's own
// $side, which is why these travel with the component rather than apart.
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
    // the initiator column. $side arrives off the wire as a string, so a value
    // that is neither reads as the responder — the same column it always did.
    private function peerDeviceId(\stdClass $row): ?string
    {
        $side = PairingSide::tryFrom($this->side) ?? PairingSide::Responder;
        $peerDeviceId = $row->{$side->peerPrefix().'device_id'};

        return is_string($peerDeviceId) ? $peerDeviceId : null;
    }
}
