<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Public\Enums\TaxCountry;
use Modules\Tax\Public\Http\Livewire\TaxSettingsSection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function taxSettingsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('seedFromCorpus inserts one row per corpus entry with the correct corpus_key', function (): void {
    $user = taxSettingsUser('tax-seed-01');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $count = $writer->seedFromCorpus($user, 'nl');

    expect($count)->toBeGreaterThanOrEqual(3);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $rows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->get();

    expect($rows)->toHaveCount($count);
    foreach ($rows as $row) {
        expect($row->corpus_key)->not->toBeNull();
        expect($row->status)->toBe('active');
    }
});

it('seedFromCorpus is idempotent: seeding the same country twice returns 0 the second time', function (): void {
    $user = taxSettingsUser('tax-seed-02');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $first = $writer->seedFromCorpus($user, 'nl');
    expect($first)->toBeGreaterThan(0);

    $second = $writer->seedFromCorpus($user, 'nl');
    expect($second)->toBe(0);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $totalRows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->count();

    expect($totalRows)->toBe($first);
});

it('seedFromCorpus never overwrites a renamed corpus-key row', function (): void {
    $user = taxSettingsUser('tax-seed-03');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $writer->seedFromCorpus($user, 'nl');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->whereNotNull('corpus_key')
        ->first();

    assert($row !== null);
    $renamedName = 'My Renamed Category';
    $db->connection()->table('tax_deduction_categories')
        ->where('id', $row->id)
        ->update(['name' => $renamedName]);

    $writer->seedFromCorpus($user, 'nl');

    $after = $db->connection()->table('tax_deduction_categories')
        ->where('id', $row->id)
        ->value('name');

    expect($after)->toBe($renamedName);
});

it('seedFromCorpus skips a corpus entry whose name collides with a user-created category', function (): void {
    $user = taxSettingsUser('tax-seed-name-collision');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    // "Giften" is also an NL corpus entry, so this row collides on name.
    $userCatId = $writer->add($user->id, 'Giften');

    $count = $writer->seedFromCorpus($user, 'nl');
    expect($count)->toBeGreaterThan(0);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $giftenRows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('name', 'Giften')
        ->get();

    expect($giftenRows)->toHaveCount(1)
        ->and((int) $giftenRows[0]->id)->toBe($userCatId)
        ->and($giftenRows[0]->corpus_key)->toBeNull();
});

it('switching country with seedFromCorpus adds new entries and deletes nothing (additive)', function (): void {
    $user = taxSettingsUser('tax-seed-04');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $nlCount = $writer->seedFromCorpus($user, 'nl');
    expect($nlCount)->toBeGreaterThan(0);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $deCount = $writer->seedFromCorpus($user, 'de');
    expect($deCount)->toBeGreaterThan(0);

    $nlRows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($nlRows)->toBe($nlCount);

    $deRows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'de')
        ->count();
    expect($deRows)->toBe($deCount);
});

it('add creates a user-owned category with corpus_key null and returns its id', function (): void {
    $user = taxSettingsUser('tax-add-01');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $id = $writer->add($user->id, 'My Custom Category');

    expect($id)->toBeInt()->toBeGreaterThan(0);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('tax_deduction_categories')->where('id', $id)->first();

    expect($row)->not->toBeNull();
    expect($row->name)->toBe('My Custom Category');
    expect($row->corpus_key)->toBeNull();
    expect($row->status)->toBe('active');
});

it('add rejects a duplicate category name for the same user', function (): void {
    $user = taxSettingsUser('tax-add-02');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $writer->add($user->id, 'Duplicate Category');

    expect(fn () => $writer->add($user->id, 'Duplicate Category'))
        ->toThrow(RuntimeException::class);
});

it('rename updates the category name for the owning user', function (): void {
    $user = taxSettingsUser('tax-rename-01');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $id = $writer->add($user->id, 'Original Name');
    $writer->rename($user->id, $id, 'Updated Name');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $name = $db->connection()->table('tax_deduction_categories')->where('id', $id)->value('name');
    expect($name)->toBe('Updated Name');
});

it('rename throws NotFoundHttpException on a cross-user category id', function (): void {
    $owner = taxSettingsUser('tax-rename-owner');
    $intruder = taxSettingsUser('tax-rename-intruder');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $ownerId = $writer->add($owner->id, 'Owner Category');

    expect(fn () => $writer->rename($intruder->id, $ownerId, 'Stolen Name'))
        ->toThrow(NotFoundHttpException::class);
});

it('seeding a second country appends after the first country\'s sort_order block — no interleave', function (): void {
    $user = taxSettingsUser('tax-seed-sort-order');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $nlCount = $writer->seedFromCorpus($user, 'nl');
    $writer->seedFromCorpus($user, 'de');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->orderBy('name')
        ->get(['country_code', 'sort_order']);

    $maxNl = max(array_map(fn ($r) => (int) $r->sort_order, array_filter($rows->all(), fn ($r) => $r->country_code === 'nl')));
    $minDe = min(array_map(fn ($r) => (int) $r->sort_order, array_filter($rows->all(), fn ($r) => $r->country_code === 'de')));

    expect($maxNl)->toBe($nlCount - 1)
        ->and($minDe)->toBeGreaterThan($maxNl);
});

it('rename to an existing name throws a friendly RuntimeException instead of a QueryException', function (): void {
    $user = taxSettingsUser('tax-rename-dup');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $writer->add($user->id, 'Existing Name');
    $renameMe = $writer->add($user->id, 'Old Name');

    expect(fn () => $writer->rename($user->id, $renameMe, 'Existing Name'))
        ->toThrow(RuntimeException::class, 'A category with this name already exists.');
});

