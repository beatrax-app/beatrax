<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Core\Public\Support\PatternScan;

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

    expect(PatternScan::matches(SIGNUP_CARRIES_NO_FLAG, $html))->toBeFalse();
});

// Both labels are visible ones, not sr-only: the language switcher hides its
// label everywhere else, which is what left the two indistinguishable here.
// Asserted as "a label for this control, and it is not sr-only" rather than as
// a class string — pinning the utilities made a styling tweak a failing test
// for no behavioural reason.
it('gives the language picker a visible label of its own', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect($html)->toMatch('/<label class="(?![^"]*\bsr-only\b)[^"]*" for="locale-switcher-select">/');
});

// Tab order follows the document, and the pair used to sit after the submit
// button: username → password → confirm → submit → language → country. A
// choice offered only after the control that leaves the screen is not one the
// signup screen asks.
it('puts both pickers ahead of the button that leaves the screen', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    $language = strpos($html, 'id="locale-switcher-select"');
    $country = strpos($html, 'id="signup-country"');
    // The account form's own opening tag: everything the reader tabs through
    // on the way to the submit button is inside it.
    $accountForm = strpos($html, 'wire:submit="submit"');

    expect($language)->toBeInt()
        ->and($country)->toBeInt()
        ->and($accountForm)->toBeInt()
        ->and($language)->toBeLessThan($accountForm)
        ->and($country)->toBeLessThan($accountForm);
});

// One bordered box holding both reads as a single question with two rows. Two
// boxes read as two questions, which is what they are.
it('gives each picker its own card rather than sharing one box', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    $cards = PatternScan::count('/<div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">/', $html);

    expect($cards)->toBe(2);
});

it('starts with no country chosen', function (): void {
    Livewire::test(SignupPage::class)->assertSet('country', '');
});

// The help line under the language picker ends "System follows your browser or
// operating system language, defaulting to English." — copy shared with
// Settings. On this screen the switcher listed Locale::cases() and nothing
// else, so the sentence named an option that was not in the list.
it('carries the System option its own help line promises', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect($html)
        ->toContain('System follows your browser or operating system language')
        ->toContain('value="'.LocaleNegotiator::SYSTEM.'"');
});
