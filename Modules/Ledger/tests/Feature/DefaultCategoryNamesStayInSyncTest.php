<?php

declare(strict_types=1);

use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;

// The migration carries its own frozen copy of the 29 slug→name pairs rather
// than importing the seeder's array, and that is right: the backfill has to
// match the wording the rows already on disk came from, not whatever the
// seeder says next year. What was missing is anything asserting the two agree
// TODAY. Reword DefaultCategoryTreeSeeder::TREE and the backfill silently stops
// recognising the row it was written for; add a slug to TREE and the new row
// keeps name_is_default = false and stays frozen in the signup language, which
// is one mixed-language row in an otherwise translated list.
//
// If this test fails because the seeder was deliberately reworded, the answer
// is a NEW migration for the new wording, not an edit to the frozen one.

/**
 * @return array<string, string> slug => English name
 */
function defaultTreeNames(): array
{
    $tree = (new ReflectionClass(DefaultCategoryTreeSeeder::class))->getConstant('TREE');
    expect($tree)->toBeArray();

    $names = [];
    foreach ($tree as $parent) {
        expect($parent)->toBeArray();
        $names[(string) $parent['slug']] = (string) $parent['name'];

        foreach ($parent['children'] ?? [] as $child) {
            $names[(string) $child['slug']] = (string) $child['name'];
        }
    }

    return $names;
}

/**
 * @return array<string, string> slug => English name
 */
function frozenBackfillNames(): array
{
    $migration = require base_path(
        'Modules/Ledger/Database/Migrations/2026_08_21_000020_add_name_is_default_to_categories.php'
    );

    $frozen = (new ReflectionClass($migration))->getConstant('DEFAULT_NAMES');
    expect($frozen)->toBeArray();

    $names = [];
    foreach ($frozen as $slug => $name) {
        $names[(string) $slug] = (string) $name;
    }

    return $names;
}

it('covers every slug the seeder plants, and plants every slug it covers', function (): void {
    $seeded = array_keys(defaultTreeNames());
    $frozen = array_keys(frozenBackfillNames());

    sort($seeded);
    sort($frozen);

    expect($frozen)->toBe($seeded);
});

it('freezes the same English wording the seeder writes', function (): void {
    $seeded = defaultTreeNames();
    $frozen = frozenBackfillNames();

    ksort($seeded);
    ksort($frozen);

    expect($frozen)->toBe($seeded);
});

it('has a translation key for every slug it freezes', function (): void {
    app()->setLocale('nl');

    $untranslated = [];
    foreach (array_keys(frozenBackfillNames()) as $slug) {
        $key = 'categorization::categories.'.$slug;
        if (trans($key) === $key) {
            $untranslated[] = $slug;
        }
    }

    expect($untranslated)->toBe([]);
});
