<?php

declare(strict_types=1);

// Measured on an iPhone 12 mini: the Gmail OAuth wizard's dialog rendered 407px
// wide on a 375pt screen with 46 elements past the right edge — the consent
// scope URL and "Publish App" both cut off mid-word. 36rem is 576px, so the
// max-width every Flux modal in the tree shares never applied on a phone.

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

// Brace-matched rather than read as a fixed window after the selector: a
// declaration this test asserts on sits at whatever offset the rule happens to
// put it, and a window short enough to miss it reports the cap as absent while
// a window long enough to spill into the next rule reports a neighbour's.
function declarationBlockFor(string $css, string $selector): string
{
    $selectorAt = strpos($css, $selector);
    expect($selectorAt)->not->toBeFalse('No rule found for selector: '.$selector);

    $open = strpos($css, '{', (int) $selectorAt);
    expect($open)->not->toBeFalse('Selector has no declaration block: '.$selector);

    $depth = 0;
    $length = strlen($css);
    for ($cursor = (int) $open; $cursor < $length; $cursor++) {
        $depth += (int) ($css[$cursor] === '{') - (int) ($css[$cursor] === '}');
        if ($depth === 0) {
            return substr($css, (int) $open, $cursor - (int) $open + 1);
        }
    }

    return '';
}

it('caps every Flux dialog at the screen it is drawn on', function (): void {
    expect(declarationBlockFor($this->css, '[data-flux-modal] > dialog:where([class~="[:where(&)]:max-w-xl"])'))
        ->toContain('max-width: min(36rem, calc(100vw - 1rem));');
});

// min-width always beats max-width, so an uncapped floor puts the overflow back.
it('caps the dialog floor as well as its ceiling', function (): void {
    expect(declarationBlockFor($this->css, '[data-flux-modal] > dialog:where([class~="[:where(&)]:min-w-xs"])'))
        ->toContain('min-width: min(20rem, calc(100vw - 1rem));');
});

// The cap alone still left three elements outside the dialog: a 46-character
// URL has no break opportunity of its own.
it('lets an unbreakable string inside a dialog wrap', function (): void {
    expect(declarationBlockFor($this->css, '[data-flux-modal] > dialog p'))
        ->toContain('overflow-wrap: anywhere;');
});

it('reaches every modal in the tree, not just the one that overflowed', function (): void {
    $modals = [];

    $directories = [base_path('Modules'), base_path('resources/views')];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        /** @var Iterator<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), '<flux:modal')) {
                $modals[] = $file->getPathname();
            }
        }
    }

    // The rule is on the element, not on any one blade, so a new modal inherits
    // it. This only guards that the shape it targets is still the shape in use.
    expect($modals)->not->toBeEmpty()
        ->and($this->css)->toContain('[data-flux-modal] > dialog');
});
