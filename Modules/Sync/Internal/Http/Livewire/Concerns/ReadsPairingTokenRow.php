<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire\Concerns;

use Illuminate\Database\DatabaseManager;

// What the pairing flow reads back off its own in-flight pairing_tokens row
// and nothing else can answer for it: which id the token it just minted
// names. A screen holds the plaintext token and needs the row id every later
// step is keyed on.
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
}
