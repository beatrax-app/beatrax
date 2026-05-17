<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Pipeline;

use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Categorization\Public\Dto\AutoCategorizationOutcomeDto;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronous Import-pipeline stage that runs RuleEvaluator against
 * the incoming canonical row + user, and (when a candidate fires)
 * stamps `categoryId` + `autoCategoryProvenance` on the canonical
 * row before fingerprinting + persistence.
 *
 * Placement: ImportPipeline.preview() inserts this stage AFTER
 * ClassifyTransactionType and BEFORE FingerprintStage::classify.
 * Sync placement (no queued posture) — see RESEARCH Pattern 4 lines
 * 505-525. The rationale: every source format (CSV / CAMT / MT940 /
 * PayPal / ICS PDF / email receipts) flows through the same pipeline,
 * so a single sync stage covers them all without per-adapter
 * post-persistence wiring.
 *
 * Side-effect-free on stage failure: if RuleEvaluator throws (rare —
 * the evaluator catches its own DB exceptions), the stage logs a
 * warning and returns `AutoCategorizationOutcomeDto::manual($tx)` so
 * the import is not aborted by a buggy rule. The user sees the row
 * land in the uncategorised bucket and can re-categorise manually.
 *
 * Cross-module contract: bound to `AppliesAutoCategory` so
 * ImportPipeline depends on the Public contract (not this Internal
 * class). Mirrors the RecordsStatementSummary / AppliesEnrichments
 * cross-module shape ImportPipeline already uses.
 */
final class ApplyAutoCategoryStage implements AppliesAutoCategory
{
    public function __construct(
        private readonly RuleEvaluator $evaluator,
        private readonly LoggerInterface $logger,
    ) {}

    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto
    {
        try {
            $outcome = $this->evaluator->evaluate($tx, $user);
        } catch (Throwable $e) {
            $this->logger->warning(
                'ApplyAutoCategoryStage swallowed evaluator exception — falling back to manual categorisation.',
                [
                    'user_id' => $user->id,
                    'source_format' => $tx->sourceFormat,
                    'source_row_index' => $tx->sourceRowIndex,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );

            return AutoCategorizationOutcomeDto::manual($tx);
        }

        if ($outcome->categoryId === null || $outcome->source === null) {
            return AutoCategorizationOutcomeDto::manual($tx);
        }

        $provenance = [
            'source' => $outcome->source,
            'rule_id' => $outcome->ruleId,
            'memory_id' => $outcome->memoryId,
            'category_id' => $outcome->categoryId,
        ];

        $canonical = $tx
            ->withCategoryId($outcome->categoryId)
            ->withAutoCategoryProvenance($provenance);

        return AutoCategorizationOutcomeDto::auto(
            canonical: $canonical,
            provenance: $outcome->source,
            ruleId: $outcome->ruleId,
            memoryId: $outcome->memoryId,
        );
    }
}
