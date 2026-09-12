<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupElement;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

// A browser picks a select's first option unless the markup marks one, and
// nothing in Livewire marks one for it: the value is server state, and the
// rendered option list is the only place the client learns which of them it
// belongs to. So a select whose value lives in Livewire and whose options say
// nothing draws its first option whatever the component holds — the app lock
// read "1 minute" over a 5-minute window, the notification digest read "Daily"
// over a weekly one, and the drift threshold read "1%" over 5%.
//
// The second half is worse than the display: a reader cannot choose the option
// already on screen, because selecting the current option fires no change
// event. The three settings above could not be set to the value they claimed.
//
// Alpine's x-model is the exception, and the reason the rule is about
// provenance rather than about every select on the page: x-model *does* write
// the element's value from its own state on initialisation, so a select whose
// value lives only in Alpine needs no rendered selection and gets no demand
// for one here.

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

// Either spelling of the same statement: the directive, which is what the tree
// uses, or a literal attribute for an option that is always the chosen one.
// Read off the start tag the lexer cut, never off the file, so an option's
// label text cannot answer for its attributes.
function optionStatesItsSelection(string $startTag): bool
{
    return PatternScan::matches('/@selected\s*\(/', $startTag)
        || PatternScan::matches('/\sselected(\s|=|>|\/)/', $startTag);
}

// A select answers for its options when Livewire holds the value — a
// `wire:`-prefixed attribute on the select itself — or when the template
// already renders a selection for one of them, which is a statement that this
// list's selection is the server's to make. A list where neither is true is
// Alpine's or a plain form's, and x-model sets those itself.
/**
 * @param  list<MarkupElement>  $options
 */
function optionListIsServerSelected(MarkupElement $select, array $options): bool
{
    foreach (array_keys($select->attributes()) as $attribute) {
        if (str_starts_with($attribute, 'wire:')) {
            return true;
        }
    }

    return array_any($options, static fn (MarkupElement $option): bool => optionStatesItsSelection($option->startTag));
}

// The options of every select in one template, plus the ones that belong to no
// select in it. A fragment holding nothing but options — the shared country
// list is one — is always the server's: the select it lands inside is in
// another file, and this walk would otherwise never open it.
//
// Every option is taken from the whole source rather than from a select's
// inner, because an element's offset is what names the line it is on and an
// inner-relative one named line three of a four-hundred-line template.
/**
 * @return list<MarkupElement>
 */
function optionsAnsweredByTheServer(string $source): array
{
    $selects = [];

    foreach (MarkupSource::elements($source, 'select') as $select) {
        if ($select->inner === null) {
            continue;
        }

        $from = $select->offset + strlen($select->startTag);
        $selects[] = [
            'from' => $from,
            'to' => $from + strlen($select->inner),
            'server' => optionListIsServerSelected($select, MarkupSource::elements($select->inner, 'option')),
        ];
    }

    $answered = [];

    foreach (MarkupSource::elements($source, 'option') as $option) {
        $owner = null;
        foreach ($selects as $select) {
            if ($option->offset >= $select['from'] && $option->offset < $select['to']) {
                $owner = $select;
            }
        }

        if ($owner === null || $owner['server'] === true) {
            $answered[] = $option;
        }
    }

    return $answered;
}

it('marks, on every option Livewire answers for, whether it is the one it holds', function (): void {
    $offenders = [];
    $files = optionSelectionBladeFiles();
    $options = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        foreach (optionsAnsweredByTheServer($source) as $option) {
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
        'The reader found '.$options.' server-selected option elements in the whole Blade tree, which is what '
        .'a walk that stopped recognising the element looks like rather than a tree that stopped using selects.',
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
// stops reading. Both readers are driven here against strings, so a rewrite of
// either cannot quietly stop finding offenders.
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
    'an echoed ternary, which is the same statement in a spelling nothing reads' => ['<option value="a" {{ $x ? \'selected\' : \'\' }}>', false],
]);

it('answers for the lists the server owns and leaves the ones Alpine sets alone', function (string $source, int $answered): void {
    expect(count(optionsAnsweredByTheServer($source)))->toBe($answered);
})->with([
    'a wire:model select' => ['<select wire:model="x"><option value="a">A</option></select>', 1],
    'a wire:change select' => ['<select wire:change="set($event.target.value)"><option value="a">A</option></select>', 1],
    'a list whose loop already marks one' => ['<select x-on:change="$wire.pick"><option value="">—</option><option value="a" @selected($x)>A</option></select>', 2],
    'an x-model select that marks nothing' => ['<select x-model="picked"><option value="">—</option><option value="a">A</option></select>', 0],
    'a fragment of options with no select of its own' => ['<option value="" @selected($none)>—</option><option value="a">A</option>', 2],
    'a select with no options at all' => ['<select wire:model="x">{{ $slot }}</select>', 0],
]);
