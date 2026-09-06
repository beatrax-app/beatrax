<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-second-root-element-in-a-livewire-view
 */

// Matched case-insensitively: a module spells it Resources/views/livewire and
// the application's own tree spells it resources/views/livewire, and a
// component view is a component view under either.
const LIVEWIRE_COMPONENT_VIEW_PATH = '/resources/views/livewire/';

// An include is rendered inside a root the including component already opened,
// so its own root count is a claim about nothing. Both spellings are checked
// against the walk below, so an exemption that stops excusing anything is
// deleted rather than left reading as considered.
const LIVEWIRE_ROOT_EXEMPTIONS = [
    '/partials/' => 'a directory of includes, each rendered inside a root the including component opened',
    '_' => 'the underscore prefix, this tree\'s other spelling for an include',
];

/** the exemption excusing $path from the single-root rule, or null */
function livewireRootExemptionFor(string $path): ?string
{
    if (str_contains($path, '/partials/')) {
        return '/partials/';
    }

    return str_starts_with(basename($path), '_') ? '_' : null;
}

/** @return list<string> */
function rootTagsOf(string $source): array
{
    // Blade comments, directives and PHP blocks are not elements.
    $stripped = PatternScan::replace('/\{\{--.*?--\}\}/s', '', $source);
    $stripped = PatternScan::replace('/@(php|use|verbatim).*?@end\1/s', '', $stripped);
    $stripped = PatternScan::replace('/^\s*@[a-zA-Z]+.*$/m', '', $stripped);
    $stripped = PatternScan::replace('/<\?php.*?\?>/s', '', $stripped);

    $depth = 0;
    $roots = [];
    $void = ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'track', 'wbr', 'area', 'base', 'col', 'embed', 'param'];

    $tags = PatternScan::sets('#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>#s', $stripped);

    foreach ($tags as $tag) {
        [$whole, $closing, $name, $attrs] = $tag;
        $name = strtolower($name);

        if (in_array($name, $void, true) || str_ends_with(trim($attrs), '/')) {
            if ($depth === 0) {
                $roots[] = $name;
            }

            continue;
        }

        if ($closing === '/') {
            $depth--;

            continue;
        }

        if ($depth === 0) {
            $roots[] = $name;
        }

        $depth++;
    }

    return $roots;
}

/** @return list<string> absolute paths to every Livewire component view a reader is shown */
function livewireComponentViews(): array
{
    $views = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        if (stripos($path, LIVEWIRE_COMPONENT_VIEW_PATH) !== false) {
            $views[] = $path;
        }
    }

    return $views;
}

it('gives every Livewire component view exactly one root element', function (): void {
    $views = livewireComponentViews();
    $scanned = 0;
    $offenders = [];

    foreach ($views as $path) {
        if (livewireRootExemptionFor($path) !== null) {
            continue;
        }

        $scanned++;
        $roots = rootTagsOf((string) file_get_contents($path));

        if (count($roots) > 1) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' — roots: '.implode(', ', $roots);
        }
    }

    // Read before the verdict: a path filter that stopped matching leaves this
    // rule green over nothing. The floor sits far under today's 140.
    expect($scanned)->toBeGreaterThan(
        80,
        'the walk read '.$scanned.' component views of '.count($views).' found, which is too few to be this tree.'
    );

    expect($offenders)->toBe([], sprintf(
        "Livewire binds wire:id to the first root; anything after it is unbound:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

// An exemption that excuses nothing reads as considered and protects nothing.
// A third one — `/components/` — was carried here for as long as this rule
// existed and never matched a file, so it is gone and this is what would have
// said so.
it('still holds each exemption to a view it actually excuses', function (): void {
    $excused = [];

    foreach (array_keys(LIVEWIRE_ROOT_EXEMPTIONS) as $exemption) {
        $excused[$exemption] = 0;
    }

    foreach (livewireComponentViews() as $path) {
        $exemption = livewireRootExemptionFor($path);

        if ($exemption !== null && count(rootTagsOf((string) file_get_contents($path))) > 1) {
            $excused[$exemption]++;
        }
    }

    $dead = array_keys(array_filter($excused, static fn (int $count): bool => $count === 0));

    expect($dead)->toBe([], implode("\n", [
        'These exemptions excuse no multi-root view at all, so they read as a decision and do nothing:',
        ...array_map(static fn (string $key): string => $key.' — '.LIVEWIRE_ROOT_EXEMPTIONS[$key], $dead),
        '',
        'Delete the entry. If the shape it names is coming back, it can come back with the file.',
    ]));
});

// The rule is a list that is empty over a clean tree and empty over a walk that
// read nothing, so the reader is driven against planted markup.
it('counts a second root element and does not count the things that are not elements', function (): void {
    expect(rootTagsOf('<div>a</div><div>b</div>'))->toBe(['div', 'div'])
        ->and(rootTagsOf('<div><span>a</span></div>'))->toBe(['div'])
        ->and(rootTagsOf('<div><br>a</div>'))->toBe(['div'])
        ->and(rootTagsOf('<img src="x"><div>a</div>'))->toBe(['img', 'div'])
        ->and(rootTagsOf("{{-- <div>x</div> --}}\n<div>a</div>"))->toBe(['div'])
        ->and(rootTagsOf("@if (\$x)\n<div>a</div>\n@endif"))->toBe(['div'])
        ->and(rootTagsOf("<?php \$x = '<div>'; ?>\n<div>a</div>"))->toBe(['div']);
});
