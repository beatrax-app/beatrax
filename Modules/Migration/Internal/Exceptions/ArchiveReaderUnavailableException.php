<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// The archive is well-formed and some build of the app could read it; this one
// cannot. Distinct from UnrecognizedMigrationFileException, which says the file
// itself is wrong — a difference the reader is told about, because only one of
// the two is answered by choosing a different file.
final class ArchiveReaderUnavailableException extends RuntimeException {}
