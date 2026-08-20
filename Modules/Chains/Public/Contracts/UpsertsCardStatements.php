<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Contracts;

use Modules\Core\Models\User;

interface UpsertsCardStatements
{
    public function upsertForImportRun(int $importRunId, User $user): int;

    // User-scoped backfill, independent of import_run_id — the
    // healing-pass counterpart to upsertForImportRun.
    public function upsertForUser(User $user): int;
}
