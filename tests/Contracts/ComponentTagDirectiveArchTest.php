<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-blade-directive-inside-a-component-tag
 */

/** @return list<string> every Blade view a reader is shown, and the module templates beside them */
function componentTagBladeFiles(): array
{
    return RepoTree::files(RepoTree::EVERY_BLADE_VIEW);
}

/**
 * The line of every `x-…` tag whose own start tag carries a Blade control
 * directive. Named and taking a source string so the control below drives the
 * same reader the walk drives.
 *
 * @return list<int> 1-based line numbers
 */
function componentTagDirectivesIn(string $source): array
{
    $lines = [];

    foreach (MarkupSource::tags($source) as $element) {
        if (! str_starts_with($element->name, 'x-')) {
            continue;
        }

        if (preg_match('/@(if|unless|else|elseif|endif|endunless|foreach|endforeach|for|endfor|isset|empty)\b/', $element->startTag) !== 1) {
            continue;
        }

        $lines[] = $element->line($source);
    }

    return $lines;
}

it('never puts a Blade directive inside a component tag', function (): void {
    $files = componentTagBladeFiles();

    // The floor sits well under the 279 templates this tree ships. A walk that
    // opened none of them reports a clean tree over markup nobody read.
    expect(count($files))->toBeGreaterThan(
        100,
        'The Blade walk opened almost nothing, so no component tag was read at all.'
    );

    $offenders = [];
    $componentTags = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::tags($source) as $element) {
            if (str_starts_with($element->name, 'x-')) {
                $componentTags++;
            }
        }

        foreach (componentTagDirectivesIn($source) as $line) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).':'.$line;
        }
    }

    expect($componentTags)->toBeGreaterThan(
        500,
        'The tag reader found no x- component at all, so it has stopped seeing the shape this rule is about.'
    );

    expect($offenders)->toBe([], 'Blade emits these component tags as raw HTML, so the component never renders: '.implode(', ', $offenders));
});

// The guard is worth exactly its ability to go red, and a tag reader that
// silently matched nothing would not be.
it('sees a directive inside a component tag, and leaves one beside it alone', function (): void {
    $offending = <<<'BLADE'
        <div>
            <x-core::button @if($ready) wire:click="save" @endif>Save</x-core::button>
        </div>
        BLADE;

    $nearMiss = <<<'BLADE'
        <div>
            @if($ready)
                <x-core::button wire:click="save">Save</x-core::button>
            @endif
            <x-core::badge :label="$name" />
        </div>
        BLADE;

    expect(componentTagDirectivesIn($offending))->toBe([2]);
    expect(componentTagDirectivesIn($nearMiss))->toBe([], 'A directive wrapping a component tag from outside is the correct shape and must not be reported.');
});
