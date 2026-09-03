<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Exceptions;

use RuntimeException;

// Thrown by the migration that takes ON DELETE CASCADE off every foreign key.
// It edits the stored schema directly, so it verifies its own work and stops
// rather than leaving behind constraints nobody has read back.
final class CascadeRemovalFailedException extends RuntimeException
{
    public static function schemaUnreadable(string $verdict): self
    {
        return new self('Removing the cascade clauses left the schema unreadable: '.$verdict);
    }

    public static function stillCascading(int $tables): self
    {
        return new self("Removing the cascade clauses left {$tables} table(s) still cascading.");
    }

    public static function foreignKeysUnenforced(): self
    {
        return new self('Removing the cascade clauses left foreign keys unenforced.');
    }
}
