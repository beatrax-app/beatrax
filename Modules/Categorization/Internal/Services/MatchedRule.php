<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Modules\Categorization\Models\RuleAction;

/**
 * @property-read list<RuleAction> $actions
 */
final readonly class MatchedRule
{
    /**
     * @param  list<RuleAction>  $actions
     */
    public function __construct(
        public int $ruleId,
        public int $priority,
        public array $actions,
    ) {}
}
