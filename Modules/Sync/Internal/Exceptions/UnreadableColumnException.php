<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// A sensitive column held ciphertext no epoch in the keyring opens, so the
// backfill has nothing to capture but a blank. Shipping that blank would
// overwrite the readable copy on every peer, which is why capture stops here
// rather than continuing with a value it already knows is wrong.
final class UnreadableColumnException extends RuntimeException
{
    public static function duringBackfill(string $table, string $field, int $userId): self
    {
        return new self(
            "OpLogBackfiller: cannot read {$table}.{$field} for user {$userId} — refusing to capture an unreadable value.",
        );
    }
}
