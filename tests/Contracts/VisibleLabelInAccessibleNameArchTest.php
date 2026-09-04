<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupElement;
use Modules\Core\Public\Support\MarkupSource;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-aria-label-that-hides-the-visible-label
 */

/**
 * @return list<string>
 */
function labelInNameBladeFiles(): array
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

// The outermost hidden element is removed first, so removing it takes any
// nested one with it and no offset computed here is ever stale.
function labelInNameWithoutHidden(string $inner): string
{
    foreach (MarkupSource::tags($inner) as $element) {
        if ($element->attribute('aria-hidden') !== 'true' || $element->inner === null) {
            continue;
        }

        $whole = strlen($element->startTag) + strlen($element->inner) + strlen('</'.$element->name.'>');

        return labelInNameWithoutHidden(substr_replace($inner, '', $element->offset, $whole));
    }

    return $inner;
}

// Strips the pieces that carry no announced text: nested elements, Blade
// comments, and anything the reader is told to ignore.
function labelInNameVisibleText(string $inner): string
{
    // Entities are decoded before the caller tests for letters, so that
    // &times; and &lsaquo; read as the glyphs they are rather than as words.
    $text = html_entity_decode(
        MarkupSource::text(labelInNameWithoutHidden($inner)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8',
    );

    return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
}

/** @return list<MarkupElement> every button and link in the file, read whole */
function labelInNameControls(string $source): array
{
    return array_merge(
        MarkupSource::elements($source, 'button'),
        MarkupSource::elements($source, 'a'),
    );
}

it('has every static visible label contained in its accessible name (WCAG 2.5.3)', function (): void {
    $offenders = [];

    foreach (labelInNameBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        foreach (labelInNameControls($source) as $control) {
            $accessibleName = $control->attribute('aria-label');

            if ($accessibleName === null || $control->inner === null) {
                continue;
            }

            $visible = labelInNameVisibleText($control->inner);

            // Only statically-decidable pairs. An interpolated name or label
            // depends on runtime values this check cannot resolve, and an
            // element with no visible text has no label to disagree with.
            if ($visible === '' || str_contains($visible, '{{') || str_contains($accessibleName, '{{')) {
                continue;
            }

            // A glyph is an icon, not a label — "×" is not a word a speech
            // user would say. Only text with a letter or digit in it is a
            // visible label for the purposes of this rule.
            if (preg_match('~[\p{L}\p{N}]~u', $visible) !== 1) {
                continue;
            }

            if (! str_contains(mb_strtolower($accessibleName), mb_strtolower($visible))) {
                $offenders[] = sprintf('%s:%d — shows "%s", announces "%s"', $path, $control->line($source), $visible, $accessibleName);
            }
        }
    }

    expect($offenders)->toBe([], "Visible label text must be part of the accessible name. Offenders:\n  ".implode("\n  ", $offenders));
});
