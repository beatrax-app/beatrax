<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Events\UserInstalled;

uses(RefreshDatabase::class);

// Autoincrement ids start at 1 everywhere, so a joining device seeding its own
// rules collides id-for-id with the ones the peer is about to send. The create
// path ignores the duplicate parent while its child conditions still attach to
// that id, binding them to a rule they were never part of.

function starterDataUser(DatabaseManager $db): int
{
    return (int) $db->connection()->table('users')->insertGetId([
        'username' => 'starter-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('seeds per-user rules for an ordinary install', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = starterDataUser($db);

    app(Dispatcher::class)->dispatch(new UserInstalled($userId));

    expect($db->connection()->table('categorization_rules')->where('user_id', $userId)->exists())->toBeTrue();
});

it('seeds no per-user rules for a device joining an existing account', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = starterDataUser($db);

    app(Dispatcher::class)->dispatch(new UserInstalled($userId, seedsStarterData: false));

    expect($db->connection()->table('categorization_rules')->where('user_id', $userId)->exists())->toBeFalse();
});

it('always seeds the shared category tree, which is not user scoped', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = starterDataUser($db);

    app(Dispatcher::class)->dispatch(new UserInstalled($userId, seedsStarterData: false));

    expect($db->connection()->table('categories')->whereNull('user_id')->exists())->toBeTrue();
});
