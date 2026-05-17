<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Contracts;

use Modules\Categorization\Public\Dto\AutoCategorizationOutcomeDto;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Public Categorization contract — the Import pipeline injects this
 * to delegate auto-categorisation of an incoming canonical row to
 * the Categorization module. The default implementation
 * (`ApplyAutoCategoryStage`) is bound in CategorizationServiceProvider.
 *
 * Cross-module by design: ImportPipeline must not import a
 * `Modules\Categorization\Internal\*` class. Mirrors the same posture
 * the RecordsStatementSummary + AppliesEnrichments contracts use to
 * keep ImportPipeline decoupled from sister-module internals.
 *
 * Implementations MUST be side-effect-free on stage failure: if the
 * underlying RuleEvaluator throws, the contract returns the original
 * canonical row wrapped in `AutoCategorizationOutcomeDto::manual($tx)`
 * so the import is not aborted by a buggy rule.
 */
interface AppliesAutoCategory
{
    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto;
}
