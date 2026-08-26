<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// An emoji's ink is wider than the advance width the fallback font reports, so
// the single space between the glyph and the label collapsed to nothing and the
// S of "Step 3" printed on top of the glyph — on the connector steps, which are
// the first screens a new install shows.

it('spaces the step glyph away from the label with a margin', function (): void {
    $html = Blade::render('<x-onboarding::wiz-eyebrow step="connect-paypal" glyph="X">Your PayPal account</x-onboarding::wiz-eyebrow>');

    expect($html)->toContain('<span class="me-1.5" aria-hidden="true">X</span>')
        ->and($html)->toContain('Your PayPal account');
});
