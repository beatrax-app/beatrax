<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryStore;
use Modules\Tax\Internal\Enums\TaxCategoryStatus;
use Modules\Tax\Public\Http\Livewire\TaxSettingsSection;

// tax_deduction_categories.status has no CHECK trigger, so the column default
// is its only schema-side anchor: nothing else rejects a status the writer and
// the readers no longer agree on.

function taxVocabUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('inserts a new category under the status the column defaults to', function (): void {
    $user = taxVocabUser('tax-vocab-default');

    /** @var TaxCategoryStore $writer */
    $writer = app(TaxCategoryStore::class);
    $id = $writer->add($user->id, 'Fresh category');

    DB::table('tax_deduction_categories')->insert([
        'user_id' => $user->id,
        'name' => 'Default-status category',
        'short_name' => 'DEF',
        'sort_order' => 99,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    expect(DB::table('tax_deduction_categories')->where('id', $id)->value('status'))
        ->toBe(TaxCategoryStatus::Active->value)
        ->and(DB::table('tax_deduction_categories')->where('name', 'Default-status category')->value('status'))
        ->toBe(TaxCategoryStatus::Active->value);
});

it('moves a category between the two statuses the enum names', function (): void {
    $user = taxVocabUser('tax-vocab-lifecycle');

    /** @var TaxCategoryStore $writer */
    $writer = app(TaxCategoryStore::class);
    $id = $writer->add($user->id, 'Round trip');

    $writer->archive($user->id, $id);
    expect(DB::table('tax_deduction_categories')->where('id', $id)->value('status'))
        ->toBe(TaxCategoryStatus::Archived->value);

    $writer->unarchive($user->id, $id);
    expect(DB::table('tax_deduction_categories')->where('id', $id)->value('status'))
        ->toBe(TaxCategoryStatus::Active->value);
});

it('drops the archived rows from the default listing and keeps them under includeArchived', function (): void {
    $user = taxVocabUser('tax-vocab-listing');

    /** @var TaxCategoryStore $writer */
    $writer = app(TaxCategoryStore::class);
    $writer->add($user->id, 'Still live');
    $archivedId = $writer->add($user->id, 'Put away');
    $writer->archive($user->id, $archivedId);

    expect(array_map(static fn (stdClass $row): string => (string) $row->name, $writer->listForUser($user->id)))
        ->toBe(['Still live'])
        ->and($writer->listForUser($user->id, includeArchived: true))
        ->toHaveCount(2);
});

// The settings partial splits the same list into two sections by the same two
// values, so a spelling that drifted there would empty one column of the page
// while the rows stayed in the database.
it('splits the settings section into its two status groups', function (): void {
    $user = taxVocabUser('tax-vocab-section');

    /** @var TaxCategoryStore $writer */
    $writer = app(TaxCategoryStore::class);
    $writer->add($user->id, 'Section live');
    $archivedId = $writer->add($user->id, 'Section archived');
    $writer->archive($user->id, $archivedId);

    Livewire::actingAs($user)->test(TaxSettingsSection::class)
        ->assertSee('Section live')
        ->assertSee('Section archived');
});
