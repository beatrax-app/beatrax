<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#two-phone-constraints-and-three-dialog-naming-failures
 */

/**
 * @return string the stylesheet with every balanced `@layer name { ... }` block
 *                removed, so what is left is exactly the unlayered rules
 */
function phoneUiUnlayeredCss(): string
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $out = '';
    $offset = 0;
    while (preg_match('/@layer\s+[a-z]+\s*\{/', $css, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $out .= substr($css, $offset, $match[0][1] - $offset);

        $cursor = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        while ($depth > 0 && $cursor < strlen($css)) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }
            $cursor++;
        }
        $offset = $cursor;
    }

    return $out.substr($css, $offset);
}

/** @return list<string> every `@media (pointer: coarse)` block that is not inside a cascade layer */
function phoneUiCoarsePointerBlocks(): array
{
    preg_match_all(
        '/@media \(pointer: coarse\) \{(?:[^{}]|\{[^{}]*\})*\}/',
        phoneUiUnlayeredCss(),
        $matches
    );

    return $matches[0];
}

/**
 * @return list<string> the class attribute of every element the coarse-pointer
 *                      44px floor selects, across all Blade views
 */
function phoneUiButtonClassLists(): array
{
    $views = [];
    foreach (['Modules', 'resources'] as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $views[] = (string) $file;
            }
        }
    }

    $lists = [];
    foreach ($views as $view) {
        preg_match_all('/<(button|summary)\b([^>]*)>/s', (string) file_get_contents($view), $tags, PREG_SET_ORDER);
        foreach ($tags as $tag) {
            if (preg_match('/class="([^"]*)"/', $tag[2], $class) === 1) {
                $lists[] = $class[1];
            }
        }
    }

    return $lists;
}

it('keeps every touch form control at or above the iOS auto-zoom threshold', function (): void {
    // The viewport deliberately allows zoom, so raising the control is the only
    // remedy for iOS auto-zoom that does not break pinch-zoom for everyone.
    $covering = array_filter(
        phoneUiCoarsePointerBlocks(),
        static fn (string $block): bool => str_contains($block, 'font-size: 16px')
            && str_contains($block, 'input')
            && str_contains($block, 'select')
            && str_contains($block, 'textarea'),
    );

    expect($covering)->not->toBeEmpty(
        'app.css has no unlayered coarse-pointer rule holding input/select/textarea at 16px'
    );
});

it('gives every touch button a 44px hit area', function (): void {
    // Unlayered, or `h-10` outranks it.
    $covering = array_filter(
        phoneUiCoarsePointerBlocks(),
        static fn (string $block): bool => str_contains($block, 'min-height: 44px')
            && str_contains($block, 'min-width: 44px')
            && preg_match('/(^|[\s,])button\s*[,{]/m', $block) === 1,
    );

    expect($covering)->not->toBeEmpty(
        'app.css has no unlayered coarse-pointer 44px floor covering <button>'
    );
});

it('names the bottom sheet dialog by its heading rather than by a second copy of it', function (): void {
    $sheet = file_get_contents(base_path('Modules/Core/Resources/views/components/bottom-sheet.blade.php'));
    expect($sheet)->toBeString();

    // The same sheet is both "create" and "edit", and a duplicated name went
    // stale on the round-trip that updated the heading.
    expect($sheet)->toContain('aria-labelledby=')
        ->and($sheet)->not->toContain('aria-label="{{ $title }}"');
});

it('never leaves the sheet dialog without an accessible name', function (): void {
    $sheet = (string) file_get_contents(base_path('Modules/Core/Resources/views/components/bottom-sheet.blade.php'));

    // A title can be conditional at the call site — the calendar's is '' before
    // a day is picked — so the empty branch has to name the dialog too.
    expect($sheet)->toContain('@else')
        ->and($sheet)->toContain("aria-label=\"{{ Lang::get('core::components.sheet_untitled') }}\"");
});

it('backs every open-sheet dispatch with a sheet that answers to that name', function (): void {
    $files = [];
    foreach (['Modules', 'resources/views'] as $root) {
        $dir = base_path($root);
        if (! is_dir($dir)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    expect($files)->not->toBeEmpty();

    // A dispatched sheet name that only a flux:modal carries is inert on a
    // phone, and the page still looks complete.
    $orphans = [];
    foreach ($files as $path) {
        $blade = (string) file_get_contents($path);

        preg_match_all('/open-sheet[\'"]?\s*,\s*\{?\s*name\s*:\s*[\'"]([a-z0-9-]+)[\'"]/', $blade, $dispatched);
        preg_match_all('/<x-core::bottom-sheet\s+name=["\']([a-z0-9-]+)["\']/', $blade, $declared);

        foreach (array_unique($dispatched[1]) as $name) {
            if (! in_array($name, $declared[1], true)) {
                $orphans[] = $name.' in '.str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($orphans)->toBe([], 'open-sheet dispatched at a name no bottom sheet answers to: '.implode(', ', $orphans));
});

it('keeps the 44px floor from deforming controls the design draws smaller', function (): void {
    // The floor inflates the border box, so a fixed-size pill-radius control
    // becomes a circle and its positioned children strand. Anything it would
    // deform opts out and takes its touch reach from a pseudo-element.
    $css = phoneUiUnlayeredCss();

    $drawnSmall = [];
    preg_match_all('/(?<selector>[^{}]+)\{(?<body>[^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        $selector = trim($rule['selector']);
        if (! preg_match('/^\.([a-zA-Z0-9_-]+)$/', $selector, $name)) {
            continue;
        }

        preg_match('/(?<![a-z-])width:\s*(\d+)px/', $rule['body'], $width);
        preg_match('/(?<![a-z-])height:\s*(\d+)px/', $rule['body'], $height);

        $sizes = array_map(intval(...), array_column([$width, $height], 1));
        if ($sizes !== [] && min($sizes) < 44) {
            $drawnSmall[$name[1]] = true;
        }
    }

    $onButtons = [];
    foreach (phoneUiButtonClassLists() as $classList) {
        foreach (preg_split('/\s+/', $classList) ?: [] as $class) {
            if (isset($drawnSmall[$class])) {
                $onButtons[$class] = true;
            }
        }
    }

    $unprotected = array_keys(array_filter(
        $onButtons,
        static fn (bool $_, string $class): bool => preg_match(
            '/\.'.preg_quote($class, '/').'(::after)?\s*[,{]/',
            implode('', phoneUiCoarsePointerBlocks())
        ) !== 1,
        ARRAY_FILTER_USE_BOTH
    ));

    expect($unprotected)->toBe([], sprintf(
        '%s sit on a <button> at a fixed size below 44px and the coarse-pointer floor will deform them',
        implode(', ', array_map(static fn (string $c): string => '.'.$c, $unprotected))
    ));
});
