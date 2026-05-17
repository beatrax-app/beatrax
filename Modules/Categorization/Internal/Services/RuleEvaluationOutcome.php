<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

/**
 * Internal-only return shape of RuleEvaluator::evaluate(). Lives under
 * Internal/Services because it is not a Public surface — the
 * Categorization module's Public surface is AutoCategorizationOutcomeDto,
 * which ApplyAutoCategoryStage builds from this internal value.
 *
 * `source` is one of `'rule'`, `'memory'`, or `null` (no candidate).
 * Exactly one of `ruleId` / `memoryId` is non-null when `source` is
 * non-null; both are null when no candidate fired.
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

    public static function rule(int $categoryId, int $ruleId, int $score): self
    {
        return new self(
            categoryId: $categoryId,
            source: 'rule',
            ruleId: $ruleId,
            memoryId: null,
            score: $score,
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
