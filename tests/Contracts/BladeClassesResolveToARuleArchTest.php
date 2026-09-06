<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

// A class written in a Blade template that matches no rule anywhere renders
// unstyled: the element is in the DOM, the route answers 200, every assertion
// passes, and the reader sees a broken control. Found on a real phone, where
// the onboarding balance card's amount field had no box at all.
/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

/**
 * Selectors in the compiled stylesheet, which carries every Tailwind utility
 * actually generated for this tree plus every custom rule.
 *
 * @return array<string, true>
 */
function builtCssClassNames(): array
{
    $built = glob(base_path('public/build/assets/app-*.css')) ?: [];

    expect($built)->not->toBe([], 'No compiled stylesheet under public/build/assets. Run `npm run build` — without it this invariant cannot be checked and must not be skipped.');

    $names = [];
    foreach ($built as $file) {
        $css = (string) file_get_contents($file);
        $matches = PatternScan::all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $css);
        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }
    }

    return $names;
}

/**
 * @param  list<string>  $blades
 * @return array<string, true>
 */
function bladeScopedClassNames(array $blades): array
{
    $names = [];
    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);
        foreach (MarkupSource::elements($source, 'style') as $block) {
            $matches = PatternScan::all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', (string) $block->inner);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }
    }

    return $names;
}

/**
 * @return list<string>
 */
function bladeTemplatePaths(): array
{
    $paths = [];
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));
    foreach ($tree as $file) {
        if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths);

    return $paths;
}

// `palette-row` carries no styling — the rows are dressed in utilities. It is
// how PaletteKeyboardPathTest spots a row before asserting it carries the
// `data-palette-row` the keyboard handler selects on, so the class is that
// check's independent signal and removing it would blind the assertion.
const MARKER_CLASSES_WITHOUT_A_RULE = [
    'palette-row',
];

// The claim is narrower than "every class": only a fully static class="…" in a
// module template, and within it only a hyphenated lowercase token. A class
// written into an @class([...]) array, a single-quoted attribute, a one-word
// utility like `flex`, a variant like `md:hidden`, and every template under
// resources/views are all outside what this reads — and the compiled stylesheet
// it compares against has to be built first, which is why the walk says so
// rather than skipping.
it('has every static hyphenated class in a module Blade template resolving to a rule', function (): void {
    $blades = bladeTemplatePaths();

    expect(count($blades))->toBeGreaterThan(
        100,
        'The walk opened almost no module template, so the empty offender list below is a tree nobody read.',
    );

    $defined = builtCssClassNames() + bladeScopedClassNames($blades)
        + array_flip(MARKER_CLASSES_WITHOUT_A_RULE);

    expect(count($defined))->toBeGreaterThan(
        500,
        'Almost no selector was read out of the compiled stylesheet, so every class below would report as unstyled.',
    );

    $hits = [];
    $tokensRead = 0;
    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);

        // Only fully static attributes: one holding a Blade expression can
        // name a class this scan has no way to evaluate.
        $attributes = PatternScan::all('/class="([^"{}@]*)"/', $source);

        foreach ($attributes[1] as $attribute) {
            foreach (PatternScan::split('/\s+/', trim($attribute)) as $token) {
                if (! PatternScan::matches('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $token)) {
                    continue;
                }
                $tokensRead++;
                if (isset($defined[$token])) {
                    continue;
                }

                $hits[] = str_replace(base_path().'/', '', $path).' → .'.$token;
            }
        }
    }

    $hits = array_values(array_unique($hits));
    sort($hits);

    expect($tokensRead)->toBeGreaterThan(
        500,
        'No hyphenated class token was read from any template, so the verdict below is about markup nobody parsed.',
    );

    expect($hits)->toBe([], "A class in a Blade template must match a rule in the compiled stylesheet or in that template's own <style> block. These match nothing and render unstyled.\nRun `npm run build` first: the compiled sheet is read as-is, so a utility added to a template since the last build reads as unstyled here.\n  ".implode("\n  ", $hits));
});

// A marker class nothing carries is an exemption for a class that is not there,
// and the entry then stands ready to excuse whatever takes the name next.
it('keeps no marker class no template still carries', function (): void {
    $carried = [];

    foreach (bladeTemplatePaths() as $path) {
        $source = (string) file_get_contents($path);

        foreach (MARKER_CLASSES_WITHOUT_A_RULE as $marker) {
            foreach (PatternScan::all('/class="([^"{}@]*)"/', $source)[1] as $attribute) {
                if (in_array($marker, PatternScan::split('/\s+/', trim($attribute)), true)) {
                    $carried[$marker] = true;
                }
            }
        }
    }

    expect(array_values(array_diff(MARKER_CLASSES_WITHOUT_A_RULE, array_keys($carried))))->toBe([], implode("\n  ", [
        'These classes are excused from needing a rule and no template carries one. The exemption covers',
        'nothing, and the next element to take the name inherits it — delete the entry.',
    ]));
});
