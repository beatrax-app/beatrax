<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// A db:restore phase failed after its alert/console error was already
// emitted. $leaveDown carries whether maintenance mode must stay ON: true
// once the live file was touched (copy/post-swap), false when the restore
// was refused before any swap so a healthy app is not locked out.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class RestoreFailedException extends RuntimeException
{
    public function __construct(public readonly bool $leaveDown)
    {
        parent::__construct('db:restore aborted; leaveDown='.($leaveDown ? 'true' : 'false').'.');
    }
}
