<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Models\User;

function coarUser(string $username, string $locale = 'en'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'locale' => $locale,
    ]);
}

function coarCategory(DatabaseManager $db, ?int $userId, string $name, string $slug): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => $slug,
        'name_is_default' => false,
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reads the category tree once however many pickers ask for it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = coarUser('coar-one');
    coarCategory($db, (int) $user->id, 'Groceries', 'coar-groceries');

    /** @var CategoryOptionsQuery $query */
    $query = app(CategoryOptionsQuery::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    for ($i = 0; $i < 25; $i++) {
        $query->for($user);
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

it('never hands one reader another readers categories', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $alice = coarUser('coar-alice');
    $bob = coarUser('coar-bob');
    coarCategory($db, (int) $alice->id, 'Alice Only', 'coar-alice-only');
    coarCategory($db, (int) $bob->id, 'Bob Only', 'coar-bob-only');

    /** @var CategoryOptionsQuery $query */
    $query = app(CategoryOptionsQuery::class);

    $alicePaths = array_map(static fn ($o): string => $o->path, $query->for($alice));
    $bobPaths = array_map(static fn ($o): string => $o->path, $query->for($bob));

    expect($alicePaths)->toContain('Alice Only')
        ->and($alicePaths)->not->toContain('Bob Only')
        ->and($bobPaths)->toContain('Bob Only')
        ->and($bobPaths)->not->toContain('Alice Only');
});

it('re-resolves default names when the reader language changes', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = coarUser('coar-locale', 'nl');

    $db->connection()->table('categories')->insert([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'name_is_default' => true,
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var CategoryOptionsQuery $query */
    $query = app(CategoryOptionsQuery::class);

    app()->setLocale('en');
    $english = array_map(static fn ($o): string => $o->path, $query->for($user));

    app()->setLocale('nl');
    $dutch = array_map(static fn ($o): string => $o->path, $query->for($user));

    app()->setLocale('en');

    expect($english)->not->toBe($dutch);
});
