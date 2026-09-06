<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A tab strip is four separate promises, and three of them were kept: the roles
// were there, the selection was announced, and nothing said which region a tab
// governs or how to walk the strip without a pointer. All four are checked here
// so the next strip cannot ship with only some of them.

/** @return list<string> */
function tabStripBladeFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $root = base_path($dir);
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Quote-aware, because Alpine expressions carry ">" inside attribute values.
 *
 * A Blade condition inside a tag does too — `@if (count($x) > 0)` guards the
 * forecast panel's attributes — so the directives go first and the tag is read
 * from what is left. Offsets survive because each is replaced by its own width.
 *
 * @return list<array{0: string, 1: string, 2: int}> tag name, attributes, byte offset
 */
function tabStripOpenTags(string $source): array
{
    $source = PatternScan::replaceCallback(
        '~@\w+\s*\((?:[^()]|\([^()]*\))*\)~',
        static fn (array $m): string => str_repeat(' ', strlen($m[0])),
        $source,
    );

    $matches = PatternScan::setsWithOffsets(
        '~<([a-zA-Z][\w:.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~s',
        $source,
    );

    $tags = [];
    foreach ($matches as $match) {
        $tags[] = [$match[1][0], $match[2][0], (int) $match[0][1]];
    }

    return $tags;
}

