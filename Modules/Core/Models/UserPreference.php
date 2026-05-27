<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the `user_preferences` table — one row models the
 * full set of per-user preferences for a single user. The row is the
 * canonical place every domain module reaches for to read or write a
 * user-scoped preference value.
 *
 * Cross-user posture: the `BelongsToUser` trait installs a global
 * scope that filters queries by the authenticated user's id whenever
 * an Eloquent surface reaches this model inside an HTTP-bound
 * request. The unique constraint on user_id at the database boundary
 * makes the one-row-per-user invariant impossible to violate from any
 * call site.
 *
 * The `$fillable` list carries only `user_id`; domain modules that
 * extend the table with additive column-add migrations extend the
 * fillable set inside their own model assignments (preference values
 * are written through Eloquent's mass-assignable surface).
 *
 * @property int $id
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UserPreference extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'user_preferences';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
