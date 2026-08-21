<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;

// The country a reader already chose scopes their government and bank-fee
// classification. An add-and-forget rename would silently reclassify every
// import that followed it, so the carry-over is asserted, not assumed.

function countryRenameMigration(): object
{
    return require base_path(
        'Modules/Core/Database/Migrations/2026_08_21_000010_rename_tax_country_code_to_country_code_on_users.php'
    );
}

it('carries an existing tax_country_code onto the renamed column', function (): void {
    $migration = countryRenameMigration();

    // Back to the shape the column had before this branch, so the row under
    // test is a row that really existed.
    $migration->down();
    expect(Schema::hasColumn('users', 'tax_country_code'))->toBeTrue();
    expect(Schema::hasColumn('users', 'country_code'))->toBeFalse();

    $userId = DB::table('users')->insertGetId([
        'username' => 'country-rename-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'tax_country_code' => 'be',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(Schema::hasColumn('users', 'country_code'))->toBeTrue();
    expect(Schema::hasColumn('users', 'tax_country_code'))->toBeFalse();
    expect(DB::table('users')->where('id', $userId)->value('country_code'))->toBe('be');
});

it('leaves a row that never chose a country unset', function (): void {
    $migration = countryRenameMigration();

    $migration->down();

    $userId = DB::table('users')->insertGetId([
        'username' => 'country-rename-unset',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('users')->where('id', $userId)->value('country_code'))->toBeNull();
});

it('hands the carried-over value straight to the preference seam', function (): void {
    $migration = countryRenameMigration();

    $migration->down();

    $userId = DB::table('users')->insertGetId([
        'username' => 'country-rename-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'tax_country_code' => 'de',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(app(UserCountry::class)->current($userId))->toBe('de');
    expect(User::query()->find($userId))->not->toBeNull();
});
