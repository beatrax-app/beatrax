<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;

// The register page now carries both pickers, and "Nederlands" and "Nederland"
// differ by two letters. Whatever tells them apart has to be on the screen.

// Regional-indicator pairs, matched by codepoint rather than through the enum,
// so this still fails if a flag comes back by another route.
const SIGNUP_CARRIES_NO_FLAG = '/[\x{1F1E6}-\x{1F1FF}]/u';

it('offers a country picker beside the language picker', function (): void {
    Livewire::test(SignupPage::class)
        ->assertSee('Display language')
        ->assertSee('Your country')
        ->assertSeeHtml('id="signup-country"')
        ->assertSeeHtml('id="locale-switcher-select"');
});

it('spells out what each of the two changes', function (): void {
    Livewire::test(SignupPage::class)
        ->assertSee('Changes the words on screen, and how amounts are written. System follows your browser or operating system language, defaulting to English.')
        ->assertSee("Decides which country's tax rules, government bodies and bank fees the app recognises. It does not change the language or how amounts are written.");
});

// A flag names a country. On the one screen that asks for both, it is the
// signal the language picker cannot carry.
it('shows no flag on either picker', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect(preg_match(SIGNUP_CARRIES_NO_FLAG, $html))->toBe(0);
});

// Both labels are visible ones, not sr-only: the language switcher hides its
// label everywhere else, which is what left the two indistinguishable here.
it('gives the language picker a visible label of its own', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect($html)->toContain('<label class="block text-sm text-slate-900 dark:text-slate-100" for="locale-switcher-select">');
    expect($html)->not->toContain('<label class="sr-only" for="locale-switcher-select">');
});

it('starts with no country chosen', function (): void {
    Livewire::test(SignupPage::class)->assertSet('country', '');
});
