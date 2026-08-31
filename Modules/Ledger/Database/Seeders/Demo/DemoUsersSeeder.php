<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;

// The identities are obviously developer-marked so nobody mistakes them for
// real rows, and the password is the same literal on every machine.
final class DemoUsersSeeder
{
    /** @var list<array{username: string, password: string, period_start_day: int, is_developer: bool}> */
    private const USERS = [
        [
            'username' => 'demo-1',
            'password' => 'demo-only',
            'period_start_day' => 1,
            'is_developer' => true,
        ],
        [
            'username' => 'demo-2',
            'password' => 'demo-only',
            'period_start_day' => 25,
            'is_developer' => false,
        ],
    ];

    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    // The reset sweep used to find these with LIKE 'demo-%', which is a
    // destructive query matching any account whose name merely starts that
    // way. The list that creates them is the only honest source for it.
    /** @return list<string> */
    public static function usernames(): array
    {
        return array_column(self::USERS, 'username');
    }

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

            // The event signup fires, so a demo install receives the starter
            // data every real one does: the community corpus and the default
            // rules hang off listeners nothing else here dispatches. Redispatched
            // on a re-seed, exactly as the install command does.
            $this->events->dispatch(new UserInstalled($user->id));

            $users[$row['username']] = $user;
        }

        return $users;
    }
}
