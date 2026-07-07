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
 * The `$fillable` list grows as domain modules ship additive
 * column-add migrations against this table. Each consuming module's
 * column lands in `$fillable` here so the Eloquent mass-assignable
 * surface stays the single canonical write path. Current columns:
 *
 *   - `user_id` — foundation
 *   - `counterparty_index_view` — `/counterparties` index view mode
 *     (`cards` | `list`). Default `cards` materialises at the DB
 *     boundary, so omission on insert yields the canonical default
 *     without an Eloquent assignment.
 *   - `skipped_update_versions` — per-user list of release versions
 *     the user dismissed via the auto-update banner's "Skip this
 *     version" action. JSON-cast to `array` so SystemAlertsBanner
 *     can apply the suppression filter without per-row decoding.
 *   - `calendar_entries_accounts` — JSON array of account IDs whose
 *     recurring entries appear on the /calendar grid. Null = all accounts
 *     (entries all ON per D-03). Resolved at CalendarQuery read time.
 *   - `calendar_balance_accounts` — JSON array of account IDs whose
 *     forecast balances are summed for the calendar balance line. Null =
 *     spendable default (checking + PayPal ON; savings + ICS OFF per D-03).
 *     Resolved at CalendarQuery read time.
 *   - `reports_index_view` — `/reports/library` index view mode
 *     (`cards` | `list`, 999.6-09). Default `cards` materialises at the
 *     DB boundary, same convention as `counterparty_index_view`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $counterparty_index_view
 * @property string $reports_index_view
 * @property array<array-key, mixed>|null $skipped_update_versions
 * @property array<array-key, mixed>|null $calendar_entries_accounts
 * @property array<array-key, mixed>|null $calendar_balance_accounts
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
        'counterparty_index_view',
        'skipped_update_versions',
        'calendar_entries_accounts',
        'calendar_balance_accounts',
        'reports_index_view',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skipped_update_versions' => 'array',
            'calendar_entries_accounts' => 'array',
            'calendar_balance_accounts' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
