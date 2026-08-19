<?php

declare(strict_types=1);

/*
 * iOS zooms the page in when a focused form control renders below 16px and
 * does not zoom back out. app.css carries a coarse-pointer floor for exactly
 * this, but an inline style beats any stylesheet rule — so a control that sets
 * its own font-size below 16px defeats the floor silently.
 *
 * Measured on an iPhone after the floor landed: the tax-tag note textarea
 * still computed 15px, because its size is written inline on the element.
 */

/** @return list<string> every blade a phone can render */
function inlineFontSizeCandidateViews(): array
{
    $views = array_merge(
        glob(base_path('Modules/*/Resources/views/**/*.blade.php')) ?: [],
        glob(base_path('Modules/*/Resources/views/*.blade.php')) ?: [],
        glob(base_path('resources/views/**/*.blade.php')) ?: [],
        glob(base_path('resources/views/*.blade.php')) ?: [],
    );

    // The recursive glob misses a level on some platforms; walk to be sure.
    foreach ([base_path('Modules'), base_path('resources/views')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile()
                && str_ends_with((string) $file->getFilename(), '.blade.php')
                && str_contains((string) $file->getPathname(), '/Resources/views/')) {
                $views[] = (string) $file->getPathname();
            }
        }
    }

    return array_values(array_unique($views));
}

// The design tokens that resolve below the threshold. A control may size
// itself inline, but not with one of these.
const INLINE_FONT_SIZES_UNDER_16 = [
    'var(--text-xs)',
    'var(--text-xs, 12px)',
    'var(--text-sm)',
    'var(--text-sm, 13px)',
    'var(--text-base)',
    'var(--text-base, 15px)',
];

it('never sizes a form control inline below the iOS auto-zoom threshold', function (): void {
    $offenders = [];

    foreach (inlineFontSizeCandidateViews() as $view) {
        $body = (string) file_get_contents($view);

        if (preg_match_all('/<(input|textarea|select)\b[^>]*?style="([^"]*)"/si', $body, $matches, PREG_SET_ORDER) === false) {
            continue;
        }

        foreach ($matches as $match) {
            if (preg_match('/font-size:\s*([^;"]+)/i', $match[2], $size) !== 1) {
                continue;
            }

            $value = trim($size[1]);
            if (in_array($value, INLINE_FONT_SIZES_UNDER_16, true)) {
                $offenders[] = str_replace(base_path().'/', '', $view).': <'.$match[1].'> at '.$value;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'an inline font-size under 16px defeats the coarse-pointer floor and makes iOS zoom the page on focus'
    );
});
