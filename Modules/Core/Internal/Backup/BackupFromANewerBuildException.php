<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

// Migrations only move forward, so there is no path from this database to a
// shape this build reads. The reader installs a newer build or restores
// something else; nothing this one does will open it.
final class BackupFromANewerBuildException extends BackupFromAnotherBuildException {}
