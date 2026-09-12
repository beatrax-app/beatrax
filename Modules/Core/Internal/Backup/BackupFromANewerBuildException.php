<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use RuntimeException;

// Migrations only move forward, so there is no path from this database to a
// shape this build reads. The reader installs a newer build or restores
// something else; nothing this one does will open it.
final class BackupFromANewerBuildException extends RuntimeException
{
    /**
     * @param  list<string>  $unmatched  the migrations this build does not have, named in the log and never to the reader
     */
    public function __construct(string $message, public readonly array $unmatched)
    {
        parent::__construct($message);
    }
}
