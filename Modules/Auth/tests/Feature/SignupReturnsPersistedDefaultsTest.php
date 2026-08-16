<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Internal\Http\Livewire\SettingsPage;

/*
 * Eloquent's create() does not read the row back, so a column filled by a
 * DATABASE default is null on the model it returns while the row itself is
 * correct. SignupAction hands that instance to the guard, and the mobile
 * runtime is persistent — so the stale copy outlived the request that made it
 * and SettingsPage::mount() fatally assigned null to a string-typed property:
 *
 *   Cannot assign null to property SettingsPage::$defaultCurrencyView of type string
 *
 * Reached from the tax page's "set your tax country" call to action, which
 * links straight to /settings.
 */

it('returns a user whose defaulted columns match the row that was written', function (): void {
    $result = app(SignupAction::class)('defaultsuser', 'opensesame-long-enough', false);

    $row = DB::table('users')->where('id', $result['user']->id)->first();

    expect($row->default_currency_view)->not->toBeNull()
        ->and($result['user']->default_currency_view)->toBe($row->default_currency_view)
        ->and($result['user']->period_start_day)->toBe((int) $row->period_start_day);
});

it('opens the settings page for an account straight out of signup', function (): void {
    $result = app(SignupAction::class)('settingsuser', 'opensesame-long-enough', false);

    // The signup instance itself, exactly as the guard holds it — not a copy
    // re-read from the database, which is what made this pass by accident.
    Livewire::actingAs($result['user'])
        ->test(SettingsPage::class)
        ->assertOk()
        ->assertSet('defaultCurrencyView', 'eur_only');
});
