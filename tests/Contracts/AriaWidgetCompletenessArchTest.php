<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupElement;
use Modules\Core\Public\Support\MarkupSource;

// A control that takes an ARIA role has to carry the state the native element
// would have supplied for free: a <progress> reports its value unasked, a div
// with role="progressbar" and no aria-valuenow announces nothing. Presence only
// — an aria-checked stuck at "false" passes, and a template cannot be read for it.

/**
 * @return list<string>
 */
function ariaCompletenessBladeFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
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

function ariaCompletenessHasName(MarkupElement $element): bool
{
    foreach (['aria-label', 'aria-labelledby', ':aria-label', ':aria-labelledby'] as $named) {
        if ($element->hasAttribute($named)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, array{check: callable(MarkupElement): bool, missing: string}>
 */
function ariaCompletenessRules(): array
{
    $named = static fn (MarkupElement $element): bool => ariaCompletenessHasName($element);
    $has = static fn (string ...$needles): callable => static function (MarkupElement $element) use ($needles): bool {
        foreach ($needles as $needle) {
            if ($element->hasAttribute($needle) || $element->hasAttribute(':'.$needle)) {
                return true;
            }
        }

        return false;
    };

    return [
        'dialog' => ['check' => $named, 'missing' => 'an accessible name (aria-label/aria-labelledby)'],
        'group' => ['check' => $named, 'missing' => 'an accessible name — an unnamed group conveys nothing a plain div would not'],
        'img' => ['check' => $named, 'missing' => 'an accessible name — role="img" without one announces an empty image'],
        'listbox' => ['check' => $named, 'missing' => 'an accessible name'],
        'radiogroup' => ['check' => $named, 'missing' => 'an accessible name'],
        'option' => ['check' => $has('aria-selected'), 'missing' => 'aria-selected — the selection is never announced without it'],
        'radio' => ['check' => $has('aria-checked'), 'missing' => 'aria-checked — the selection is never announced without it'],
        'progressbar' => ['check' => $has('aria-valuenow', 'aria-valuetext'), 'missing' => 'aria-valuenow (or aria-valuetext when indeterminate)'],
    ];
}

// A walk that opened nothing reports the same clean tree as a walk that found
// nothing. The two roots hold 279 templates carrying 121 roled widgets between
// them, and both floors sit far under those.
const ARIA_COMPLETENESS_TEMPLATE_FLOOR = 150;

const ARIA_COMPLETENESS_WIDGET_FLOOR = 40;

// This stands in for Web:S6819, switched off for Blade in
// sonar-project.properties: that rule asks only whether a native element exists
// for the role, and its answer was wrong for all 57 remaining findings — an
// Alpine popover is not <dialog>, a Livewire picker is not <select>.
it('gives every ARIA-roled widget the state its native element would have carried', function (): void {
    $rules = ariaCompletenessRules();
    $offenders = [];
    $templates = 0;
    $widgets = 0;

    foreach (ariaCompletenessBladeFiles() as $path) {
        $source = (string) file_get_contents($path);
        $templates++;

        foreach (MarkupSource::tags($source) as $element) {
            $role = $element->attribute('role');

            if ($role === null || ! isset($rules[$role])) {
                continue;
            }

            $widgets++;

            // A role on a hidden element is not announced at all, so there is
            // no name or state for it to be missing.
            if ($element->attribute('aria-hidden') === 'true') {
                continue;
            }

            if (($rules[$role]['check'])($element)) {
                continue;
            }

            $offenders[] = sprintf(
                '%s:%d — role="%s" without %s',
                str_replace(dirname(__DIR__, 2).'/', '', $path),
                $element->line($source),
                $role,
                $rules[$role]['missing'],
            );
        }
    }

    expect($templates)->toBeGreaterThan(
        ARIA_COMPLETENESS_TEMPLATE_FLOOR,
        'The walk opened '.$templates.' templates, so a clean answer here is a walk that read almost nothing.'
    );

    expect($widgets)->toBeGreaterThan(
        ARIA_COMPLETENESS_WIDGET_FLOOR,
        'The lexer found '.$widgets.' roled widgets in '.$templates
        .' templates, which is what a reader that stopped recognising a tag looks like: no widget found is none to judge.'
    );

    expect($offenders)->toBe([], "An ARIA role must carry the state its native element implies. Offenders:\n  ".implode("\n  ", $offenders));
});

// A radio outside a radiogroup, or an option outside a listbox, is announced as
// a lone control with no set to be one of. Native <input type="radio"> gets that
// from its name attribute and <option> from its <select>; a roled widget only
// gets it from the container being present. Read per TEMPLATE and not per
// ancestor: a Blade partial holds a fragment rather than a tree, so enclosure
// is not a question a single file can be asked.
it('draws no roled radio or option in a template that names no container role', function (): void {
    $containers = ['radio' => 'radiogroup', 'option' => 'listbox'];
    $offenders = [];

    foreach (ariaCompletenessBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach ($containers as $child => $container) {
            if (! str_contains($source, 'role="'.$child.'"')) {
                continue;
            }

            if (! str_contains($source, 'role="'.$container.'"')) {
                $offenders[] = sprintf('%s — has role="%s" but no role="%s" around it', $path, $child, $container);
            }
        }
    }

    expect($offenders)->toBe([], "A roled child needs its container role in the same template. Offenders:\n  ".implode("\n  ", $offenders));
});

// A guard that cannot go red says nothing. Each rule is checked against the
// widget it was written for and against the one that satisfies it, rather than
// against the tree.
it('reads the state each roled widget has to carry', function (string $markup, bool $complete): void {
    $rules = ariaCompletenessRules();
    $element = MarkupSource::tags($markup)[0];
    $role = (string) $element->attribute('role');

    expect(($rules[$role]['check'])($element))->toBe(
        $complete,
        'The rule for role="'.$role.'" answered '.var_export(! $complete, true).' for: '.$markup
    );
})->with([
    'a named dialog' => ['<div role="dialog" aria-label="Filters"></div>', true],
    'a dialog named by reference' => ['<div role="dialog" aria-labelledby="h"></div>', true],
    'a dialog named by an Alpine binding' => ['<div role="dialog" :aria-label="title"></div>', true],
    'an unnamed dialog' => ['<div role="dialog"></div>', false],
    'an unnamed group' => ['<div role="group"></div>', false],
    'a roled image with no name' => ['<div role="img"></div>', false],
    'an option announcing its selection' => ['<li role="option" aria-selected="true"></li>', true],
    'an option announcing nothing' => ['<li role="option"></li>', false],
    'a radio announcing its state' => ['<div role="radio" aria-checked="false"></div>', true],
    'a radio announcing nothing' => ['<div role="radio"></div>', false],
    'a progressbar with a value' => ['<div role="progressbar" aria-valuenow="40"></div>', true],
    'an indeterminate progressbar with words' => ['<div role="progressbar" aria-valuetext="Working"></div>', true],
    'a progressbar with neither' => ['<div role="progressbar"></div>', false],
]);
