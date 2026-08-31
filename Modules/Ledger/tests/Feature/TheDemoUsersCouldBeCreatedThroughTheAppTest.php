<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;

uses(RefreshDatabase::class);

// The seeder writes its rows straight through Eloquent, so nothing it creates
// is checked against Username — the rule every account-creating path in the app
// goes through. It shipped `demo-1@beatrax.local`, and `@` is outside the
// pattern: the demo dataset every developer and every device test runs against
// modelled an account the product itself refuses to create. The phone's signup
// screen is where that showed — it rejected the name the desktop was using.
it('creates only usernames the app itself would accept', function (): void {
    $rejected = [];

    foreach (app(DemoUsersSeeder::class)->run() as $key => $user) {
        foreach ([$key, $user->username] as $name) {
            if (! Username::isValid(Username::normalize((string) $name))) {
                $rejected[] = (string) $name;
            }
        }
    }

    expect($rejected)->toBe([], 'demo usernames the app would refuse: '.implode(', ', $rejected));
});

// The keys are how twenty seeders find their user, so a rename that misses one
// hands it null and it seeds nothing — silently, into a dataset whose whole job
// is to look complete.
it('keys every seeded user by the username it stored', function (): void {
    $users = app(DemoUsersSeeder::class)->run();

    foreach ($users as $key => $user) {
        expect($user)->toBeInstanceOf(User::class)
            ->and($user->username)->toBe($key);
    }

    expect(array_keys($users))->toBe(['demo-1', 'demo-2']);
});
