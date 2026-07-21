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
 * @link ../../../../.docs/features/categorization/architecture.md
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

            // Wrap the hits_count bump loop in a single DB transaction so
            // a mid-loop exception rolls back every increment for this
            // row, rather than leaving earlier rules permanently bumped
            // even though the outer catch discards the categorization.
            $categoryRuleId = null;
            $this->db->connection()->transaction(function () use ($matched, $user, &$categoryRuleId): void {
                foreach ($matched as $rule) {
                    $this->db->connection()
                        ->table('categorization_rules')
                        ->where('id', $rule->ruleId)
                        ->where('user_id', $user->id)
                        ->update(['hits_count' => new Expression('hits_count + 1')]);

                    foreach ($rule->actions as $action) {
                        // Last-writer-wins, mirroring
                        // RuleApplier::applyAtImport's own fold order.
                        if ($action->type === 'category') {
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
            // merchant_memories.
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
