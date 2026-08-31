<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The rows this repairs were written before the cast existed, so the fixture
// writes them the only way that still can: straight through the query builder,
// which is also how the demo seeder and every raw fixture reach these columns.
const STORED_DAY_MIGRATION = 'Modules/Core/Database/Migrations/2026_08_29_000010_trim_the_time_off_every_stored_day.php';

function storedDayMigration(): Migration
{
    $migration = require base_path(STORED_DAY_MIGRATION);
    assert($migration instanceof Migration);

    return $migration;
}

function storedDayUser(): User
{
    return User::query()->create([
        'username' => 'stored-day',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function storedDayGoal(User $user, string $name, string $startDate, string $targetDate): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('goals')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'target_minor' => 100_000,
        'target_currency' => 'EUR',
        'start_date' => $startDate,
        'target_date' => $targetDate,
        'status' => 'active',
        'created_at' => '2026-08-29 09:41:00',
        'updated_at' => '2026-08-29 09:41:00',
    ]);
}

/**
 * @return array{0: string|null, 1: string|null}
 */
function storedDayValues(int $goalId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('goals')->where('id', $goalId)->first(['start_date', 'target_date']);

    $start = $row?->start_date ?? null;
    $target = $row?->target_date ?? null;

    return [
        is_string($start) ? $start : null,
        is_string($target) ? $target : null,
    ];
}

it('trims a stored day that carries a time down to the day it names', function (): void {
    $user = storedDayUser();
    $goal = storedDayGoal($user, 'carries a time', '2026-09-16 00:00:00', '2027-03-01 13:45:07');

    expect(storedDayValues($goal))->toBe(['2026-09-16 00:00:00', '2027-03-01 13:45:07']);

    storedDayMigration()->up();

    expect(storedDayValues($goal))->toBe(['2026-09-16', '2027-03-01']);
});

it('changes nothing on a second run', function (): void {
    $user = storedDayUser();
    $goal = storedDayGoal($user, 'already a day', '2026-09-16 00:00:00', '2027-03-01 00:00:00');

    storedDayMigration()->up();
    storedDayMigration()->up();

    expect(storedDayValues($goal))->toBe(['2026-09-16', '2027-03-01']);
});

it('leaves a stored day in a shape it does not recognise exactly as found', function (): void {
    $user = storedDayUser();
    $goal = storedDayGoal($user, 'some other shape', '2026-09-16T00:00:00Z', '2027-03-01');

    storedDayMigration()->up();

    expect(storedDayValues($goal))->toBe(['2026-09-16T00:00:00Z', '2027-03-01']);
});
