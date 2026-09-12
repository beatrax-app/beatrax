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
            sprintf('SensitiveColumnCodec: encryption is enabled for user %s but no epoch key is held, so ', $userId)
            .$table.'.{'.implode(',', $fields).'} cannot be sealed. Refusing to write it in the clear.',
        );
    }

    // The whole recovery pass, refused before it starts rather than one column
    // at a time. A pass with no key records every create it rebuilds as a
    // strategy error instead, and a hold under that reason is answered from
    // every device's ops at the pk rather than the refused author's own create.
    public static function forTheRecoveryPass(int $userId): self
    {
        return new self(
            sprintf('HistoryReprojector: encryption is enabled for user %s but no epoch key is held, so the ', $userId)
            .'quarantined history cannot be re-projected. Refusing to replay it without one.',
        );
    }
}
