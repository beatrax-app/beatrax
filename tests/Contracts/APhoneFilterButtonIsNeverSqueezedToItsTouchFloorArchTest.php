<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// The Filters button moved onto the search box's own line, which is a flex row
// with a control at each end and nothing spare between them.
//
// `min-width: 44px` from the touch floor REPLACES `min-width: auto`, so a
// shrinkable button in such a row is taken to exactly 44px wide and breaks its
// own label into one glyph per line — measured on the dev Logs page. The button
// therefore does not shrink and does not wrap its label; the field carries the
// flex basis, so when the reader's text no longer leaves it 11rem the whole
// group wraps to a second line instead of anything being squeezed.

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

it('lets the row wrap rather than squeeze what is on it', function (): void {
    expect(CssRule::blockFor($this->css, '.srch-input-row {'))->toContain('flex-wrap: wrap;');
});

// A floor written in rems outgrows a 320px viewport and takes the page
// sideways; the basis alone decides where the row breaks.
it('sizes the search field from a basis and not from a floor', function (): void {
    $field = CssRule::blockFor($this->css, '.srch-input-row > .srch-input-wrap {');

    expect($field)->toContain('flex: 1 1 11rem;')
        ->and($field)->toContain('min-width: 0;');
});

it('never lets the filter button give up width', function (): void {
    expect(CssRule::blockFor($this->css, '.srch-input-row > .srch-phone-filters {'))->toContain('flex-shrink: 0;');

    $button = CssRule::blockFor($this->css, '.srch-filters-btn {');

    expect($button)->toContain('flex-shrink: 0;')
        ->and($button)->toContain('white-space: nowrap;');
});

// .srch-filters-btn sets `display: inline-flex` in unlayered CSS, which beats
// Tailwind's layered `hidden`, so the hide has to sit on a wrapper — the same
// trap the desktop chip row beside it already documents.
it('hides the phone button from a wrapper rather than from the button', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Ledger/Resources/views/livewire/partials/search-toolbar.blade.php'),
    );

    preg_match_all('/class="([^"]*\bsrch-filters-btn\b[^"]*)"/', $blade, $matches);

    expect($matches[1])->toHaveCount(1)
        ->and($matches[1][0])->not->toContain('md:hidden');

    expect($blade)->toContain('class="md:hidden flex items-center gap-2 srch-phone-filters"');
});
