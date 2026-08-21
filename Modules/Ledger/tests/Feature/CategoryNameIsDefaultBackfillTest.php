<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Support\CategoryDisplayName;

function categoryNameIsDefaultMigration(): object
{
    return require base_path(
        'Modules/Ledger/Database/Migrations/2026_08_21_000020_add_name_is_default_to_categories.php'
    );
}

// RefreshDatabase has already migrated, so the pre-migration shape has to be
// put back: no column, and the seeded rows worded in the signup locale.
function categoryNameIsDefaultRewind(string $seededLocale): void
{
    app()->setLocale($seededLocale);
    app(DefaultCategoryTreeSeeder::class)->run();

    foreach (['groceries', 'eating-out', 'cash-withdrawal'] as $slug) {
        $translated = trans('categorization::categories.'.$slug);
        DB::table('categories')->whereNull('user_id')->where('slug', $slug)
            ->update(['name' => is_string($translated) ? $translated : $slug]);
    }

    Schema::table('categories', static function (Blueprint $table): void {
        $table->dropColumn('name_is_default');
    });
}

function categoryNameIsDefaultRow(string $slug): stdClass
{
    $row = DB::table('categories')->whereNull('user_id')->where('slug', $slug)->first();

    return $row instanceof stdClass ? $row : new stdClass;
}

it('marks every seeded global row as still carrying the app default, worded in English', function (): void {
    categoryNameIsDefaultRewind('nl');
    expect(categoryNameIsDefaultRow('groceries')->name)->toBe('Boodschappen');

    categoryNameIsDefaultMigration()->up();

    $row = categoryNameIsDefaultRow('groceries');
    expect($row->name)->toBe('Groceries')
        ->and((int) $row->name_is_default)->toBe(1);

    // The Dutch install that was rewritten still reads Dutch, now per render.
    app()->setLocale('nl');
    expect(CategoryDisplayName::fromRow($row))->toBe('Boodschappen');
});

it('leaves a user-owned category out of the backfill even when it shares a default slug', function (): void {
    categoryNameIsDefaultRewind('en');

    /** @var User $user */
    $user = User::create([
        'username' => 'backfill-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $ownId = DB::table('categories')->insertGetId([
        'user_id' => $user->id, 'name' => 'Mijn boodschappen', 'slug' => 'groceries',
        'kind' => 'expense', 'display_order' => 99,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    categoryNameIsDefaultMigration()->up();

    $own = DB::table('categories')->where('id', $ownId)->first();
    expect($own?->name)->toBe('Mijn boodschappen')
        ->and((int) ($own?->name_is_default ?? 1))->toBe(0);

    app()->setLocale('nl');
    /** @var Category $model */
    $model = Category::withoutGlobalScopes()->findOrFail($ownId);
    expect($model->display_name)->toBe('Mijn boodschappen');
});

it('is safe to run twice, because a second pass has nothing left to add', function (): void {
    categoryNameIsDefaultRewind('nl');

    categoryNameIsDefaultMigration()->up();
    DB::table('categories')->whereNull('user_id')->where('slug', 'groceries')
        ->update(['name' => 'Supermarkt', 'name_is_default' => false]);
    categoryNameIsDefaultMigration()->up();

    $row = categoryNameIsDefaultRow('groceries');
    expect($row->name)->toBe('Supermarkt')
        ->and((int) $row->name_is_default)->toBe(0);
});

// The two states the backfill's "unknown provenance keeps its name" promise is
// actually load-bearing for, neither of which the three cases above reach.

// A slug an older TREE planted and a newer one dropped: it is a global row, so
// whereNull('user_id') selects it, but there is no frozen English to give it.
// It has to keep false and keep its stored wording — one mixed-language row in
// a translated list is the cost of not knowing where its name came from.
it('leaves a global row whose slug the frozen list does not know alone', function (): void {
    categoryNameIsDefaultRewind('nl');

    $strayId = DB::table('categories')->insertGetId([
        'user_id' => null, 'name' => 'Huisdieren', 'slug' => 'legacy-pets',
        'kind' => 'expense', 'display_order' => 98,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    categoryNameIsDefaultMigration()->up();

    $stray = DB::table('categories')->where('id', $strayId)->first();
    expect($stray?->name)->toBe('Huisdieren')
        ->and((int) ($stray?->name_is_default ?? 1))->toBe(0);

    // And it renders verbatim rather than as a raw translation key.
    app()->setLocale('en');
    expect(CategoryDisplayName::fromRow(categoryNameIsDefaultRow('legacy-pets')))->toBe('Huisdieren');
});

// A slug the backfill DID flag, read in a language whose category file has no
// line for it. resolve() must fall back to the stored English — which is
// exactly what the old seeder's own fallback wrote — and never surface
// "categorization::categories.<slug>" on a budget screen.
it('falls back to the stored English when the reader language has no line for the slug', function (): void {
    categoryNameIsDefaultRewind('nl');
    categoryNameIsDefaultMigration()->up();

    $flaggedId = DB::table('categories')->insertGetId([
        'user_id' => null, 'name' => 'Pet supplies', 'slug' => 'untranslated-pets',
        'kind' => 'expense', 'display_order' => 97, 'name_is_default' => true,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    expect(trans('categorization::categories.untranslated-pets'))
        ->toBe('categorization::categories.untranslated-pets');

    app()->setLocale('nl');
    $row = DB::table('categories')->where('id', $flaggedId)->first();

    expect($row)->toBeInstanceOf(stdClass);
    expect(CategoryDisplayName::fromRow($row))->toBe('Pet supplies');
});
