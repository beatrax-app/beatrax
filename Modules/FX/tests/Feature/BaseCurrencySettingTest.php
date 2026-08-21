<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Currency;

beforeEach(function (): void {
    // Two currencies, so the picker has options and exists:currencies,code can pass.
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
    Currency::query()->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);
});

it('mounts with baseCurrency hydrated from the user row', function (): void {
    $this->user->update(['base_currency' => 'USD']);

    Livewire::test(SettingsPage::class)
        ->assertSet('baseCurrency', 'USD');
})->group('phase-1-fx');

it('defaults baseCurrency to EUR when user.base_currency is null', function (): void {
    // Straight to the table: Eloquent's $attributes default would put 'EUR' back.
    DB::table('users')->where('id', $this->user->id)->update(['base_currency' => null]);

    Livewire::test(SettingsPage::class)
        ->assertSet('baseCurrency', 'EUR');
})->group('phase-1-fx');

it('persists a valid base_currency code to the users row on save', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh()->base_currency)->toBe('USD');
})->group('phase-1-fx');

it('rejects an invalid base_currency code that is not in the currencies table', function (): void {
    // A well-formed but unseeded code, so exists:currencies,code is what blocks it.
    $component = Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'XXX')
        ->call('save')
        ->assertHasErrors(['baseCurrency']);

    expect($this->user->fresh()->base_currency)->toBe('EUR');
})->group('phase-1-fx');

it('rejects a base_currency code that is too long (size:3)', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'EURO')
        ->call('save')
        ->assertHasErrors(['baseCurrency']);

    expect($this->user->fresh()->base_currency)->toBe('EUR');
})->group('phase-1-fx');

it('shows a validation error message when baseCurrency is invalid', function (): void {
    $component = Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'XXX')
        ->call('save')
        ->assertHasErrors(['baseCurrency']);

    expect($component->errors()->first('baseCurrency'))->toBe('Please choose a currency.');
})->group('phase-1-fx');

it('does not write another user\'s base_currency (V4 access-control)', function (): void {
    $other = User::create([
        'username' => 'other',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);

    Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect($other->fresh()->base_currency)->toBe('EUR');
})->group('phase-1-fx');
