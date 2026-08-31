<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// The affordance this replaces is the `title` attribute, which fires on hover
// and nothing else: measured on an iPhone 12 mini and a Galaxy S24 Ultra, no
// tap, long-press or focus surfaces one. Every property below is one a finger
// or a keyboard can use with no pointer in the room at all.

function helpTipMarkup(string $extra = ''): string
{
    return Blade::render(
        '<x-core::help-tip topic="probe" :label="$label" :body="$body" '.$extra.' />',
        [
            'label' => 'Ready to assign',
            'body' => 'Money that has arrived and has no envelope yet.',
        ],
    );
}

it('opens its panel from a button wired to it, with no hover anywhere in the path', function (): void {
    $html = helpTipMarkup();

    expect($html)->toMatch('/<button\b[^>]*\btype="button"[^>]*\bpopovertarget="help-tip-probe"/')
        ->and($html)->toMatch('/<div\b[^>]*\bpopover\b[^>]*\bid="help-tip-probe"/');
});

it('carries a dismissal a finger can find, rather than only a tap outside it', function (): void {
    expect(helpTipMarkup())->toMatch(
        '/<button\b[^>]*\bpopovertarget="help-tip-probe"[^>]*\bpopovertargetaction="hide"/',
    );
});

it('puts the help text in the document, never in an attribute only a pointer reads', function (): void {
    $html = helpTipMarkup();

    expect($html)->toContain('Money that has arrived and has no envelope yet.')
        ->and($html)->not->toContain('title="');
});

it('names the thing it explains, so the trigger is not twenty identical question marks', function (): void {
    expect(helpTipMarkup())->toContain('aria-label="About Ready to assign"');
});

// A sole icon action is an emoji here; a help mark is not an action, so it is a
// glyph. The selector matters as much as the choice: a picture-by-default code
// point without U+FE0F draws as line art on Android and as colour on iOS.
it('draws its mark as a glyph and not as a picture', function (): void {
    $html = helpTipMarkup();

    expect($html)->toContain('>?</span>')
        ->and($html)->not->toContain("\u{FE0F}");

    expect(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $html))->toBe(0);
});

it('gives the panel an accessible name taken from the label it was opened for', function (): void {
    $html = helpTipMarkup();

    expect($html)->toContain('aria-labelledby="help-tip-probe-title"')
        ->and($html)->toContain('id="help-tip-probe-title"');
});

it('lets a call site pass its own hooks through to the trigger', function (): void {
    expect(helpTipMarkup('data-testid="probe-help"'))->toContain('data-testid="probe-help"');
});
