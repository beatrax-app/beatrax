<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;

// Signup switches language over a Livewire round trip rather than a navigation,
// deliberately, so a half-typed form survives the change. The cost is that the
// layout does not redraw: with the page switched from Dutch to German the copy
// read "Willkommen bei Beatrax" under `document.documentElement.lang === "nl"`,
// which is what a screen reader takes its pronunciation from and what
// beatraxLocaliseChart takes its month names and number format from.

it('tells the page which language it is now in when the switch is a round trip', function (): void {
    Livewire::test(SignupPage::class)
        ->set('locale', 'de')
        ->assertDispatched('locale-applied', tag: 'de');
});

it('names the language it actually applied, not the one that was asked for', function (): void {
    Livewire::test(SignupPage::class)
        ->set('locale', 'nl')
        ->assertDispatched('locale-applied', tag: 'nl');
});

// The listener has to sit on an element that survives the morph, or the first
// switch works and the second does nothing.
it('listens for that on the switcher itself', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect($html)->toContain('locale-applied.window');
});

// The two suffixes are compiled into each row's own x-text effect when Alpine
// first initialises the clone, so a re-render in another language updated the
// visible label beside them and left these behind: German rows announcing
// "(nog niet voldaan)". Reading them off the same object the label comes from
// is what makes them follow.
it('reads the requirement suffix from the same source as its label', function (): void {
    $html = Livewire::test(SignupPage::class)->html();

    expect($html)->toContain('req.ok ? req.met : req.unmet');
});
