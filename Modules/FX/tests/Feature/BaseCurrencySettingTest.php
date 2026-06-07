<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Currency;

/*
 * Feature tests for the base-currency picker in the Settings page.
 *
 * FX-01 / T-04-01 / T-04-02:
 *  - A valid ISO-4217 code persists to users.base_currency.
 *  - An invalid code (or a code not in the currencies table) fails the
 *    `exists:currencies,code` validation — nothing is written to the DB.
 *  - All writes are scoped to the CurrentUser's own row; user_id is never
 *    taken from the request (V4 access-control).
 */

beforeEach(function (): void {
    // Seed at least two currencies so the picker has options and the
    // `exists:currencies,code` rule works correctly.
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
    // Directly nullify without going through Eloquent defaults
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
    // 'XXX' is a well-formed 3-char code but NOT seeded — exists:currencies,code blocks it.
    $component = Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'XXX')
        ->call('save')
        ->assertHasErrors(['baseCurrency']);

    // The users row must be unchanged
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

    // Acting as $this->user — the write must only touch $this->user's row
    Livewire::test(SettingsPage::class)
        ->set('baseCurrency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    // The other user's row must be untouched
    expect($other->fresh()->base_currency)->toBe('EUR');
})->group('phase-1-fx');
