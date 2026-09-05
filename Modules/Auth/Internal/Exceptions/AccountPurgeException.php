<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Exceptions;

use RuntimeException;

// The purge could not prove the account is gone, so the transaction has to roll
// back. Its own type because half an erased account is the worst outcome
// available, and a caller must be able to tell that apart from the ordinary
// database faults the sweep already retries past.
final class AccountPurgeException extends RuntimeException
{
    /** @param list<string> $tables */
    public static function tablesBlocked(array $tables, int $userId): self
    {
        return new self('UserScopedDataPurge: could not clear '.implode(', ', $tables).' for user '.$userId.'.');
    }

    /** @param list<string> $survivors */
    public static function dataSurvived(array $survivors, int $userId): self
    {
        return new self('UserScopedDataPurge: data survived the purge of user '.$userId.': '.implode(', ', $survivors).'.');
    }

    // The paths named here are what a paired peer restores the account from, so
    // this is the arm that must reach the reader as "nothing was changed".
    /** @param list<string> $survivors */
    public static function keyMaterialSurvived(array $survivors, int $userId): self
    {
        return new self(
            'UserScopedFilePurge: the account keeps files this device syncs from, for user '
            .$userId.': '.implode(', ', $survivors).'.',
        );
    }
}
