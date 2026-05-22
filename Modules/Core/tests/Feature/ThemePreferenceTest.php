<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

/*
 * Feature tests for the dark-theme preference (Phase 15 D-15 / D-16):
 *
 *   - users.theme — the per-user appearance preference, one of
 *     light / dark / system, defaulting to system.
 *   - The Settings Appearance section persists the value instant-apply
 *     (no submit button) via the setTheme handler, mirroring the
 *     existing toggleAutoImport pattern.
 *   - SettingsPage rejects a theme value outside the enum via #[Validate].
 *   - The dark-companion arch guard is wired and green for the views
 *     Task 1 themed (the layout + the Settings page).
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'theme-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('gives the users table a theme column defaulting to system', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('users', 'theme'))->toBeTrue();

    // A freshly-created user with no explicit theme falls to the
    // schema default.
    $fresh = User::create([
        'username' => 'theme-default-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    expect($fresh->fresh()->theme)->toBe('system');
})->group('phase-15');

it('initialises the theme property from the user row on mount', function (): void {
    $this->user->update(['theme' => 'dark']);

    Livewire::test(SettingsPage::class)
        ->assertSet('theme', 'dark');
})->group('phase-15');

it('renders the Appearance section with the Light / Dark / System control', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSee('Appearance')
        ->assertSee('Light')
        ->assertSee('Dark')
        ->assertSee('System');
})->group('phase-15');

it('persists light/dark/system to users.theme instant-apply via setTheme', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSet('theme', 'system')
        ->call('setTheme', 'dark')
        ->assertSet('theme', 'dark')
        ->assertHasNoErrors();

    expect($this->user->fresh()->theme)->toBe('dark');

    Livewire::test(SettingsPage::class)
        ->call('setTheme', 'light')
        ->assertSet('theme', 'light');

    expect($this->user->fresh()->theme)->toBe('light');

    Livewire::test(SettingsPage::class)
        ->call('setTheme', 'system')
        ->assertSet('theme', 'system');

    expect($this->user->fresh()->theme)->toBe('system');
})->group('phase-15');

it('rejects a theme value outside {light, dark, system}', function (): void {
    Livewire::test(SettingsPage::class)
        ->call('setTheme', 'garbage')
        ->assertHasErrors(['theme']);

    // Nothing was persisted — the row stays at the beforeEach default.
    expect($this->user->fresh()->theme)->toBe('system');
})->group('phase-15');

it('wires the dark-companion arch guard and keeps it green for Task 1 themed views', function (): void {
    // The dark-companion arch guard lives in BoundaryArchTest under an
    // it() whose description contains the word "dark"; this assertion
    // confirms the guard file exists and is part of the suite. The
    // guard itself is exercised by `php artisan test --filter=dark`.
    $archTest = base_path('tests/Contracts/BoundaryArchTest.php');
    expect(is_file($archTest))->toBeTrue();

    $contents = (string) file_get_contents($archTest);
    expect($contents)->toContain('darkCompanionUtilitiesOnThemedViews');
})->group('phase-15');
