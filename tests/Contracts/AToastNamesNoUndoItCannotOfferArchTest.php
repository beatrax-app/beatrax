<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/which-actions-ask-before-they-act.md
 */

// The undo button is drawn from the dispatched payload — the host renders it
// behind x-show="t.undoAction" — so only toastWithUndo() puts one on screen.
// A plain toast() whose line ends in the word is a sentence pretending to be a
// control: nothing to press, and the reader believes the removal is undoable.
//
// The English line only, and only where the key is a literal: a key assembled
// from a constant or a ternary names no string this scan can resolve, and the
// twenty-five other locales are read by nothing here.
it('never writes the English word for undo into a toast that names a literal key and dispatches none', function (): void {
    $translator = app(Translator::class);
    $offenders = [];

    foreach (toastOnlyCalls() as [$path, $line, $key]) {
        $copy = $translator->get($key, [], 'en', false);

        if (is_string($copy) && toastLineNamesUndo($copy)) {
            $offenders[] = sprintf('%s:%d — toast(%s) reads "%s"', $path, $line, $key, $copy);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'Say it with toastWithUndo() and an inverse, or do not say it:',
        ...$offenders,
    ]));
});

/** Whether an English toast line offers the reader an undo it has no button for. */
function toastLineNamesUndo(string $copy): bool
{
    return preg_match('~\bundo\b~i', $copy) === 1;
}

// A guard that cannot go red says nothing, and this one reads its verdict off a
// list that is empty on a clean tree and on a broken translator alike.
it('reads a line that offers an undo and leaves a word that merely contains it alone', function (): void {
    expect(toastLineNamesUndo('Rule deleted. Undo'))->toBeTrue('a line ending in the word went unreported');
    expect(toastLineNamesUndo('Budget undone for this month'))->toBeFalse('a longer word containing the stem was read as the word');
    expect(toastLineNamesUndo('Rule deleted.'))->toBeFalse('a line that offers nothing was reported as offering an undo');
});

/**
 * Every plain toast() call whose key is written out as a single-quoted literal.
 * A key reached through a constant or chosen by a ternary is invisible here.
 *
 * @return list<array{0: string, 1: int, 2: string}>
 */
function toastOnlyCalls(): array
{
    $calls = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getPathname(), '.php')) {
            continue;
        }
        if (! str_contains($file->getPathname(), '/Http/Livewire/')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // `->toast(` only: the `WithUndo` suffix is what carries the button, so
        // the boundary after "toast" is the whole distinction being drawn.
        $matches = PatternScan::setsWithOffsets(
            '~->toast\(\s*Lang::(?:get|choice)\(\s*\'([^\']+)\'~',
            $source,
        );

        foreach ($matches as $match) {
            $calls[] = [
                str_replace(base_path().'/', '', $file->getPathname()),
                substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
                $match[1][0],
            ];
        }
    }

    // Ninety-one such calls stand on this tree. A run that resolved a handful
    // read a broken scan, not a screen that stopped offering an undo.
    expect(count($calls))->toBeGreaterThan(
        50,
        'The walk found almost no literal-key toast() calls, so the verdict above is about a tree nobody read.',
    );

    return $calls;
}
