<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

/*
 * Feature tests for the /settings page. Covers the round-trip of
 * `default_currency_view` and `period_start_day` into the users row via
 * the SettingsPage Livewire component, including validation rejection
 * for out-of-bounds values and the unchanged-row guarantee.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('maps the user accounts into the forecasting list carrying each account currency', function (): void {
    app(DatabaseManager::class)->connection()->table('accounts')->insert([
        'user_id' => $this->user->id,
        'name' => 'Main',
        'slug' => 'main-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00MAIN0000000000',
        'default_currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(SettingsPage::class)
        ->assertViewHas('forecastingAccounts', fn (array $accts): bool => count($accts) === 1 && $accts[0]['default_currency'] === 'USD');
});

it('renders the Settings page with the user current preferences pre-filled', function (): void {
    $this->user->update([
        'default_currency_view' => 'original',
        'period_start_day' => 25,
    ]);

    Livewire::test(SettingsPage::class)
        ->assertSet('defaultCurrencyView', 'original')
        ->assertSet('periodStartDay', 25)
        ->assertSee('Settings')
        ->assertSee('Save settings');
})->group('phase-3');

it('persists default_currency_view when changed via the toggle', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'original')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect($this->user->fresh()->default_currency_view)->toBe('original');
})->group('phase-3');

it('persists period_start_day when changed', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh()->period_start_day)->toBe(25);
})->group('phase-3');

it('rejects period_start_day outside 1..28', function (): void {
    $low = Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 0)
        ->call('save')
        ->assertHasErrors(['periodStartDay']);

    expect($low->errors()->first('periodStartDay'))->toBe('Choose a day from 1 to 28.');

    $high = Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 29)
        ->call('save')
        ->assertHasErrors(['periodStartDay']);

    expect($high->errors()->first('periodStartDay'))->toBe('Choose a day from 1 to 28.');

    // Database row stays at the beforeEach default; nothing was persisted.
    expect($this->user->fresh()->period_start_day)->toBe(1);
})->group('phase-3');

it('rejects default_currency_view outside {eur_only, original}', function (): void {
    $component = Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'garbage')
        ->call('save')
        ->assertHasErrors(['defaultCurrencyView']);

    expect($component->errors()->first('defaultCurrencyView'))->toBe('Pick one of the available options.');

    expect($this->user->fresh()->default_currency_view)->toBe('eur_only');
})->group('phase-3');

it('round-trips default_currency_view = original into the user row', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'original')
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->default_currency_view)->toBe('original');
    expect($this->user->period_start_day)->toBe(25);
})->group('phase-3');

// ----------------------------------------------------------------------
// Phase 7 — watched-folder secondary path toggle (D-704 / D-718).
// ----------------------------------------------------------------------

it('initialises autoImportFromDropFolder from the user row on mount', function (): void {
    $this->user->update(['auto_import_drop_folder' => true]);

    Livewire::test(SettingsPage::class)
        ->assertSet('autoImportFromDropFolder', true);
})->group('phase-7');

it('renders the Auto-import section with the locked UI-SPEC copy', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSee('Auto-import')
        ->assertSee('Auto-import from drop folder')
        ->assertSee('every 5 minutes');
})->group('phase-7');

it('persists the toggle to users.auto_import_drop_folder via toggleAutoImport (instant-apply)', function (): void {
    // The handler flips the property explicitly so the Blade
    // checkbox only needs `wire:change`, not `wire:model.live`. One
    // call -> property flips false -> true and the new value is
    // persisted to the users row.
    Livewire::test(SettingsPage::class)
        ->assertSet('autoImportFromDropFolder', false)
        ->call('toggleAutoImport')
        ->assertSet('autoImportFromDropFolder', true)
        ->call('toggleAutoImport')
        ->assertSet('autoImportFromDropFolder', false);

    expect((bool) $this->user->fresh()->auto_import_drop_folder)->toBeFalse();
})->group('phase-7');

it('persists the on-state to users.auto_import_drop_folder after one toggleAutoImport (instant-apply)', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSet('autoImportFromDropFolder', false)
        ->call('toggleAutoImport')
        ->assertSet('autoImportFromDropFolder', true);

    expect((bool) $this->user->fresh()->auto_import_drop_folder)->toBeTrue();
})->group('phase-7');

it('flips the help text from off to on when the toggle is enabled', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSee('Processed files move to')
        ->set('autoImportFromDropFolder', true)
        ->assertSee('Drop folder is active.');
})->group('phase-7');
