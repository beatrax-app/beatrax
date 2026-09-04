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

it('has every class in a Blade template resolving to a rule', function (): void {
    $blades = bladeTemplatePaths();
    $defined = builtCssClassNames() + bladeScopedClassNames($blades)
        + array_flip(MARKER_CLASSES_WITHOUT_A_RULE);

    $hits = [];
    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);

        // Only fully static attributes: one holding a Blade expression can
        // name a class this scan has no way to evaluate.
        $attributes = PatternScan::all('/class="([^"{}@]*)"/', $source);

        foreach ($attributes[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $token) {
                if (! PatternScan::matches('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $token)) {
                    continue;
                }
                if (isset($defined[$token])) {
                    continue;
                }

                $hits[] = str_replace(base_path().'/', '', $path).' → .'.$token;
            }
        }
    }

    $hits = array_values(array_unique($hits));
    sort($hits);

    expect($hits)->toBe([], "A class in a Blade template must match a rule in the compiled stylesheet or in that template's own <style> block. These match nothing and render unstyled.\nRun `npm run build` first: the compiled sheet is read as-is, so a utility added to a template since the last build reads as unstyled here.\n  ".implode("\n  ", $hits));
});
