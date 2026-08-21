<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// The real <input type="file"> hides inside the <label> because the engine's own
// "Choose File" chrome renders in the system locale and no attribute or
// stylesheet reaches it. The wrap alone is a valid implicit association, but the
// id arrived through $attributes, so the template never said either half aloud.

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
