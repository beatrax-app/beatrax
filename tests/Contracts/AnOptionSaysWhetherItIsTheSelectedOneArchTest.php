<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

// A browser picks a select's first option unless the markup marks one, and
// nothing in Livewire marks one for it: the value the component holds is
// server state, and the rendered option list is the only place the client
// learns which of them it belongs to. So a bound select whose options say
// nothing draws its first option whatever the component holds — the app lock
// read "1 minute" over a 5-minute window, the notification digest read "Daily"
// over a weekly one, and the drift threshold read "1%" over 5%.
//
// The second half is worse than the display: a reader cannot choose the option
// already on screen, because selecting the current option fires no change
// event. The three settings above could not be set to the value they claimed.
//
// Marking the placeholder too, not only the loop: an option list is re-rendered
// whenever the server changes the value — the import wizard's format sniffer
// does exactly that — and an unmarked list leaves the browser holding whatever
// it had.

/**
 * @return list<string> every Blade template in the two view trees
 */
function optionSelectionBladeFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['Modules', 'resources'] as $directory) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($walk as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

// Either spelling of the same statement: the directive, which is what almost
// every option here uses, or a literal attribute for an option that is always
// the chosen one. Read off the start tag the lexer cut, never off the file, so
// the label text cannot answer for the attributes.
function optionStatesItsSelection(string $startTag): bool
{
    return PatternScan::matches('/@selected\s*\(/', $startTag)
        || PatternScan::matches('/\sselected(\s|=|>|\/)/', $startTag);
}

it('marks, on every option, whether it is the one the component holds', function (): void {
    $offenders = [];
    $files = optionSelectionBladeFiles();
    $options = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::elements($source, 'option') as $option) {
            $options++;

            if (! optionStatesItsSelection($option->startTag)) {
                $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path).':'.$option->line($source);
            }
        }
    }

    // Both denominators before the verdict: a walk that opened no template and
    // a lexer that recognised no option both report the same clean tree as a
    // tree where every option is marked.
    expect(count($files))->toBeGreaterThan(
        100,
        'The walk opened '.count($files).' templates, which is too few to be the Blade tree.',
    );

    expect($options)->toBeGreaterThan(
        50,
        'The lexer found '.$options.' option elements in the whole Blade tree, which is what a reader '
        .'that stopped recognising the element looks like rather than a tree that stopped using selects.',
    );

    expect($offenders)->toBe([], sprintf(
        "These options say nothing about whether they are the selected one, so the\n"
        ."browser draws the first of their list and the reader cannot choose the one\n"
        ."already on screen. Add @selected(...) comparing against the bound property —\n"
        ."the placeholder included, since a re-render is what loses it:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

// A guard whose only verdict is "this list is empty" passes once its reader
// stops reading. The reader is driven here against strings, so a rewrite of it
// cannot quietly stop finding them.
it('reads a selection off both spellings and off none of the near misses', function (string $startTag, bool $states): void {
    expect(optionStatesItsSelection($startTag))->toBe($states);
})->with([
    'the directive' => ['<option value="{{ $code }}" @selected($base === $code)>', true],
    'the directive with a space' => ['<option value="a" @selected ($x)>', true],
    'a literal bare attribute' => ['<option value="a" selected>', true],
    'a literal valued attribute' => ['<option value="a" selected="selected">', true],
    'nothing at all' => ['<option value="{{ $code }}">', false],
    'a disabled option that says nothing else' => ['<option value="" @disabled($locked)>', false],
    'an attribute that merely ends in the word' => ['<option value="a" data-selected="1">', false],
    'the word as a value' => ['<option value="selected">', false],
]);
