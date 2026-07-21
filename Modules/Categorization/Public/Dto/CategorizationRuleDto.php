<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class CategorizationRuleDto extends Data
{
    /**
     * @param  list<RuleConditionDto>  $conditions
     * @param  list<RuleActionDto>  $actions
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $priority,
        public readonly string $combinator,
        public readonly int $hitsCount,
        public readonly bool $active,
        public readonly ?string $notes,
        public readonly DateTimeImmutable $createdAt,
        public readonly array $conditions,
        public readonly array $actions,
    ) {}
}
