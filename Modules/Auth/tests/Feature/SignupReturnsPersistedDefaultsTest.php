<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Internal\Http\Livewire\SettingsPage;

// create() does not read the row back, so a database-defaulted column is null
// on the returned model. SignupAction hands that instance to the guard, and on
// the persistent mobile runtime the stale copy outlived the request.

it('returns a user whose defaulted columns match the row that was written', function (): void {
    $result = app(SignupAction::class)('defaultsuser', 'opensesame-long-enough', false);

    $row = DB::table('users')->where('id', $result['user']->id)->first();

    expect($row->default_currency_view)->not->toBeNull()
        ->and($result['user']->default_currency_view)->toBe($row->default_currency_view)
        ->and($result['user']->period_start_day)->toBe((int) $row->period_start_day);
});

it('opens the settings page for an account straight out of signup', function (): void {
    $result = app(SignupAction::class)('settingsuser', 'opensesame-long-enough', false);

    // The signup instance itself, as the guard holds it: a copy re-read from
    // the database is what made this pass by accident.
    Livewire::actingAs($result['user'])
        ->test(SettingsPage::class)
        ->assertOk()
        ->assertSet('defaultCurrencyView', 'eur_only');
});
