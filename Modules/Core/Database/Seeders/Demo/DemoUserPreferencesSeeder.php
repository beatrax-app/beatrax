<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

/**
 * Materialises one `user_preferences` row per demo user — the
 * foundation table the 17-04a migration created. Domain modules add
 * their own preference columns onto this single-row store via
 * additive column-add migrations; the demo seeder lands the baseline
 * row so the user_preferences read path always finds it on a fresh
 * demo install and downstream module preference reads never have to
 * branch on "row may not yet exist".
 *
 * Idempotency: the `(user_id)` UNIQUE on `user_preferences` keys the
 * upsert so a re-run reuses the existing row.
 */
final class DemoUserPreferencesSeeder
{
    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        foreach ($users as $user) {
            UserPreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                [],
            );
        }

        return UserPreference::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }
}
