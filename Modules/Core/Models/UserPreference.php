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
 * The `$fillable` list extends as feature columns land via additive
 * column-add migrations. `skipped_update_versions` carries the
 * per-user list of release versions the user dismissed via the
 * auto-update banner's "Skip this version" action; the JSON cast
 * decodes the column into a plain `list<string>` so the
 * SystemAlertsBanner can apply the suppression filter without
 * per-row decoding.
 *
 * @property int $id
 * @property int|null $user_id
 * @property array<array-key, mixed>|null $skipped_update_versions
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
        'skipped_update_versions',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skipped_update_versions' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
