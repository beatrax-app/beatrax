<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

// Materialises one `user_preferences` row per demo user so downstream module
// preference reads never have to branch on "row may not yet exist". The
// `(user_id)` UNIQUE constraint keys the upsert so a re-run reuses the
// existing row rather than duplicating it.
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
