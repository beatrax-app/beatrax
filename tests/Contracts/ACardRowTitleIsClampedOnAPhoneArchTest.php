<?php

declare(strict_types=1);

// Measured on an iPhone 12 mini, on a cash entry saved with a 300-character
// counterparty: the row grew to 350px of an 812px viewport and eleven lines of
// name, and the amount and its delete button were pushed off the bottom of the
// card. overflow-wrap had already stopped the text escaping sideways; nothing
// stopped it escaping downwards. Clamped, the same row is 143px.

// The phone rule is the one carrying overflow-wrap: the base rule above it
// applies at every width and is not what this guards.
function cardListItemPhoneRule(): string
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.card-list-item .primary {'."\n".'            overflow-wrap: anywhere;');

    expect($start)->not->toBeFalse('No phone-width .card-list-item .primary rule carries overflow-wrap.');

    $end = strpos($css, '}', (int) $start);

    return substr($css, (int) $start, (int) $end - (int) $start);
}

it('clamps a card row title to two lines rather than letting it grow', function (): void {
    $rule = cardListItemPhoneRule();

    $missing = [];

    // -webkit-box is what line-clamp needs to apply at all, and overflow:
    // hidden is what turns the clamp into an ellipsis instead of a crop.
    foreach (['display: -webkit-box', '-webkit-box-orient: vertical', '-webkit-line-clamp: 2', 'overflow: hidden'] as $property) {
        if (! str_contains($rule, $property)) {
            $missing[] = $property;
        }
    }

    expect($missing)->toBe([], 'The clamp is incomplete, missing: '.implode(', ', $missing));
});

// The clamp cannot break a run that has no break opportunity, so the guard it
// was added beside has to stay: an IBAN would still leave the card sideways.
it('keeps the wrap guard the clamp depends on', function (): void {
    expect(cardListItemPhoneRule())->toContain('overflow-wrap: anywhere');
});
