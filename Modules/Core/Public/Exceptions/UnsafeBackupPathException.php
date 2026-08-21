<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use InvalidArgumentException;

// A destination path carrying a byte that must never reach VACUUM INTO,
// whose target is interpolated literally rather than bound. Extends
// InvalidArgumentException rather than RuntimeException because nothing
// failed: the path was refused before anything was attempted.
final class UnsafeBackupPathException extends InvalidArgumentException {}
