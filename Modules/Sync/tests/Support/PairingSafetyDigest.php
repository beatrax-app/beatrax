<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;

// The digest a screen would hold after showing the safety number, derived from
// the row a test just built. Production callers pass what they DISPLAYED — that
// difference is the guard — so this exists for tests confirming a legitimate
// pairing, never for application code.
final class PairingSafetyDigest
{
    public static function forToken(int $tokenId, int $userId): string
    {
        $row = app(DatabaseManager::class)->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->where('user_id', $userId)
            ->first(['initiator_ed25519_pub_hex', 'responder_ed25519_pub_hex']);

        if ($row === null
            || ! is_string($row->initiator_ed25519_pub_hex)
            || ! is_string($row->responder_ed25519_pub_hex)) {
            return '';
        }

        return app(SafetyNumberDeriver::class)->digestFor(
            $row->initiator_ed25519_pub_hex,
            $row->responder_ed25519_pub_hex,
        );
    }
}
