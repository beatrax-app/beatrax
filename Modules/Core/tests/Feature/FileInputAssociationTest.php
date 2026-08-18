<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
 * The file field's label contract.
 *
 * `x-core::file-input` hides the real `<input type="file">` inside a <label>,
 * because the engine's own chrome ("Choose File / No file chosen") renders in
 * the SYSTEM locale and no attribute or stylesheet can reach it. Wrapping keeps
 * the native click-to-open behaviour and the screen-reader semantics.
 *
 * The wrap alone is a valid implicit association, and every caller passes an
 * `id` — but that id reached the input through `$attributes`, so nothing
 * reading the template could see either half. The accessibility scanner called
 * the input orphaned, and it was right that the source did not say otherwise.
 */

it('associates the label with the input explicitly, both halves in the markup', function (): void {
    $html = Blade::render('<x-core::file-input id="import-file" accept=".csv" />');

    expect($html)->toContain('for="import-file"')
        ->and($html)->toContain('id="import-file"')
        ->and($html)->toContain('type="file"');

    // Exactly one id, so the spread cannot also emit it and produce a
    // duplicate attribute that the browser resolves and the scanner does not.
    expect(mb_substr_count($html, 'id="import-file"'))->toBe(1);
});

it('still wraps the input in the label, which is what makes the click work', function (): void {
    $html = Blade::render('<x-core::file-input id="wrapped" />');

    $labelAt = mb_strpos($html, '<label');
    $inputAt = mb_strpos($html, '<input');
    $closeAt = mb_strpos($html, '</label>');

    expect($labelAt)->not->toBeFalse()
        ->and($inputAt)->toBeGreaterThan($labelAt)
        ->and($closeAt)->toBeGreaterThan($inputAt);
});

it('omits both halves rather than inventing an id the caller did not give', function (): void {
    $html = Blade::render('<x-core::file-input />');

    expect($html)->not->toContain('for=')
        ->and($html)->toContain('type="file"');
});

it('carries the caller wire:model through to the input', function (): void {
    $html = Blade::render('<x-core::file-input id="f" wire:model="statement" accept=".csv" />');

    expect($html)->toContain('wire:model="statement"')
        ->and($html)->toContain('accept=".csv"');
});
