<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Exceptions;

use RuntimeException;

// Encryption is on for this user but the key is not held. Falling back to the
// plaintext would store a second form of one merchant inside the UNIQUE index
// that decides whether a statement row is a duplicate, so re-importing that
// statement would double the ledger.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class BlindIndexKeyUnavailableException extends RuntimeException
{
    public static function forUser(int $userId, string $domain): self
    {
        return new self(
            "BlindIndexCodec: encryption is enabled for user {$userId} but the app-lock key is not held, "
            ."so the '{$domain}' blind index cannot be derived. Refusing to write a plaintext matching key.",
        );
    }
}
