<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Clock;

use Carbon\CarbonImmutable;
use LogicException;

// pairing_tokens.expires_at and relay_mailbox.expires_at are TEXT compared
// lexically in SQL, so every value written and every value compared against has
// to share one zero-padded Zulu format — an offset form sorts by its own local
// hour digits (see @link).
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#expiry-is-compared-as-text
 */
final class ZuluTimestamp
{
    private const string ZULU_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    // Converts AND asserts, in one call: a writer that only asserted could
    // still hand in a local moment, which is how this column filled with
    // +02:00 under a comment promising UTC.
    /**
     * @throws LogicException
     */
    public static function stamp(CarbonImmutable $moment): string
    {
        $stamp = $moment->toIso8601ZuluString();

        if (preg_match(self::ZULU_PATTERN, $stamp) !== 1) {
            throw new LogicException(
                "ZuluTimestamp: '{$stamp}' is not zero-padded UTC Zulu ISO8601. "
                .'The lexical expires_at comparison requires the Zulu form.'
            );
        }

        return $stamp;
    }
}
