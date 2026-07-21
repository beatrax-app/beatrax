<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;

// Read paths inside RuleEvaluator + CategorizationRuleQuery use the raw
// query builder with an explicit `where('user_id', ...)` scope so the
// unauthenticated-context fallthrough (CLI/queue/test) cannot leak a
// foreign user's rule; BelongsToUser is the secondary Eloquent guard.
/**
 * @link ../../../.docs/features/categorization/architecture.md
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
