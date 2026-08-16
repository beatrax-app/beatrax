<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AutoImportSettingsSection;
use Modules\Core\Models\User;

/*
 * The watched-folder toggle (D-704 / D-718), extracted out of SettingsPage so
 * it could move to Data & Devices whole: it describes where statements enter
 * this install, which is the same question as bank connections and backups,
 * not a preference like theme or currency. These tests moved with it.
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

it('initialises the toggle from the user row on mount', function (): void {
    $this->user->update(['auto_import_drop_folder' => true]);

    Livewire::test(AutoImportSettingsSection::class)
        ->assertSet('enabled', true);
})->group('phase-7');

it('renders the Auto-import section with the locked UI-SPEC copy', function (): void {
    Livewire::test(AutoImportSettingsSection::class)
        ->assertSee('Auto-import')
        ->assertSee('Auto-import from drop folder')
        ->assertSee('every 5 minutes');
})->group('phase-7');

it('persists the toggle to users.auto_import_drop_folder (instant-apply)', function (): void {
    // The handler flips the property explicitly so the Blade checkbox only
    // needs `wire:change`, not `wire:model.live`. One call -> property flips
    // false -> true and the new value is persisted to the users row.
    Livewire::test(AutoImportSettingsSection::class)
        ->assertSet('enabled', false)
        ->call('toggle')
        ->assertSet('enabled', true)
        ->call('toggle')
        ->assertSet('enabled', false);

    expect((bool) $this->user->fresh()->auto_import_drop_folder)->toBeFalse();
})->group('phase-7');

it('persists the on-state after a single toggle', function (): void {
    Livewire::test(AutoImportSettingsSection::class)
        ->assertSet('enabled', false)
        ->call('toggle')
        ->assertSet('enabled', true);

    expect((bool) $this->user->fresh()->auto_import_drop_folder)->toBeTrue();
})->group('phase-7');

it('flips the help text from off to on when the toggle is enabled', function (): void {
    Livewire::test(AutoImportSettingsSection::class)
        ->assertSee('Processed files move to')
        ->set('enabled', true)
        ->assertSee('Drop folder is active.');
})->group('phase-7');

it('names the drop folder after the signed-in user', function (): void {
    // The help text embeds the per-user inbox path, so the component has to
    // carry the id rather than the view reaching for the session.
    Livewire::test(AutoImportSettingsSection::class)
        ->assertSet('userId', (int) $this->user->id)
        ->assertSee('inbox-drop/'.$this->user->id.'/');
})->group('phase-7');
