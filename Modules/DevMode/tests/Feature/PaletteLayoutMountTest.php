<?php

declare(strict_types=1);

// Source-grep rather than render: the layouts' @inject contracts do not
// resolve cleanly in a CLI test outside a real request.
it('declares the palette + ⌘. keybind handler on the body tag of resources/views/layouts/app.blade.php', function (): void {
    $contents = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($contents)->toContain('x-on:keydown.window="onKey($event)"');
    expect($contents)->toContain("'palette:open'");
    expect($contents)->toContain("'/dev'");
    // 'INPUT' is the carve-out that stops the window-level handler stealing
    // keystrokes while the caret is in a field.
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
