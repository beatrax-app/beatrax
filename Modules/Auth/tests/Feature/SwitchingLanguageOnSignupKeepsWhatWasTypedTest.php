<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The register page carries the language picker and the country picker in one
// box, above three boxes the reader has already filled in. Changing the
// language there used to be a whole navigation, and took all four with it.

function signupPageHalfFilled(): Testable
{
    return Livewire::test(SignupPage::class)
        ->set('username', 'wessel')
        ->set('password', 'opensesame-long-enough')
        ->set('passwordConfirmation', 'opensesame-long-enough')
        ->set('country', 'nl');
}

it('keeps every box the reader has filled in when the language changes', function (): void {
    signupPageHalfFilled()
        ->call('setLocale', 'nl')
        ->assertSet('username', 'wessel')
        ->assertSet('password', 'opensesame-long-enough')
        ->assertSet('passwordConfirmation', 'opensesame-long-enough')
        ->assertSet('country', 'nl');
});

// The switch has to actually switch, or keeping the state is worth nothing.
it('renders the page in the language just chosen', function (): void {
    $html = signupPageHalfFilled()
        ->call('setLocale', 'nl')
        ->html();

    expect($html)
        ->toContain('Jouw land')
        ->not->toContain('Your country');
});

// A Livewire action reaches the same session key the POST route writes, so
// SetLocale still decides the language on the next full page load.
it('leaves the choice where the rest of the app looks for it', function (): void {
    signupPageHalfFilled()->call('setLocale', 'nl');

    expect(session('locale'))->toBe('nl');
});

it('ignores a language code that is not one of ours', function (): void {
    signupPageHalfFilled()
        ->call('setLocale', 'xx')
        ->assertSet('country', 'nl');

    expect(session('locale'))->not->toBe('xx');
});

// The switch is not a submit: a half-filled form must not create an account.
it('creates no account when only the language changed', function (): void {
    signupPageHalfFilled()->call('setLocale', 'nl');

    expect(User::query()->count())->toBe(0);
});

// And the state it kept is the state that gets written.
it('signs up with the country still selected after a language change', function (): void {
    signupPageHalfFilled()
        ->call('setLocale', 'nl')
        ->call('submit');

    $user = User::query()->firstOrFail();

    expect(DB::table('users')->where('id', $user->id)->value('country_code'))->toBe('nl')
        ->and($user->username)->toBe('wessel');
});

// The POST form is what navigated away; on this screen it must be gone
// entirely rather than sitting beside the Livewire control.
it('offers no navigating form for the language on this screen', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect($html)
        ->toContain('wire:change="setLocale($event.target.value)"')
        ->not->toContain(route('locale.switch'));
});
