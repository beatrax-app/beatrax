<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

// The tree is shared (`user_id => null`) and re-seeded whenever a user is
// installed, so the seeder still writes name and provenance once, at creation.
// What changed is that the stored name is no longer a translation: the row
// says it carries the app default, and the reader's language is applied at
// render, per reader.
function defaultCategory(string $slug): Category
{
    /** @var Category $model */
    $model = Category::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

    return $model;
}

it('stores the untranslated default name and marks the row as carrying it', function (): void {
    app()->setLocale('nl');

    app(DefaultCategoryTreeSeeder::class)->run();

    expect(defaultCategory('groceries')->name)->toBe('Groceries')
        ->and(defaultCategory('groceries')->name_is_default)->toBeTrue();
});

it('names the row in whichever language is active at render', function (): void {
    app()->setLocale('en');
    app(DefaultCategoryTreeSeeder::class)->run();

    app()->setLocale('nl');
    expect(defaultCategory('groceries')->display_name)->toBe('Boodschappen');

    app()->setLocale('de');
    expect(defaultCategory('groceries')->display_name)->toBe('Lebensmittel');
});

it('does not rewrite an existing tree when the locale changes', function (): void {
    app()->setLocale('nl');
    app(DefaultCategoryTreeSeeder::class)->run();

    app()->setLocale('de');
    app(DefaultCategoryTreeSeeder::class)->run();

    expect(defaultCategory('groceries')->name)->toBe('Groceries');
});

it('keeps a rename the user made, and stops translating over it', function (): void {
    app()->setLocale('nl');
    app(DefaultCategoryTreeSeeder::class)->run();

    Category::withoutGlobalScopes()->where('slug', 'groceries')
        ->update(['name' => 'Supermarkt', 'name_is_default' => false]);

    app(DefaultCategoryTreeSeeder::class)->run();

    expect(defaultCategory('groceries')->name)->toBe('Supermarkt')
        ->and(defaultCategory('groceries')->name_is_default)->toBeFalse()
        ->and(defaultCategory('groceries')->display_name)->toBe('Supermarkt');
});

it('still re-asserts structure on every run', function (): void {
    // The name is write-once; ordering and parentage are not, or a tree that
    // gained a level would never pick it up.
    app(DefaultCategoryTreeSeeder::class)->run();

    Category::withoutGlobalScopes()->where('slug', 'groceries')->update(['display_order' => 9999]);

    app(DefaultCategoryTreeSeeder::class)->run();

    expect(defaultCategory('groceries')->display_order)->not->toBe(9999);
});
