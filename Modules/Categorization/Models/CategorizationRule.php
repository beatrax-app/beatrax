<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the categorization_rules table — the parent half
 * of the multi-condition/multi-action rules engine (D-01). A rule's
 * matchable conditions live in `rule_conditions` and its effects live
 * in `rule_actions`; the parent row only carries evaluation metadata:
 * `priority` (evaluation order), `combinator` ('all' | 'any' — how
 * this rule's conditions combine), `active`, `notes`, `hits_count`.
 *
 * The model uses BelongsToUser so global scope queries via Eloquent
 * automatically filter by the authenticated user. Read paths inside
 * RuleEvaluator + CategorizationRuleQuery use the raw query builder
 * with an explicit `where('user_id', ...)` scope so the
 * unauthenticated-context fallthrough (CLI / queue / test contexts
 * with no auth guard bound) cannot leak a foreign user's rule.
 *
 * `combinator` is enforced via paired BEFORE INSERT / BEFORE UPDATE
 * triggers on categorization_rules; a typo in the action layer fails
 * loud at the DB boundary rather than landing a silently-broken rule.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $priority
 * @property bool $active
 * @property string $combinator
 * @property string|null $notes
 * @property int $hits_count
 */
final class CategorizationRule extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'categorization_rules';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'priority',
        'active',
        'combinator',
        'notes',
        'hits_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'priority' => 'integer',
            'hits_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<RuleCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(RuleCondition::class, 'rule_id');
    }

    /**
     * @return HasMany<RuleAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(RuleAction::class, 'rule_id');
    }
}