function tabStripLineOf(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

/**
 * What the three rules below have between them read, counted before any of
 * them reads a verdict. All three answer "no offenders" when the tag reader
 * stops recognising a tag, which is the same answer they give for a tree where
 * every strip is complete — and the Sonar rule that would otherwise notice a
 * broken accessibility contract does not exist for tabs.
 *
 * @return array{files: int, tags: int, tablists: int, tabs: int, panels: int}
 */
function tabStripDenominators(): array
{
    $seen = ['files' => 0, 'tags' => 0, 'tablists' => 0, 'tabs' => 0, 'panels' => 0];

    foreach (tabStripBladeFiles() as $path) {
        $seen['files']++;

        foreach (tabStripOpenTags((string) file_get_contents($path)) as [$tag, $attributes, $_]) {
            $seen['tags']++;

            if (str_contains($attributes, 'role="tablist"')) {
                $seen['tablists']++;
            }
            if ($tag === 'x-core::tab' || str_contains($attributes, 'role="tab"')) {
                $seen['tabs']++;
            }
            if (str_contains($attributes, 'role="tabpanel"')) {
                $seen['panels']++;
            }
        }
    }

    return $seen;
}

it('reads a strip, its tabs and their panels out of the tree at all', function (): void {
    $seen = tabStripDenominators();

    expect($seen['files'])->toBeGreaterThan(100, 'The walk opened '.$seen['files'].' templates, which is too few to be the Blade tree.')
        ->and($seen['tags'])->toBeGreaterThan(1000, 'The reader recognised '.$seen['tags'].' open tags in the whole tree, which is what a broken tag pattern looks like.')
        ->and($seen['tablists'])->toBeGreaterThan(2, 'The reader found '.$seen['tablists'].' role="tablist" elements, so the first rule below judged almost nothing.')
        ->and($seen['tabs'])->toBeGreaterThan(3, 'The reader found '.$seen['tabs'].' tabs, so the second and third rules below judged almost nothing.')
        ->and($seen['panels'])->toBeGreaterThan(2, 'The reader found '.$seen['panels'].' role="tabpanel" elements, so every aria-controls below would resolve to nothing and the third rule would report the whole tree.');
});

// The tag reader is what all three rules turn on, and the two shapes that
// break a naive one are both written in this tree: a ">" inside a Blade
// condition guarding an attribute, and a ">" inside a quoted Alpine
// expression. Either one splits the tag early, and the half that carried
// role="tablist" then reads as an element with no attributes — which is a rule
// passing over a strip it never saw.
it('reads a tag past an angle bracket in a directive and in a quoted value', function (): void {
    $source = <<<'BLADE'
        <div role="tablist" @if (count($views) > 1) aria-label="Views" @endif x-data="tabStrip()">
        <x-core::tab id="a" aria-controls="p" tabindex="0" x-on:click="open = $event.detail > 0" />
        </div>
        BLADE;

    $tags = tabStripOpenTags($source);

    expect(array_column($tags, 0))->toBe(['div', 'x-core::tab'])
        ->and(str_contains($tags[0][1], 'x-data="tabStrip()"'))->toBeTrue('the ">" inside the Blade condition closed the tag early, so everything after it was lost')
        ->and(str_contains($tags[1][1], 'aria-controls="p"'))->toBeTrue('the tag was read whole past the ">" in the quoted Alpine expression');
});

// The strip is the only thing that knows how many tabs there are, so the arrow
// keys have to live on it. Without them the strip renders tabindex="-1" on
// every tab but one and the rest become unreachable.
it('gives every tab strip the arrow-key handler its roving tabindex depends on', function (): void {
    $offenders = [];

    foreach (tabStripBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach (tabStripOpenTags($source) as [$_, $attributes, $offset]) {
            if (! str_contains($attributes, 'role="tablist"')) {
                continue;
            }

            $missing = [];
            if (! str_contains($attributes, 'aria-label')) {
                $missing[] = 'an accessible name';
            }
            if (! str_contains($attributes, 'x-data="tabStrip()"')) {
                $missing[] = 'x-data="tabStrip()"';
            }
            if (! str_contains($attributes, 'x-on:keydown="onKey($event)"')) {
                $missing[] = 'x-on:keydown="onKey($event)"';
            }

            if ($missing !== []) {
                $offenders[] = sprintf(
                    '%s:%d — role="tablist" without %s',
                    str_replace(base_path().'/', '', $path),
                    tabStripLineOf($source, $offset),
                    implode(' and ', $missing),
                );
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A role="tablist" is a promise that the strip behaves like one. The strip renders',
        'tabindex="-1" on every tab but the selected one, so without the arrow-key handler',
        'that moves the selection, every other tab is unreachable from the keyboard — and',
        'the strip is the only thing that knows how many tabs there are, so the handler',
        'cannot live on a tab. Give it x-data="tabStrip()", x-on:keydown="onKey($event)"',
        'and an aria-label naming what the strip switches between:',
        '',
        ...$offenders,
    ]));
});

// x-core::tab supplies role and aria-selected; id, aria-controls and the roving
// tabindex stay at the call site, because only the caller can name the panel.
it('points every tab at the panel it governs and keeps exactly one of them tabbable', function (): void {
    $sharedTabComponent = base_path('Modules/Core/Resources/views/components/tab.blade.php');
    $offenders = [];

    foreach (tabStripBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach (tabStripOpenTags($source) as [$tag, $attributes, $offset]) {
            $isCallSite = $tag === 'x-core::tab';
            $isRawTab = str_contains($attributes, 'role="tab"') && $path !== $sharedTabComponent;

            if (! $isCallSite && ! $isRawTab) {
                continue;
            }

            $required = ['id=', 'aria-controls=', 'tabindex='];
            if ($isRawTab) {
                $required[] = 'aria-selected';
            }

            $missing = array_values(array_filter(
                $required,
                static fn (string $needle): bool => ! str_contains($attributes, $needle),
            ));

            if ($missing !== []) {
                $offenders[] = sprintf(
                    '%s:%d — tab without %s',
                    str_replace(base_path().'/', '', $path),
                    tabStripLineOf($source, $offset),
                    implode(', ', $missing),
                );
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'x-core::tab supplies role and aria-selected; the rest cannot be supplied by the',
        'component because only the call site knows which panel this tab governs. Without',
        'id and aria-controls a screen reader announces a tab that governs nothing, and',
        'without tabindex the roving focus the strip moves has nothing to move:',
        '',
        ...$offenders,
    ]));
});

// aria-controls that resolves to nothing is worse than none at all: the reader
// is offered a jump to a region that is not there.
it('backs every aria-controls on a tab with a named tabpanel in the same template', function (): void {
    $offenders = [];

    foreach (tabStripBladeFiles() as $path) {
        $source = (string) file_get_contents($path);
        $relative = str_replace(base_path().'/', '', $path);

        foreach (tabStripOpenTags($source) as [$tag, $attributes, $offset]) {
            if ($tag !== 'x-core::tab' && ! str_contains($attributes, 'role="tab"')) {
                continue;
            }
            if (preg_match('~\baria-controls="([^"]+)"~', $attributes, $controls) !== 1) {
                continue;
            }

            $panel = null;
            foreach (tabStripOpenTags($source) as [$_, $panelAttributes, $__]) {
                if (str_contains($panelAttributes, 'role="tabpanel"')
                    && str_contains($panelAttributes, 'id="'.$controls[1].'"')) {
                    $panel = $panelAttributes;
                    break;
                }
            }

            if ($panel === null) {
                $offenders[] = sprintf('%s:%d — aria-controls="%s" names no role="tabpanel"', $relative, tabStripLineOf($source, $offset), $controls[1]);
            } elseif (! str_contains($panel, 'aria-labelledby')) {
                $offenders[] = sprintf('%s — the "%s" tabpanel has no aria-labelledby', $relative, $controls[1]);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'An aria-controls naming no element is worse than none at all: the reader is',
        'offered a jump to a region that is not there. A tabpanel with no aria-labelledby',
        'is the same promise broken the other way — the reader arrives and is told nothing',
        'about what they arrived at. Point each tab at a role="tabpanel" in the same',
        'template, and label that panel with the tab id:',
        '',
        ...$offenders,
    ]));
});
