<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

/*
 * The default category tree is created for every real user, so its names are
 * product copy, not fixtures — a Dutch user's own budget screen listed
 * Groceries, Eating out and Fees & charges in English.
 *
 * The second half is subtler and is what these tests mostly defend: the tree
 * is shared (`user_id => null`) and re-seeded whenever a user is installed.
 * Writing the name on every run would retranslate a second user's categories
 * into whichever locale was active then, and would discard any rename the
 * first user had made.
 */

it('writes the default names in the active language', function (): void {
    app()->setLocale('nl');

    app(DefaultCategoryTreeSeeder::class)->run();

    $name = Category::withoutGlobalScopes()->where('slug', 'groceries')->value('name');

    expect($name)->toBe('Boodschappen');
});

it('does not retranslate an existing tree when the locale changes', function (): void {
    app()->setLocale('nl');
    app(DefaultCategoryTreeSeeder::class)->run();

    // A second install, with a different language active.
    app()->setLocale('de');
    app(DefaultCategoryTreeSeeder::class)->run();

    $name = Category::withoutGlobalScopes()->where('slug', 'groceries')->value('name');

    expect($name)->toBe('Boodschappen');
});

it('keeps a rename the user made', function (): void {
    app()->setLocale('nl');
    app(DefaultCategoryTreeSeeder::class)->run();

    Category::withoutGlobalScopes()->where('slug', 'groceries')->update(['name' => 'Supermarkt']);

    app(DefaultCategoryTreeSeeder::class)->run();

    expect(Category::withoutGlobalScopes()->where('slug', 'groceries')->value('name'))
        ->toBe('Supermarkt');
});

it('still re-asserts structure on every run', function (): void {
    // The name is write-once; ordering and parentage are not, or a tree that
    // gained a level would never pick it up.
    app(DefaultCategoryTreeSeeder::class)->run();

    Category::withoutGlobalScopes()->where('slug', 'groceries')->update(['display_order' => 9999]);

    app(DefaultCategoryTreeSeeder::class)->run();

    expect(Category::withoutGlobalScopes()->where('slug', 'groceries')->value('display_order'))
        ->not->toBe(9999);
});
