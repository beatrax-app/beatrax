<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the categorization_rules table — user-defined
 * matchers that pre-categorise an incoming transaction at import
 * time.
 *
 * The model uses BelongsToUser so global scope queries via Eloquent
 * automatically filter by the authenticated user. Read paths inside
 * RuleEvaluator + CategorizationRuleQuery use the raw query builder
 * with an explicit `where('user_id', ...)` scope so the
 * unauthenticated-context fallthrough (CLI / queue / test contexts
 * with no auth guard bound) cannot leak a foreign user's rule.
 *
 * Allowed `field` values: `merchant`, `description`, `counterparty`.
 * Allowed `match` values: `contains`, `equals`, `starts_with`. Both
 * sets are enforced via paired BEFORE INSERT / BEFORE UPDATE
 * triggers on the categorization_rules table; a typo in the action
 * layer fails loud at the DB boundary rather than landing a
 * silently-broken rule.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $field
 * @property string $match
 * @property string $value
 * @property int $category_id
 * @property int $hits_count
 * @property bool $active
 * @property string|null $notes
 */
final class CategorizationRule extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'categorization_rules';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'field',
        'match',
        'value',
        'category_id',
        'hits_count',
        'active',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hits_count' => 'integer',
            'category_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
