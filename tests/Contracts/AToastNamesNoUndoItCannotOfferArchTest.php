<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;

/**
 * @link ../../.docs/conventions/which-actions-ask-before-they-act.md
 */

// The undo button is drawn from the dispatched payload — the host renders it
// behind x-show="t.undoAction" — so only toastWithUndo() puts one on screen.
// A plain toast() whose line ends in the word is a sentence pretending to be a
// control: nothing to press, and the reader believes the removal is undoable.
it('never writes the word for undo into a toast that dispatches none', function (): void {
    $translator = app(Translator::class);
    $offenders = [];

    foreach (toastOnlyCalls() as [$path, $line, $key]) {
        $copy = $translator->get($key, [], 'en', false);

        if (is_string($copy) && preg_match('~\bundo\b~i', $copy) === 1) {
            $offenders[] = sprintf('%s:%d — toast(%s) reads "%s"', $path, $line, $key, $copy);
        }
    }

    expect($offenders)->toBe([], "Say it with toastWithUndo() and an inverse, or do not say it:\n  ".implode("\n  ", $offenders));
});

/** @return list<array{0: string, 1: int, 2: string}> every plain toast() call and the key it names */
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
        preg_match_all(
            '~->toast\(\s*Lang::(?:get|choice)\(\s*\'([^\']+)\'~',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $calls[] = [
                str_replace(base_path().'/', '', $file->getPathname()),
                substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
                $match[1][0],
            ];
        }
    }

    expect($calls)->not->toBe([]);

    return $calls;
}
