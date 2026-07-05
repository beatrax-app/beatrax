<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Pipeline;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Expression;
use Modules\Categorization\Internal\Services\MatchedRule;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Categorization\Public\Dto\AutoCategorizationOutcomeDto;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronous Import-pipeline stage that runs `RuleEngine::match()` +
 * `RuleApplier::applyAtImport()` against the incoming canonical row +
 * user (D-06, Req 2/3/5), and falls through to `merchant_memories`
 * (via `RuleEvaluator::lookupMemory()`) only when none of the fired
 * rules carried a `category` action (RESEARCH Pattern 4).
 *
 * Placement: ImportPipeline.preview() inserts this stage AFTER
 * ClassifyTransactionType and BEFORE FingerprintStage::classify.
 * Sync placement (no queued posture): every source format
 * (CSV / CAMT / MT940 / PayPal / ICS PDF / email receipts) flows
 * through the same pipeline, so one sync stage covers them all
 * without per-adapter post-persistence wiring.
 *
 * Side-effect-free on stage failure (T-13.4-18): the entire matching +
 * applying + hits_count-bump + memory-fallback flow runs inside one
 * try/catch. If anything throws (rare — a rule matcher/applier bug),
 * the stage logs a warning and returns
 * `AutoCategorizationOutcomeDto::manual($tx)` built from the ORIGINAL,
 * untouched canonical row, so the import is not aborted by a buggy
 * rule. The user sees the row land in the uncategorised bucket and can
 * re-categorise manually.
 *
 * hits_count (Pitfall 3): bumped once per matched rule, regardless of
 * whether that rule's actions included a `category` action — this is
 * import-only; `ReapplyRulesJob` (Plan 07) never touches this counter.
 *
 * Cross-module contract: bound to `AppliesAutoCategory` so
 * ImportPipeline depends on the Public contract (not this Internal
 * class). Mirrors the RecordsStatementSummary / AppliesEnrichments
 * cross-module shape ImportPipeline already uses.
 */
final class ApplyAutoCategoryStage implements AppliesAutoCategory
{
    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly RuleApplier $ruleApplier,
        private readonly RuleEvaluator $evaluator,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $db,
    ) {}

    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto
    {
        try {
            /** @var list<MatchedRule> $matched */
            $matched = $this->ruleEngine->match(RuleMatchInput::fromCanonical($tx), $user);
            $folded = $this->ruleApplier->applyAtImport($matched, $tx);

            // WR-01: wrap the hits_count bump loop in a single DB
            // transaction so a mid-loop exception (e.g. on the 3rd of 4
            // matched rules) rolls back every increment for this row,
            // instead of leaving rules 1-2 permanently bumped even though
            // the outer catch below discards the folded categorization
            // entirely and falls back to AutoCategorizationOutcomeDto::manual().
            $categoryRuleId = null;
            $this->db->connection()->transaction(function () use ($matched, $user, &$categoryRuleId): void {
                foreach ($matched as $rule) {
                    $this->db->connection()
                        ->table('categorization_rules')
                        ->where('id', $rule->ruleId)
                        ->where('user_id', $user->id)
                        ->update(['hits_count' => new Expression('hits_count + 1')]);

                    foreach ($rule->actions as $action) {
                        if ($action->type === 'category') {
                            // Last-writer-wins: a later firing rule's category
                            // action overwrites an earlier one, mirroring
                            // RuleApplier::applyAtImport's own fold order.
                            $categoryRuleId = $rule->ruleId;
                        }
                    }
                }
            });

            if ($categoryRuleId !== null) {
                $provenance = [
                    'source' => 'rule',
                    'rule_id' => $categoryRuleId,
                    'memory_id' => null,
                    'category_id' => $folded->categoryId,
                ];

                return AutoCategorizationOutcomeDto::auto(
                    canonical: $folded->withAutoCategoryProvenance($provenance),
                    provenance: 'rule',
                    ruleId: $categoryRuleId,
                    memoryId: null,
                );
            }

            // No fired rule carried a category action — fall through to
            // merchant_memories exactly as before the multi-condition
            // engine landed (RESEARCH Pattern 4, resolves the D-06
            // memory FLAG).
            $memory = $this->evaluator->lookupMemory($tx, $user->id);
            if ($memory === null) {
                return AutoCategorizationOutcomeDto::manual($folded);
            }

            $memoryCategoryId = self::toInt($memory->category_id);
            $memoryId = self::toInt($memory->id);
            $provenance = [
                'source' => 'memory',
                'rule_id' => null,
                'memory_id' => $memoryId,
                'category_id' => $memoryCategoryId,
            ];

            return AutoCategorizationOutcomeDto::auto(
                canonical: $folded->withCategoryId($memoryCategoryId)->withAutoCategoryProvenance($provenance),
                provenance: 'memory',
                ruleId: null,
                memoryId: $memoryId,
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'ApplyAutoCategoryStage swallowed an exception — falling back to manual categorisation.',
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
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
