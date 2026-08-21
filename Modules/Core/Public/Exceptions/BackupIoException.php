<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The backup file could not be read or written: a missing path, a full
// disk, a short write. Nothing is wrong with the backup itself, so a
// caller may reasonably retry after the cause is dealt with.
final class BackupIoException extends RuntimeException {}
