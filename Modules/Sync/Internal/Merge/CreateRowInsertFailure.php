<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\QueryException;
use Modules\Sync\Internal\OpLog\QuarantineReason;

// Why the database refused a CreateRow insert. SQLite answers every integrity
// violation with SQLSTATE 23000 — NOT NULL, FOREIGN KEY and UNIQUE alike — so
// the driver message is the only thing separating a row the peer already holds
// from a create that arrived without a column the table requires.
enum CreateRowInsertFailure
{
    case AlreadyPresent;

    case MissingColumn;

    case MissingReference;

    case Unclassified;

    private const string UNIQUE_PROBE = 'UNIQUE constraint failed';

    private const string NOT_NULL_PROBE = 'NOT NULL constraint failed';

    private const string FOREIGN_KEY_PROBE = 'FOREIGN KEY constraint failed';

    public static function classify(QueryException $e): self
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, self::UNIQUE_PROBE) => self::AlreadyPresent,
            str_contains($message, self::NOT_NULL_PROBE) => self::MissingColumn,
            str_contains($message, self::FOREIGN_KEY_PROBE) => self::MissingReference,
            default => self::Unclassified,
        };
    }

    // An unclassified refusal keeps the verdict the whole catch used to give,
    // rather than inventing a reason the quarantine row cannot support.
    public function quarantineReason(): QuarantineReason
    {
        return match ($this) {
            self::MissingColumn => QuarantineReason::IncompleteCreateRow,
            default => QuarantineReason::MissingReference,
        };
    }
}
