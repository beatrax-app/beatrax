<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// The rose cell tint was 1.20:1 against a plain cell in light and 1.11:1 in
// dark once the hue is taken away, which is no signal at all. It leant on the
// signed balance in the cell corner — and that corner is `hidden
// sm:inline-block`, so below 640px the phone drew the day number, the entry
// count and the tint, and a reader who cannot see the rose had nothing to
// read. The edge bar is the same marker the dashboard's failed-chain toast
// wears, and it is drawn at every width.

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
    $this->template = (string) file_get_contents(
        base_path('Modules/Calendar/Resources/views/livewire/calendar-page.blade.php'),
    );
});

it('marks a risk day with an edge the hue is not needed to see', function (): void {
    $rule = CssRule::blockFor($this->css, '.cal-cell--risk {');

    expect($rule)->not->toBe('', 'No rule in app.css declares .cal-cell--risk.');
    expect($rule)->toContain('box-shadow: inset 3px 0 0 var(--color-rose);')
        ->and($rule)->toContain('background: var(--color-rose-bg);');
});

// The fact that made the tint the whole of the signal, pinned so the fix is
// not quietly undone by giving the corner back and dropping the bar.
it('still leaves the signed balance corner to widths from 640px up', function (): void {
    expect($this->template)->toContain('class="cal-day-balance hidden sm:inline-block"');
});

// A hover that repaints the background must not paint over the bar.
it('keeps the edge while the cell is hovered', function (): void {
    expect(CssRule::blockFor($this->css, '.cal-cell--risk:hover'))->not->toContain('box-shadow');
});
