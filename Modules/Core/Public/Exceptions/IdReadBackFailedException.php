<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The row was written and the read-back by the columns that identify it found
// nothing. Raised rather than folded into a 0, because a zero primary key rides
// a sync op out to every paired device, where it names whatever row holds it.
/**
 * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
 */
final class IdReadBackFailedException extends RuntimeException
{
    public function __construct(public readonly string $table)
    {
        parent::__construct(
            'The row just written to `'.$table.'` could not be read back by the columns that identify it.',
        );
    }
}
