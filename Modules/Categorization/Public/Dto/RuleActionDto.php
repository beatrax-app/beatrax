<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Read-side projection of one `rule_actions` row (D-03) — one effect
 * a `CategorizationRuleDto` performs when its conditions match.
 *
 * `payload` is the raw type-specific JSON shape (`category_id`,
 * `counterparty_id`, `{text, mode}`, or `{deduction_category_id,
 * year?}` — see `Modules\Categorization\Models\RuleAction`).
 *
 * `categoryPath`/`counterpartyName` are resolved display fields
 * joined at read time by `CategorizationRuleQuery` — populated ONLY
 * for the action `type` they correspond to (`category` /
 * `counterparty`), null otherwise, and also null when the payload's
 * embedded id no longer resolves to a visible row (e.g. the
 * referenced category/counterparty was deleted — the rule itself is
 * deactivated by `DeactivateRulesOnReferentDelete` in that case, but
 * the read side stays defensive regardless).
 *
 * `position` orders this rule's actions' application (last-writer-
 * wins across shared fields — Req 3).
 */
final class RuleActionDto extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly int $position,
        public readonly string $type,
        public readonly array $payload,
        public readonly ?string $categoryPath,
        public readonly ?string $counterpartyName,
    ) {}
}
