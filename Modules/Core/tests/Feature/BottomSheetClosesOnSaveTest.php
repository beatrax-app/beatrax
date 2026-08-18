<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
 * A sheet has to close when the page says the work is done.
 *
 * The component listened for `close-sheet`, and nothing in this codebase has
 * ever dispatched that name. What the pages dispatch is `modal-close`. So on a
 * phone, saving a goal wrote the row, closed the DESKTOP modal, and left the
 * bottom sheet sitting open over the list it had just added to — measured on
 * a Galaxy, with the save button still on screen at 783px of an 891px
 * viewport after a successful save.
 *
 * Both names are honoured rather than renaming one: the desktop modal answers
 * to `modal-close` as well, and one signal closing both surfaces is the point.
 */

it('closes on the event the pages actually dispatch', function (): void {
    $html = Blade::render('<x-core::bottom-sheet name="goal-form" title="T">body</x-core::bottom-sheet>');

    expect($html)->toContain('modal-close.window')
        ->and($html)->toContain("'goal-form'");
});

it('keeps answering close-sheet, so nothing that used it regresses', function (): void {
    $html = Blade::render('<x-core::bottom-sheet name="pot-form" title="T">body</x-core::bottom-sheet>');

    expect($html)->toContain('close-sheet.window');
});

it('scopes both listeners to its own name, so one sheet cannot close another', function (): void {
    $html = Blade::render('<x-core::bottom-sheet name="only-me" title="T">body</x-core::bottom-sheet>');

    // Every listener guards on the name before touching `open`.
    $listeners = preg_match_all('/x-on:(?:close-sheet|modal-close)\.window="([^"]+)"/', $html, $matches);

    expect($listeners)->toBe(2);

    foreach ($matches[1] as $handler) {
        expect($handler)->toContain('detail.name')
            ->and($handler)->toContain('only-me');
    }
});