it('rename to the SAME name is a no-op, not a duplicate error', function (): void {
    $user = taxSettingsUser('tax-rename-same');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $catId = $writer->add($user->id, 'Stable Name');

    $writer->rename($user->id, $catId, 'Stable Name');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('tax_deduction_categories')
        ->where('id', $catId)->value('name'))->toBe('Stable Name');
});

it('renameCategory surfaces empty/duplicate-name errors inline instead of 500ing', function (): void {
    $user = taxSettingsUser('tax-rename-ui');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);
    $writer->add($user->id, 'Taken Name');
    $catId = $writer->add($user->id, 'Renameable');

    $component = Livewire::actingAs($user)->test(TaxSettingsSection::class);

    $component->call('renameCategory', $catId, '');
    expect($component->get('renameError'))->toBe('Category name cannot be empty.');

    $component->call('renameCategory', $catId, 'Taken Name');
    expect($component->get('renameError'))->toBe('A category with this name already exists.');

    $component->call('renameCategory', $catId, 'Renamed OK');
    expect($component->get('renameError'))->toBe('');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('tax_deduction_categories')
        ->where('id', $catId)->value('name'))->toBe('Renamed OK');
});

it('unarchive restores an archived category to active', function (): void {
    $user = taxSettingsUser('tax-unarchive');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);
    $catId = $writer->add($user->id, 'Resurrect Me');
    $writer->archive($user->id, $catId);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('tax_deduction_categories')
        ->where('id', $catId)->value('status'))->toBe('archived');

    $writer->unarchive($user->id, $catId);

    expect($db->connection()->table('tax_deduction_categories')
        ->where('id', $catId)->value('status'))->toBe('active');
});

it('unarchive throws NotFoundHttpException on a cross-user category id', function (): void {
    $owner = taxSettingsUser('tax-unarchive-owner');
    $intruder = taxSettingsUser('tax-unarchive-intruder');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);
    $catId = $writer->add($owner->id, 'Owner Archived Cat');
    $writer->archive($owner->id, $catId);

    expect(fn () => $writer->unarchive($intruder->id, $catId))
        ->toThrow(NotFoundHttpException::class);
});

it('unarchiveCategory restores via the settings component', function (): void {
    $user = taxSettingsUser('tax-unarchive-ui');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);
    $catId = $writer->add($user->id, 'UI Restore Cat');
    $writer->archive($user->id, $catId);

    Livewire::actingAs($user)->test(TaxSettingsSection::class)
        ->call('unarchiveCategory', $catId);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('tax_deduction_categories')
        ->where('id', $catId)->value('status'))->toBe('active');
});

it('archive sets status to archived for the owning user', function (): void {
    $user = taxSettingsUser('tax-archive-01');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $id = $writer->add($user->id, 'To Archive');
    $writer->archive($user->id, $id);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $status = $db->connection()->table('tax_deduction_categories')->where('id', $id)->value('status');
    expect($status)->toBe('archived');
});

it('archive throws NotFoundHttpException on a cross-user category id', function (): void {
    $owner = taxSettingsUser('tax-archive-owner');
    $intruder = taxSettingsUser('tax-archive-intruder');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $ownerId = $writer->add($owner->id, 'Owner Archive Category');

    expect(fn () => $writer->archive($intruder->id, $ownerId))
        ->toThrow(NotFoundHttpException::class);
});

it('the component mounts and exposes the user tax_country_code', function (): void {
    $user = taxSettingsUser('tax-livewire-01');
    $this->actingAs($user);

    Livewire::test(TaxSettingsSection::class)
        ->assertSet('taxCountryCode', '');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('users')->where('id', $user->id)->update(['tax_country_code' => 'nl']);

    Livewire::test(TaxSettingsSection::class)
        ->assertSet('taxCountryCode', 'nl');
});

it('setTaxCountry seeds categories and persists users.tax_country_code', function (): void {
    $user = taxSettingsUser('tax-livewire-02');
    $this->actingAs($user);

    Livewire::test(TaxSettingsSection::class)
        ->call('setTaxCountry', 'nl')
        ->assertSet('taxCountryCode', 'nl');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $countryCode = $db->connection()->table('users')->where('id', $user->id)->value('tax_country_code');
    expect($countryCode)->toBe('nl');

    $categoryCount = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($categoryCount)->toBeGreaterThan(0);
});

it('setTaxCountry rejects a code outside the allow-list (no-op)', function (): void {
    $user = taxSettingsUser('tax-livewire-03');
    $this->actingAs($user);

    Livewire::test(TaxSettingsSection::class)
        ->call('setTaxCountry', 'xx')
        ->assertSet('taxCountryCode', '');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $countryCode = $db->connection()->table('users')->where('id', $user->id)->value('tax_country_code');
    expect($countryCode)->toBeNull();
});

it('renders the country allow-list even when unauthenticated (no throw at mount)', function (): void {
    // Derived from the enum, not copied out of it: a literal allow-list here
    // would only ever fail on the day someone adds a country.
    $expected = array_map(
        static fn (TaxCountry $case): string => $case->value,
        TaxCountry::cases(),
    );
    sort($expected);

    Livewire::test(TaxSettingsSection::class)
        ->assertOk()
        ->assertViewHas('allowedCountries', $expected);
});

it('settings page blade includes the tax settings section livewire tag', function (): void {
    $content = file_get_contents(
        dirname(__DIR__, 4).'/Modules/Core/Resources/views/livewire/settings-page.blade.php'
    );
    assert(is_string($content));
    expect($content)->toContain("@livewire('tax.settings-section')");
});
