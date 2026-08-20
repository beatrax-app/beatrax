<?php

declare(strict_types=1);

/*
 * Livewire binds wire:id to the FIRST top-level element of a component view.
 * A second root is not a layout quirk — it silently unbinds everything after
 * it.
 *
 * /pots had a <style> block beside its wrapper div, so <style> became the
 * component. Measured on a Galaxy: button.closest('[wire:id]') returned null,
 * zero requests to /livewire/update across a whole deposit flow, and the
 * component still read {"potId":0,"amt":""} after typing. Every write on the
 * page did nothing, with no error — while the sheets still opened, because
 * $dispatch is a plain window event and needs no binding.
 *
 * That shape is indistinguishable from a working page until you check the
 * database, which is why it survived a device pass that "used" the feature.
 */

/** @return list<string> */
function rootTagsOf(string $source): array
{
    // Blade comments, directives and PHP blocks are not elements.
    $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    $stripped = (string) preg_replace('/@(php|use|verbatim).*?@end\1/s', '', $stripped);
    $stripped = (string) preg_replace('/^\s*@[a-zA-Z]+.*$/m', '', $stripped);
    $stripped = (string) preg_replace('/<\?php.*?\?>/s', '', $stripped);

    $depth = 0;
    $roots = [];
    $void = ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'track', 'wbr', 'area', 'base', 'col', 'embed', 'param'];

    preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>#s', $stripped, $tags, PREG_SET_ORDER);

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
