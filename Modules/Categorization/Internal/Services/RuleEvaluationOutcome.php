<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

/**
 * Internal-only return shape of RuleEvaluator::evaluate(). Lives under
 * Internal/Services because it is not a Public surface — the
 * Categorization module's Public surface is AutoCategorizationOutcomeDto,
 * which ApplyAutoCategoryStage builds from this internal value.
 *
 * Memory-only since Plan 06 (D-06): `RuleEngine` + `RuleApplier` now
 * own every `categorization_rules` match/apply decision, so `source`
 * is one of `'memory'` or `null` (no candidate) — the former
 * `rule()` static factory / `source === 'rule'` branch is dead code
 * post-migration and has been removed. `ruleId` stays on the shape
 * (always null now) so callers that pattern-match on both
 * `ruleId`/`memoryId` do not need a signature change.
 */
final readonly class RuleEvaluationOutcome
{
    public function __construct(
        public ?int $categoryId,
        public ?string $source,
        public ?int $ruleId,
        public ?int $memoryId,
        public int $score,
    ) {}

    public static function none(): self
    {
        return new self(
            categoryId: null,
            source: null,
            ruleId: null,
            memoryId: null,
            score: 0,
        );
    }

    public static function memory(int $categoryId, int $memoryId, int $score): self
    {
        return new self(
            categoryId: $categoryId,
            source: 'memory',
            ruleId: null,
            memoryId: $memoryId,
            score: $score,
        );
    }
}
