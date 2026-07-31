<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// A db:backup phase (data_version read, VACUUM INTO, chmod, integrity
// check, sidecar write) failed in a way that has already recorded its
// critical system_alerts row and printed its console error. It exists only
// so handle() collapses six failure returns into one catch.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class BackupCorruptException extends RuntimeException {}
