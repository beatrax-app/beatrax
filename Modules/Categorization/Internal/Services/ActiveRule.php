<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Modules\Categorization\Models\RuleAction;
use Modules\Categorization\Public\Enums\RuleCombinator;
use stdClass;

final readonly class ActiveRule
{
    /**
     * @param  list<stdClass>  $conditions
     * @param  list<RuleAction>  $actions
     */
    public function __construct(
        public int $ruleId,
        public int $priority,
        public RuleCombinator $combinator,
        public array $conditions,
        public array $actions,
    ) {}
}
