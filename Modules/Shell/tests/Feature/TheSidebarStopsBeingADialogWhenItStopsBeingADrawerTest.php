<?php

declare(strict_types=1);

// One element is the phone's drawer and the desktop's static sidebar; the
// stylesheet swaps it at 1024px. The dialog semantics were written as literal
// attributes, so at 1280px a permanently visible navigation still said
// role="dialog" aria-modal="true" — which tells a screen reader that
// everything outside the nav is unavailable, on every desktop page.

it('binds the drawer role rather than asserting it at every width', function (): void {
    $drawer = (string) file_get_contents(
        base_path('Modules/Shell/Resources/views/components/drawer.blade.php'),
    );

    // Newline-anchored: the same strings appear in the comment above the
    // binding, explaining what they used to do.
    expect($drawer)->not->toContain("\n    role=\"dialog\"")
        ->and($drawer)->not->toContain("\n    aria-modal=\"true\"")
        ->and($drawer)->toContain(':role="$store.mobileNav.isDrawer')
        ->and($drawer)->toContain(':aria-modal="$store.mobileNav.isDrawer');
});

// The binding is only as good as the flag behind it, and the flag has to track
// the same breakpoint the stylesheet switches on, both at load and on resize.
it('tracks the same breakpoint the stylesheet swaps the panel at', function (): void {
    $app = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($app)->toContain("matchMedia('(max-width: 1023.98px)')")
        ->and($app)->toContain('isDrawer: drawerBreakpoint.matches')
        ->and($app)->toContain("drawerBreakpoint.addEventListener('change'");

    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain('1024px');
});
