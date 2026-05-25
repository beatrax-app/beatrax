<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Community\Tests\TestCase;
use Modules\Core\Models\User;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Module-local fixture helper for Community feature tests. Returns a
 * freshly-persisted User with sensible defaults so per-test setup
 * blocks stay focused on the assertion under test.
 */
function makeCommunityTestUser(string $username = 'community-user'): User
{
    return User::create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}
