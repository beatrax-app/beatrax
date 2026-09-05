<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use Modules\Core\Public\Support\NamesTheCellItRefused;
use Modules\Core\Public\Support\RefusedCell;
use RuntimeException;

// Thrown before any MigrationBatch is yielded, so a corrupt source is rejected
// whole rather than half-imported.
final class UnrecognizedMigrationFileException extends RuntimeException implements NamesTheCellItRefused
{
    public function __construct(string $reason, private readonly ?RefusedCell $cell = null)
    {
        parent::__construct('Unrecognized or corrupt migration source file: '.$reason);
    }

    // The message and the cell are composed together so they cannot drift: the
    // screen shows a fixed line and never either of them, and the message is
    // dropped on the way to the log for quoting the value, so the cell is the
    // only form of this diagnostic that survives the trip.
    public static function cell(string $file, string $column, string $value, string $expectation): self
    {
        return new self(
            sprintf("could not parse %s %s value '%s' (%s)", $file, $column, $value, $expectation),
            new RefusedCell($file, $column, $value),
        );
    }

    public function refusedCell(): ?RefusedCell
    {
        return $this->cell;
    }
}
