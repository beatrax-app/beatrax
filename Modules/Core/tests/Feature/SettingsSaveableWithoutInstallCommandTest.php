<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

/*
 * /settings could not be saved at all on a device that joined by pairing.
 *
 * `currencies` was seeded only by beatrax:install, which a paired device never
 * runs, so the "Rapportagevaluta" select rendered with zero options. The empty
 * select synced "" back through wire:model, `exists:currencies,code` rejected
 * it, and save() threw in validate() before reaching $user->save().
 *
 * That validator gates the whole Money/period block, so period start day,
 * currency view, the recurring window and the drift threshold were all
 * unchangeable — measured on a Galaxy: period_start_day stayed 1 across two
 * attempts and users.updated_at never moved.
 */

beforeEach(function (): void {
    $this->settingsUser = User::query()->create([
        'username' => 'settings-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($this->settingsUser);
});

it('has currencies on a device that never ran the install command', function (): void {
    // The migration is the only thing that ran here.
    expect(DB::table('currencies')->count())->toBeGreaterThan(0)
        ->and(DB::table('currencies')->where('code', 'EUR')->exists())->toBeTrue();
});

it('offers at least one currency to pick, so the select is never empty', function (): void {
    $currencies = Livewire::test(SettingsPage::class)->viewData('currencyOptions');

    expect($currencies)->not->toBeEmpty();
});

it('saves the period start day, which the currency validator used to block', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors();

    expect((int) DB::table('users')->where('id', $this->settingsUser->id)->value('period_start_day'))
        ->toBe(25);
});
