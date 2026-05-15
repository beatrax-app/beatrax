<?php

declare(strict_types=1);

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
        'email' => 'wessel@example.com',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
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
