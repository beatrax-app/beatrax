<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// `payload` is a type-specific JSON shape: category {category_id},
// counterparty {counterparty_id}, note {text, mode: set|append}, tax_tag
// {deduction_category_id, year?}. `position` orders multi-action
// application; `type` is DB-trigger-enforced against an allow-list.
/**
 * @property int $id
 * @property int $rule_id
 * @property int $position
 * @property string $type
 * @property array<string, mixed> $payload
 */
final class RuleAction extends Model
{
    /** @var string|null */
    protected $table = 'rule_actions';

    /** @var list<string> */
    protected $fillable = [
        'rule_id',
        'position',
        'type',
        'payload',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'payload' => 'array',
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
