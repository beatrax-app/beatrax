<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

// Where migrations run before the first request this database would be brought
// forward, and where they do not it would be served against code that does not
// match it. That is a difference between shells, so the refusal is not one.
final class BackupFromAnOlderBuildException extends BackupFromAnotherBuildException {}
