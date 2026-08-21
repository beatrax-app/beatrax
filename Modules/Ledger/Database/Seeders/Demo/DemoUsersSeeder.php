<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Modules\Core\Models\User;

// The identities are obviously developer-marked so nobody mistakes them for
// real rows, and the password is the same literal on every machine.
final class DemoUsersSeeder
{
    /** @var list<array{username: string, password: string, period_start_day: int, is_developer: bool}> */
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

    /** @return array<string, User> */
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
