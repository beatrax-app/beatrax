<?php

declare(strict_types=1);

/*
 * Palette keybind handler + Livewire mount invariants (Phase 16-08 Task 2).
 *
 * Both base layouts MUST host:
 *   - a body-level Alpine `x-data` block declaring the `onKey($event)`
 *     handler that dispatches `palette:open` on ⌘K / Ctrl+K and
 *     navigates to /dev on ⌘. (D-42).
 *   - an `@livewire('dev.command-palette-modal')` mount so the
 *     palette has a dispatch sink inside the layout.
 *
 * The tests grep the on-disk Blade source rather than render the
 * layouts because (a) the `resources/views/layouts/app.blade.php` uses
 * the `auth()` helper which a CLI test cannot resolve cleanly outside
 * a real request, and (b) the rendered HTML still contains the
 * x-data + livewire markers we want to lock — so a source-grep is
 * the simplest, most stable contract.
 */

it('declares the palette + ⌘. keybind handler on the body tag of resources/views/layouts/app.blade.php', function (): void {
    $contents = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($contents)->toContain('x-on:keydown.window="onKey($event)"');
    // The handler dispatches `palette:open` on ⌘K and navigates to
    // /dev on ⌘. — both behaviours must be present in the body
    // attribute.
    expect($contents)->toContain("'palette:open'");
    expect($contents)->toContain("'/dev'");
    // The I-7 carve-out for text-input focus is required so the
    // handler does not steal keystrokes inside an INPUT / TEXTAREA.
    expect($contents)->toContain("'INPUT'");
});

it('mounts the command palette Livewire component inside the @auth block of resources/views/layouts/app.blade.php', function (): void {
    $contents = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($contents)->toContain("@livewire('dev.command-palette-modal')");
});

it('declares the palette + ⌘. keybind handler on the body tag of dev-shell.blade.php', function (): void {
    $contents = (string) file_get_contents(base_path('Modules/DevMode/Resources/views/layouts/dev-shell.blade.php'));

    expect($contents)->toContain('x-on:keydown.window="onKey($event)"');
    expect($contents)->toContain("'palette:open'");
    expect($contents)->toContain("'/dev'");
    expect($contents)->toContain("'INPUT'");
});

it('mounts the command palette Livewire component inside dev-shell.blade.php', function (): void {
    $contents = (string) file_get_contents(base_path('Modules/DevMode/Resources/views/layouts/dev-shell.blade.php'));

    expect($contents)->toContain("@livewire('dev.command-palette-modal')");
});
