<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the `rule_conditions` table — one row per
 * condition on a `CategorizationRule` parent (D-02).
 *
 * A rule's `combinator` ('all' | 'any') decides how its conditions
 * combine: 'all' requires every condition row to match, 'any' requires
 * at least one.
 *
 * `field` is meaningful only when `value_type = 'string'` (allowed
 * values: `merchant`, `description`, `counterparty`); `amount`/`date`
 * value_types compare against the transaction's canonical
 * settled-amount / posted-date property. `op`, `field`, and
 * `value_type` are all enforced via paired BEFORE INSERT / BEFORE
 * UPDATE triggers on `rule_conditions` — a typo in the action layer
 * fails loud at the DB boundary rather than landing a silently-broken
 * condition.
 *
 * @property int $id
 * @property int $rule_id
 * @property string $field
 * @property string $op
 * @property string $value_type
 * @property string $value
 * @property string|null $value2
 */
final class RuleCondition extends Model
{
    /** @var string|null */
    protected $table = 'rule_conditions';

    /** @var list<string> */
    protected $fillable = [
        'rule_id',
        'field',
        'op',
        'value_type',
        'value',
        'value2',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CategorizationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CategorizationRule::class, 'rule_id');
    }
}
