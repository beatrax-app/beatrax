<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\ImportConfirmResult;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#confirm-bounded-recorder-and-post-commit-dispatch
 */
interface ConfirmsImports
{
    public function __invoke(int $importRunId, User $user, bool $dispatchChain = true): ImportConfirmResult;
}
