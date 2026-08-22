<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;
use Throwable;

// The blind-index key this keyring holds is not valid hex, so nothing can be
// derived under it. Internal, unlike BlindIndexKeyUnavailableException: an
// unlock produces a missing key but never turns invalid hex into a usable one,
// so no caller outside Sync has a different thing to do about it.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class BlindIndexKeyMalformedException extends RuntimeException
{
    public static function forUser(int $userId, string $domain, ?Throwable $previous = null): self
    {
        return new self(
            "BlindIndexCodec: the blind-index key held for user {$userId} is not valid hex, "
            ."so the '{$domain}' blind index cannot be derived.",
            0,
            $previous,
        );
    }
}
