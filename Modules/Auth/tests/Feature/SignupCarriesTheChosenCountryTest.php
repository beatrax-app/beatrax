<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

// The country used to be reachable only from a tax screen, so a fresh install
// classified against every region until somebody went looking for it.

function signupWithCountry(string $country): User
{
    Livewire::test(SignupPage::class)
        ->set('username', 'wessel')
        ->set('password', 'opensesame-long-enough')
        ->set('passwordConfirmation', 'opensesame-long-enough')
        ->set('country', $country)
        ->call('submit');

    return User::query()->firstOrFail();
}

it('lands the country chosen on the register page on the new user row', function (): void {
    expect(User::query()->count())->toBe(0);

    $user = signupWithCountry('nl');

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBe('nl');
});

// Not choosing is an answer. Defaulting to somewhere would classify a reader's
// imports against a country they never named.
it('leaves the country unset when the reader skips the picker', function (): void {
    $user = signupWithCountry('');

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBeNull();
});

it('drops a country code that is not on the allow-list', function (): void {
    $user = signupWithCountry('xx');

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBeNull();
});

// The signup choice has to reach the module that keeps country-scoped
// reference data, or the reader arrives at an empty tax screen anyway.
it('seeds the deduction categories for the country picked at signup', function (): void {
    $user = signupWithCountry('nl');

    $seeded = DB::table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();

    expect($seeded)->toBeGreaterThan(0);
});

// The language choice already rode through signup this way; the country now
// travels beside it rather than through a second mechanism.
it('keeps carrying the welcome-screen language while it carries the country', function (): void {
    $this->post(route('locale.switch'), ['code' => 'nl'])->assertRedirect();

    $user = signupWithCountry('be');

    expect($user->locale)->toBe('nl')
        ->and(DB::table('users')->where('id', $user->id)->value('country_code'))->toBe('be');
});
