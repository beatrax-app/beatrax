<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Random\RandomException;

// An id no second device will land on, for a row no second device could have
// computed. Two devices used while apart both take the next autoincrement and
// hand it to unrelated rows, and a table with no natural key cannot tell those
// two apart afterwards.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class DeviceMintedRowId
{
    // Deliberately NOT DerivedRowId: folding a row's own identity into its id
    // makes two devices agree, which is right for a slot and wrong for an
    // event. Two deposits of one amount on one day are two deposits, and a
    // content-derived id merges them — losing money rather than duplicating.
    /**
     * @throws RandomException
     */
    public static function mint(): int
    {
        return random_int(1, PHP_INT_MAX);
    }
}
