<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\LocaleNegotiator;

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
        ->set('locale', 'nl')
        ->assertSet('username', 'wessel')
        ->assertSet('password', 'opensesame-long-enough')
        ->assertSet('passwordConfirmation', 'opensesame-long-enough')
        ->assertSet('country', 'nl');
});

// The switch has to actually switch, or keeping the state is worth nothing.
it('renders the page in the language just chosen', function (): void {
    $html = signupPageHalfFilled()
        ->set('locale', 'nl')
        ->html();

    expect($html)
        ->toContain('Jouw land')
        ->not->toContain('Your country');
});

// A Livewire action reaches the same session key the POST route writes, so
// SetLocale still decides the language on the next full page load.
it('leaves the choice where the rest of the app looks for it', function (): void {
    signupPageHalfFilled()->set('locale', 'nl');

    expect(session('locale'))->toBe('nl');
});

it('ignores a language code that is not one of ours', function (): void {
    signupPageHalfFilled()
        ->set('locale', 'xx')
        ->assertSet('country', 'nl');

    expect(session('locale'))->not->toBe('xx');
});

// The switch is not a submit: a half-filled form must not create an account.
it('creates no account when only the language changed', function (): void {
    signupPageHalfFilled()->set('locale', 'nl');

    expect(User::query()->count())->toBe(0);
});

// And the state it kept is the state that gets written.
it('signs up with the country still selected after a language change', function (): void {
    signupPageHalfFilled()
        ->set('locale', 'nl')
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
        ->toContain('wire:model.live="locale"')
        ->not->toContain(route('locale.switch'));
});

// "System" is the absence of an override, not a locale. Storing it as one would
// pin the install to the literal string `auto`, and the reader who switched
// away by accident could never get their browser's language back.
it('clears the preference rather than storing a code when System is chosen', function (): void {
    session()->put('locale', 'nl');

    signupPageHalfFilled()->set('locale', LocaleNegotiator::SYSTEM);

    expect(session()->has('locale'))->toBeFalse();
});

it('opens on the language already in force, so the select does not misreport it', function (): void {
    session()->put('locale', 'nl');

    expect(Livewire::test(SignupPage::class)->get('locale'))->toBe('nl');
});

it('opens on System when nothing has been chosen', function (): void {
    session()->forget('locale');

    expect(Livewire::test(SignupPage::class)->get('locale'))->toBe(LocaleNegotiator::SYSTEM);
});
