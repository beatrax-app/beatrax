<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The installation cannot be backed up the way this command backs things
// up — a non-sqlite driver, or no configured database path. Separate from
// BackupIoException because retrying will not help and the operator has to
// change configuration rather than free disk space.
final class BackupNotSupportedException extends RuntimeException {}
