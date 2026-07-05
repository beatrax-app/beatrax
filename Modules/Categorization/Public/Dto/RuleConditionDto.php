<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Read-side projection of one `rule_conditions` row (D-02) — one
 * matchable condition inside a `CategorizationRuleDto`'s `conditions`
 * list.
 *
 * `field` is meaningful only when `valueType = 'string'` (one of
 * `merchant`, `description`, `counterparty`); `amount`/`date`
 * `valueType`s compare against the transaction's canonical
 * settled-amount / posted-date property directly and carry a
 * placeholder `field` value (the DB column still requires a valid
 * enum value — see `Modules\Categorization\Models\RuleCondition`).
 *
 * `op` is one of `contains`/`equals`/`starts_with` (string),
 * `>`/`<`/`between`/`equals` (amount), or `before`/`after`/`between`
 * (date) — enforced at write time by the op x value_type validity
 * matrix in `CreateCategorizationRule`/`UpdateCategorizationRule`
 * (Req 1 / D-02 Pitfall 6), not merely by the DB-layer per-column
 * allow-list triggers.
 *
 * `value2` is populated only for the `between` op.
 */
final class RuleConditionDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $field,
        public readonly string $op,
        public readonly string $valueType,
        public readonly string $value,
        public readonly ?string $value2,
    ) {}
}
