<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;

// Joining a desktop's account creates a second user row, and `users` is not a
// synced table: a country never asked for here is never answered from the
// other side either.

function importWithCountry(string $country): User
{
    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->set('country', $country)
        ->call('submit')
        ->assertSet('step', 'recovery_codes');

    return User::query()->firstOrFail();
}

it('lands the country chosen on the import screen on the new user row', function (): void {
    expect(User::query()->count())->toBe(0);

    $user = importWithCountry('nl');

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBe('nl');
});

// Not choosing is an answer. A phone that guessed would classify against a
// country its owner never named, and the empty state widens rather than fails.
it('leaves the country unset when the phone owner skips the picker', function (): void {
    $user = importWithCountry('');

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBeNull();
});

it('drops a country code that is not on the allow-list', function (): void {
    $user = importWithCountry('xx');

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBeNull();
});

// Skipping has to stay reachable after a country is picked, so this surface
// keeps the empty option choosable — Settings is the one that cannot.
it('leaves the empty option choosable, and marks the chosen country selected', function (): void {
    $component = Livewire::test(MobileImportBootstrap::class);

    expect($component->html())->toMatch('/<option value=""\s+selected>/')
        ->not->toContain('<option value="" disabled');

    expect($component->set('country', 'nl')->html())->toContain('<option value="nl" selected>');
});
