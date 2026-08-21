<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Contracts;

use Modules\Categorization\Public\Dto\AutoCategorizationOutcomeDto;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Cross-module by design: ImportPipeline must not reach into
// Modules\Categorization\Internal. Implementations must be side-effect-free
// on failure — a throwing evaluator returns manual($tx), so no import aborts.
interface AppliesAutoCategory
{
    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto;
}
