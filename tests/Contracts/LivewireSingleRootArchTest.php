<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-second-root-element-in-a-livewire-view
 */

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

it('gives every Livewire component view exactly one root element', function (): void {
    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
            continue;
        }

        // Only component roots. partials/ and components/ are includes, which
        // are rendered inside a root that already exists.
        if (! str_contains($path, '/Resources/views/livewire/')) {
            continue;
        }

        // partials/, components/ and _underscore files are includes, rendered
        // inside a root that already exists.
        if (str_contains($path, '/partials/') || str_contains($path, '/components/')) {
            continue;
        }

        if (str_starts_with($file->getBasename(), '_')) {
            continue;
        }

        $roots = rootTagsOf((string) file_get_contents($path));

        if (count($roots) > 1) {
            $offenders[] = str_replace(base_path().'/', '', $path).' — roots: '.implode(', ', $roots);
        }
    }

    expect($offenders)->toBe([], sprintf(
        "Livewire binds wire:id to the first root; anything after it is unbound:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
