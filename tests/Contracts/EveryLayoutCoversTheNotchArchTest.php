<?php

declare(strict_types=1);

// Without viewport-fit=cover iOS reports every safe-area inset as 0, so a
// layout that omits it reserves nothing and draws over the status bar however
// carefully the CSS asks for --safe-top. Read on an iPhone 12 mini: the Dev
// Console heading and the clock printed on top of each other.

it('declares viewport-fit=cover in every layout', function (): void {
    $layouts = array_merge(
        glob(base_path('resources/views/layouts/*.blade.php')) ?: [],
        glob(base_path('Modules/*/Resources/views/layouts/*.blade.php')) ?: [],
    );

    expect($layouts)->not->toBe([]);

    $missing = [];
    foreach ($layouts as $path) {
        $source = (string) file_get_contents($path);
        if (! str_contains($source, '<meta name="viewport"')) {
            continue;
        }
        if (! str_contains($source, 'viewport-fit=cover')) {
            $missing[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($missing)->toBe([]);
});

it('gives the Dev Console rail a phone layout instead of 87pt of page', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.flex:has(> .dev-side)');
    expect($start)->not->toBeFalse();

    $before = substr($css, 0, (int) $start);
    $lastMedia = strrpos($before, '@media (');

    expect(substr($before, (int) $lastMedia, 30))->toContain('max-width: 767px');

    $rule = substr($css, (int) $start, 800);

    expect($rule)->toContain('flex-direction: column;')
        ->and($rule)->toContain('padding-top: calc(var(--space-3) + var(--safe-top));');
});
