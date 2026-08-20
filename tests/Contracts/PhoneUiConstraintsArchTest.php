<?php

declare(strict_types=1);

/*
 * Two phone constraints that are cheap to satisfy and expensive to notice
 * having lost: the iOS auto-zoom threshold, and a dialog whose accessible
 * name is a second copy of its own heading.
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

it('keeps every touch form control at or above the iOS auto-zoom threshold', function (): void {
    // iOS zooms the page in when a focused control renders below 16px and does
    // not zoom back out. The viewport deliberately allows zoom, so raising the
    // control is the only remedy that does not break pinch-zoom for everyone.
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
    // 29 of 30 routes measured under Apple's minimum before this rule. It has
    // to be unlayered or `h-10` outranks it.
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

    // The same sheet is both "create" and "edit". A duplicated string went
    // stale on the Livewire round-trip that updated the heading, so the
    // dialog announced "create" while the form was editing.
    expect($sheet)->toContain('aria-labelledby=')
        ->and($sheet)->not->toContain('aria-label="{{ $title }}"');
});

it('never leaves the sheet dialog without an accessible name', function (): void {
    $sheet = (string) file_get_contents(base_path('Modules/Core/Resources/views/components/bottom-sheet.blade.php'));

    // A title can be conditional at the call site — the calendar's evaluates
    // to '' before a day is picked — so the empty branch has to name the
    // dialog too, or role="dialog" ships anonymous.
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

    // A phone row's Withdraw button dispatched open-sheet {name:'pot-withdraw'}
    // when the only thing carrying that name was a flux:modal, which the phone
    // never opens. The button was inert and the page looked complete, so money
    // could go into a pot and not come out.
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
