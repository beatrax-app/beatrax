<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// `field` is meaningful only when `value_type = 'string'`; `amount`/`date`
// value_types compare against the transaction's canonical settled-amount
// / posted-date property. `op`, `field`, and `value_type` are all
// DB-trigger-enforced against an allow-list.
/**
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
