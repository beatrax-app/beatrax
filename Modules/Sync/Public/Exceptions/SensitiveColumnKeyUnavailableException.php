<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Exceptions;

use RuntimeException;

// Encryption is on for this user but no epoch key is reachable. Passing the
// plaintext through would put a human-written value in a registered column in
// the clear while the settings screen reports encryption On, and nothing
// afterwards ever re-reads that column to notice.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class SensitiveColumnKeyUnavailableException extends RuntimeException
{
    /**
     * @param  list<string>  $fields
     */
    public static function forColumns(int $userId, string $table, array $fields): self
    {
        return new self(
            "SensitiveColumnCodec: encryption is enabled for user {$userId} but no epoch key is held, so "
            .$table.'.{'.implode(',', $fields).'} cannot be sealed. Refusing to write it in the clear.',
        );
    }
}
