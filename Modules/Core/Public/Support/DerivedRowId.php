<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// An id two devices compute rather than mint. Detector output is produced
// independently on each device, so an autoincrement names a different logical
// row on the peer; folding the columns that already identify the row into the
// id makes both devices agree on it without ever exchanging a message.
final class DerivedRowId
{
    private const int HIGH_HALF_HEX_DIGITS = 16;

    /**
     * @param  array<string, int|string|null>  $identity  The columns the table's
     *                                                   idempotency UNIQUE names, in a fixed order — a different
     *                                                   order is a different id, so callers must not vary it.
     */
    public static function for(string $table, array $identity): int
    {
        $payload = json_encode(
            ['table' => $table, 'identity' => $identity],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        // The table name is hashed with the tuple so the same identity in two
        // tables cannot land on the same number, and the digest is folded one
        // nibble at a time because hexdec() returns a float past 2^53.
        $digest = hash('sha256', $payload);
        $value = 0;

        foreach (str_split(substr($digest, 0, self::HIGH_HALF_HEX_DIGITS)) as $nibble) {
            $value = ($value << 4) | (int) hexdec($nibble);
        }

        // Sixty-three bits, not sixty-four: SQLite's INTEGER and PHP's int are
        // both SIGNED, so a set top bit would read back as a negative id.
        // Clearing it costs one bit of collision headroom and keeps the value
        // an ordinary positive integer every int-typed call site can hold.
        return $value & PHP_INT_MAX;
    }
}
