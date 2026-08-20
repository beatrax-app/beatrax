<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Thrown by ZipExtractor when the per-run scoped extraction directory
// cannot be created on disk — a local filesystem/permission failure,
// distinct from the UnrecognizedMigrationFileException raised for a
// malformed or hostile archive.
final class ExtractionDirectoryException extends RuntimeException
{
    public function __construct(string $targetDir)
    {
        parent::__construct("could not create scoped extraction directory '{$targetDir}'");
    }
}
