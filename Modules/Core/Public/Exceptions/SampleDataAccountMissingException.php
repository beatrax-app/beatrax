<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The sample dataset is loaded onto an account that already exists, so an id
// naming none is a caller that has lost track of who it is seeding for — not a
// state the reader can be shown or the seeder can recover from.
final class SampleDataAccountMissingException extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self("Cannot load sample data for user {$userId}: no such account.");
    }
}
