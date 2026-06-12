<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Feature tests for Tax settings — country code configuration and
 * deduction category management.
 *
 * Task 2 (writer slice) — Plans 04
 * Task 3 (Livewire section) — implemented in Plan 04 Task 3
 */

// ────────────────────────────────────────────────────────────────────────────
// Helper
// ────────────────────────────────────────────────────────────────────────────

/**
 * Create a minimal user for settings tests (uses Eloquent so RefreshDatabase works).
 */
function taxSettingsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// ────────────────────────────────────────────────────────────────────────────
// seedFromCorpus
// ────────────────────────────────────────────────────────────────────────────

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

    // Total rows must equal the first seed count, not 2x.
    expect($totalRows)->toBe($first);
});

it('seedFromCorpus never overwrites a renamed corpus-key row (Pitfall-4 / T-07-14)', function (): void {
    $user = taxSettingsUser('tax-seed-03');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $writer->seedFromCorpus($user, 'nl');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Simulate the user renaming the first corpus-seeded category.
    $row = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->whereNotNull('corpus_key')
        ->first();

    assert($row !== null);
    $renamedName = 'My Renamed Category';
    $db->connection()->table('tax_deduction_categories')
        ->where('id', $row->id)
        ->update(['name' => $renamedName]);

    // Re-seed the same country.
    $writer->seedFromCorpus($user, 'nl');

    // The user's custom name must still be there.
    $after = $db->connection()->table('tax_deduction_categories')
        ->where('id', $row->id)
        ->value('name');

    expect($after)->toBe($renamedName);
});

it('switching country with seedFromCorpus adds new entries and deletes nothing (additive)', function (): void {
    $user = taxSettingsUser('tax-seed-04');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $nlCount = $writer->seedFromCorpus($user, 'nl');
    expect($nlCount)->toBeGreaterThan(0);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Seed DE on top.
    $deCount = $writer->seedFromCorpus($user, 'de');
    expect($deCount)->toBeGreaterThan(0);

    // NL rows must still exist.
    $nlRows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($nlRows)->toBe($nlCount);

    // DE rows added on top.
    $deRows = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'de')
        ->count();
    expect($deRows)->toBe($deCount);
});

// ────────────────────────────────────────────────────────────────────────────
// add
// ────────────────────────────────────────────────────────────────────────────

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
        ->toThrow(\RuntimeException::class);
});

// ────────────────────────────────────────────────────────────────────────────
// rename
// ────────────────────────────────────────────────────────────────────────────

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

it('rename throws NotFoundHttpException on a cross-user category id (T-07-13)', function (): void {
    $owner = taxSettingsUser('tax-rename-owner');
    $intruder = taxSettingsUser('tax-rename-intruder');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $ownerId = $writer->add($owner->id, 'Owner Category');

    expect(fn () => $writer->rename($intruder->id, $ownerId, 'Stolen Name'))
        ->toThrow(NotFoundHttpException::class);
});

// ────────────────────────────────────────────────────────────────────────────
// archive
// ────────────────────────────────────────────────────────────────────────────

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

it('archive throws NotFoundHttpException on a cross-user category id (T-07-13)', function (): void {
    $owner = taxSettingsUser('tax-archive-owner');
    $intruder = taxSettingsUser('tax-archive-intruder');

    /** @var TaxCategoryWriter $writer */
    $writer = app(TaxCategoryWriter::class);

    $ownerId = $writer->add($owner->id, 'Owner Archive Category');

    expect(fn () => $writer->archive($intruder->id, $ownerId))
        ->toThrow(NotFoundHttpException::class);
});

// ────────────────────────────────────────────────────────────────────────────
// Livewire section tests (Task 3) — stubs to be implemented with TaxSettingsSection
// ────────────────────────────────────────────────────────────────────────────

it('the component mounts and exposes the user tax_country_code', function (): void {
    // TODO: Task 3 — TaxSettingsSection Livewire component
})->skip('built in Plan 04 Task 3');

it('setTaxCountry seeds categories and persists users.tax_country_code', function (): void {
    // TODO: Task 3 — TaxSettingsSection setTaxCountry
})->skip('built in Plan 04 Task 3');

it('setTaxCountry rejects a code outside the allow-list (no-op)', function (): void {
    // TODO: Task 3 — TaxSettingsSection allow-list enforcement
})->skip('built in Plan 04 Task 3');

it('settings page includes the tax settings section', function (): void {
    // TODO: Task 3 — @livewire injection in settings-page.blade.php
})->skip('built in Plan 04 Task 3');
