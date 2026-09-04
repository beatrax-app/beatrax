<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\PatternScan;

// Measured in Chromium with a coarse pointer at 375px and 411px, against the
// built stylesheet, in all 26 locales: without these two the strip's buttons
// squeezed to exactly 44px and broke one word per line — 156 clipped labels,
// English's own "Keep it locked" among them.
it('lets a confirm strip wrap rather than squeeze its two answers to the touch floor', function (): void {
    $html = Blade::render(
        '<x-core::confirm-strip question="Q" cancel-label="C" confirm-label="Y" cancel="no" confirm="yes" />'
    );

    expect($html)->toContain('flex flex-wrap items-center');

    $buttons = PatternScan::all('~<button\b.*?</button>~s', $html);

    expect($buttons[0])->toHaveCount(2);

    foreach ($buttons[0] as $button) {
        expect($button)->toContain('shrink-0');
    }
});

// The 44px floor replaces min-width:auto, so a shrinkable button is the trap.
// A strip standing in for a table cell or a list item takes the same classes.
it('keeps the wrap and the floor whichever tag the strip stands in for', function (): void {
    foreach (['li', 'td'] as $tag) {
        $html = Blade::render(sprintf(
            '<x-core::confirm-strip tag="%s" question="Q" cancel-label="C" confirm-label="Y" cancel="no" confirm="yes" />',
            $tag,
        ));

        expect($html)->toContain('<'.$tag.' ')
            ->and($html)->toContain('flex flex-wrap items-center')
            ->and(substr_count($html, 'shrink-0'))->toBe(2);
    }
});

// Cancel first and confirm last: the button nearest the thumb must not be the
// one that goes through with it.
it('draws the way out before the way through', function (): void {
    $html = Blade::render(
        '<x-core::confirm-strip question="Q" cancel-label="Keep it" confirm-label="Do it" cancel="no" confirm="yes" />'
    );

    expect(strpos($html, 'Keep it'))->toBeLessThan((int) strpos($html, 'Do it'));
});
