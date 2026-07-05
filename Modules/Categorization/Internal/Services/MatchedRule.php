<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Modules\Categorization\Models\RuleAction;

/**
 * One firing result from `RuleEngine::match()` — a rule whose
 * condition set matched the evaluated `RuleMatchInput`, carrying its
 * `priority` (for caller-side auditing/logging; `match()` itself
 * already returns results in priority-then-id order) and its
 * position-ordered `rule_actions` rows for the applier (Plan 05) to
 * execute.
 *
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
