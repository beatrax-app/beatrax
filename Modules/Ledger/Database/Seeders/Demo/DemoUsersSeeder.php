<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;

/**
 * Idempotent demo-user seeder. Produces a stable pair of demo accounts
 * the rest of the demo seeder graph attaches its synthetic data to.
 *
 * Identity values are obviously developer-marked (`demo-1@beatrax.local`,
 * `demo-2@beatrax.local`) so a contributor inspecting the users table
 * after running `php artisan demo:seed` cannot mistake them for real
 * production rows. The password is the literal string `demo-only`
 * (hashed by the User model's `password => 'hashed'` cast) — the value
 * is documented in CONTRIBUTING patterns and is the same on every
 * machine, which is the point: anyone with the repo checked out can
 * log in to a demo install without consulting a credential store.
 *
 * Idempotency: `updateOrCreate` keyed on `username` ensures a second
 * `php artisan demo:seed` run reuses the same User rows (and therefore
 * the same primary keys) so downstream demo seeders that point at the
 * `users.id` value get the same target on every run.
 */
final class DemoUsersSeeder
{
    /**
     * The literal seed data for both demo users. Kept as a single
     * source so the `run()` loop reads like a checklist and a future
     * "add a third demo user" change is a one-line append.
     *
     * @var list<array{username: string, password: string, period_start_day: int, is_developer: bool}>
     */
    private const USERS = [
        [
            'username' => 'demo-1@beatrax.local',
            'password' => 'demo-only',
            'period_start_day' => 1,
            'is_developer' => true,
        ],
        [
            'username' => 'demo-2@beatrax.local',
            'password' => 'demo-only',
            'period_start_day' => 25,
            'is_developer' => false,
        ],
    ];

    /**
     * Seed both demo users and return them keyed by username so
     * downstream seeders (accounts, transactions, …) can address each
     * one without a second query.
     *
     * @return array<string, User>
     */
    public function run(): array
    {
        $users = [];

        foreach (self::USERS as $row) {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['username' => $row['username']],
                [
                    'password' => $row['password'],
                    'period_start_day' => $row['period_start_day'],
                    'is_developer' => $row['is_developer'],
                ],
            );

            $users[$row['username']] = $user;
        }

        return $users;
    }
}
