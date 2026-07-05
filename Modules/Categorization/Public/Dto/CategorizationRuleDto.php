<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of one `categorization_rules` parent row plus
 * its `rule_conditions` / `rule_actions` children (D-01/D-02/D-03).
 *
 * `priority` decides evaluation order (ascending, then `id` — see
 * `RuleEngine::match()`); `combinator` ('all' | 'any') decides how
 * `conditions` combine. Both enums are enforced at the database layer
 * via paired BEFORE INSERT / BEFORE UPDATE triggers.
 *
 * `hitsCount` is denormalised on the rule row and incremented by
 * `ApplyAutoCategoryStage` whenever the rule fires for an incoming
 * transaction at IMPORT time only (never during re-apply — Pitfall 3).
 * It surfaces in the `/rules` table column.
 *
 * `active` lets the user disable a rule without deleting it (UX
 * nicety + an audit trail for past hits) — it is also flipped to
 * `false` by `DeactivateRulesOnReferentDelete` when a referenced
 * category/counterparty is deleted (T-13.4-09). `notes` is a
 * free-form memo also surfaced in the rule-form modal.
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
