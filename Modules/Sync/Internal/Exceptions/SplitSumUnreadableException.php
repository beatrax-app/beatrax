<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;
use Throwable;

// The split-sum gate could not read one of the two amounts it compares. Raised
// rather than folded into a number so the gate never answers "the legs fit"
// from a sum it never computed — a database that cannot be read is not a
// transaction whose legs add up.
final class SplitSumUnreadableException extends RuntimeException
{
    public static function reading(string $table, Throwable $previous): self
    {
        return new self("SplitOverfillGate: cannot read {$table} to compare a split leg against its transaction.", 0, $previous);
    }
}
