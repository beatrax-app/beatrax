<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Contracts;

use Modules\Categorization\Public\Dto\AutoCategorizationOutcomeDto;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Cross-module by design: ImportPipeline must not import a
// Modules\Categorization\Internal\* class. Implementations MUST be
// side-effect-free on stage failure: a thrown RuleEvaluator returns
// AutoCategorizationOutcomeDto::manual($tx) so the import never aborts.
interface AppliesAutoCategory
{
    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto;
}
