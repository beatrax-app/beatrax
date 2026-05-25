<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the merchant_aliases table — per-user remapping
 * of cryptic raw bank-statement descriptions to friendly names.
 *
 * Aliases are pure presentation-layer rewrites: they are resolved at
 * preview / list render time and never mutate the stored
 * `transactions.description`. Composite UNIQUE (user_id, pattern) at
 * the DB layer blocks duplicate-pattern rows for the same user.
 *
 * `pattern` is the immutable first-seen raw description; the
 * resolver's exact-match query hits this column. `generalized_pattern`
 * is the editable PatternGeneralizer output that drives fuzzy
 * matching across the rest of the user's history. `merged_from`
 * carries bulk-merge provenance after a Settings → Aliases merge
 * action.
 *
 * Cross-user posture: every production read / write path that operates
 * on this table carries an EXPLICIT `where('user_id', $userId)`
 * filter — the resolver, the Settings → Aliases page, the YAML
 * importer, and the bulk-merge action all do so. The `BelongsToUser`
 * global scope is a SECONDARY guard that fires only when an Eloquent
 * query reaches the resolver within an HTTP-bound request; it does
 * not fire under queue workers, console commands, or model factory
 * paths. Defence-in-depth: the explicit filter is the primary guard,
 * and a new query path MUST carry its own `where('user_id', ...)`
 * regardless of what the trait does. The trait stays so that any
 * future Eloquent surface (e.g. an Inertia resource controller) gets
 * the scope wired automatically; the explicit-filter rule still
 * applies on top.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $pattern
 * @property string $generalized_pattern
 * @property string $friendly_name
 * @property array<int, array<string, mixed>>|null $merged_from
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MerchantAlias extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'merchant_aliases';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'pattern',
        'generalized_pattern',
        'friendly_name',
        'merged_from',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'merged_from' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
