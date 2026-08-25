<?php

declare(strict_types=1);

// The dashboard header is a nowrap flex row: a text-3xl month name on the left
// and a shrink-0 "‹ Today ›" stepper on the right. shrink-0 is deliberate — the
// glyphs must keep their tap targets — so the row has to stack rather than
// squeeze. Measured on an iPhone 12 mini with real transactions on it:
// "Αύγουστος 2026" put the stepper's right edge at 397px on a 375pt screen,
// taking the next-period glyph off the display entirely.

it('stacks the period title and its stepper until there is room for both', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/dashboard.blade.php')
    );

    expect($blade)->toContain('flex flex-col gap-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6')
        ->and($blade)->not->toContain('<header class="flex items-baseline justify-between gap-6 dashboard-phone-order-1">');
});

// Unshrinkable and unwrappable together leaves the label as the only thing
// that can give: at the iOS accessibility text sizes ‹ Today › needs 464px of a
// 375pt screen, and the row bought its fit by breaking "Today" into "Tod / ay"
// inside a button two lines too short for it. The stepper keeps shrink-0 and
// takes a second row instead.
it('leaves the stepper unshrinkable, and lets it take a second row', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/livewire/dashboard.blade.php')
    );

    preg_match_all('/class="([^"]*)"/', $blade, $matches);

    $stepper = array_values(array_filter(
        $matches[1],
        static fn (string $classes): bool => str_contains($classes, 'items-center') && str_contains($classes, 'gap-1'),
    ));

    expect($stepper)->not->toBe([], 'The period stepper row is gone.')
        ->and($stepper[0])->toContain('shrink-0')
        ->and($stepper[0])->toContain('flex-wrap');
});

// Same row shape one card down: a text-3xl figure beside a shrink-0
// whitespace-nowrap pill. Measured at 375pt — nl 385, de 387, el 392, all past
// the right edge of the screen and 49px past the card's own edge.
it('lets the over-budget pill wrap instead of leaving the card', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Budgets/Resources/views/livewire/envelope-glance-card.blade.php')
    );

    expect($blade)->toContain('mt-4 flex flex-wrap items-center gap-3')
        ->and($blade)->not->toContain('<div class="mt-4 flex items-center gap-3">');
});
