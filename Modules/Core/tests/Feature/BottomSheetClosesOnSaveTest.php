<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\PatternScan;

// The component listened for `close-sheet`, which nothing in this codebase has
// ever dispatched; the pages dispatch `modal-close`. So on a phone, saving a
// goal wrote the row, closed the desktop modal, and left the sheet open over the
// list it had just added to. Both names answer because one signal closes both.

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

    $matches = PatternScan::all('/x-on:(?:close-sheet|modal-close)\.window="([^"]+)"/', $html);

    expect($matches[0])->toHaveCount(2);

    foreach ($matches[1] as $handler) {
        expect($handler)->toContain('detail.name')
            ->and($handler)->toContain('only-me');
    }
});
