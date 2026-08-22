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
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApplyAutoCategoryStage implements AppliesAutoCategory
{
    use CoercesScalars;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly RuleApplier $ruleApplier,
        private readonly RuleEvaluator $evaluator,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $db,
    ) {}

    // Two methods only so this catch exists: categorisation is a convenience,
    // so a failure inside it degrades to manual, never aborts the import.
    public function apply(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto
    {
        try {
            return $this->categorize($tx, $user);
        } catch (Throwable $e) {
            $this->logger->warning(
                'ApplyAutoCategoryStage swallowed an exception — falling back to manual categorisation.',
                [
                    'user_id' => $user->id,
                    'source_format' => $tx->sourceFormat,
                    'source_row_index' => $tx->sourceRowIndex,
                    ...SafeExceptionContext::describe($e),
                ],
            );

            return AutoCategorizationOutcomeDto::manual($tx);
        }
    }

    private function categorize(CanonicalTransaction $tx, User $user): AutoCategorizationOutcomeDto
    {
        /** @var list<MatchedRule> $matched */
        $matched = $this->ruleEngine->match(RuleMatchInput::fromCanonical($tx), $user);
        $folded = $this->ruleApplier->applyAtImport($matched, $tx);

        // One transaction over the whole bump loop, so a mid-loop exception
        // rolls back every increment for this row rather than leaving earlier
        // rules bumped while the outer catch discards the categorization.
        $categoryRuleId = null;
        $this->db->connection()->transaction(function () use ($matched, $user, &$categoryRuleId): void {
            foreach ($matched as $rule) {
                $this->db->connection()
                    ->table('categorization_rules')
                    ->where('id', $rule->ruleId)
                    ->where('user_id', $user->id)
                    ->update(['hits_count' => new Expression('hits_count + 1')]);

                foreach ($rule->actions as $action) {
                    // Deliberately no break: the LAST category action wins,
                    // matching RuleApplier::applyAtImport's own fold order.
                    if ($action->type === ActionType::Category->value) {
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

        // Merchant memory is a fallback only: it never overrides a category
        // a fired rule set.
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
    }
}
