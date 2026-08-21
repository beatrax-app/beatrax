<?php

declare(strict_types=1);

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

// Strips the pieces that carry no announced text: nested elements, Blade
// comments, and anything the reader is told to ignore.
function labelInNameVisibleText(string $inner): string
{
    $inner = preg_replace('~<span[^>]*aria-hidden="true"[^>]*>.*?</span>~s', '', $inner) ?? $inner;
    $inner = preg_replace('~\{\{--.*?--\}\}~s', '', $inner) ?? $inner;
    $inner = preg_replace('~<[^>]+>~', '', $inner) ?? $inner;

    // Entities are decoded before the caller tests for letters, so that
    // &times; and &lsaquo; read as the glyphs they are rather than as words.
    $inner = html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim(preg_replace('~\s+~', ' ', $inner) ?? $inner);
}

it('has every static visible label contained in its accessible name (WCAG 2.5.3)', function (): void {
    $offenders = [];

    foreach (labelInNameBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        // <button …>text</button> and <a …>text</a>, non-greedy, no nesting
        // of the same tag. Anything this pattern misses is simply not checked
        // rather than wrongly reported.
        preg_match_all(
            '~<(button|a)\b([^>]*)>((?:(?!</\1>).)*)</\1>~s',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $attributes = $match[2][0];
            $inner = $match[3][0];

            if (preg_match('~aria-label="([^"]*)"~', $attributes, $label) !== 1) {
                continue;
            }

            $accessibleName = $label[1];
            $visible = labelInNameVisibleText($inner);

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

            // The tag pattern stops at the first ">", so an attribute holding
            // inline JS spills its tail into $inner. Those artefacts carry
            // quotes or "=", which real button text does not.
            if (str_contains($visible, '"') || str_contains($visible, '=')) {
                continue;
            }

            if (! str_contains(mb_strtolower($accessibleName), mb_strtolower($visible))) {
                $line = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
                $offenders[] = sprintf('%s:%d — shows "%s", announces "%s"', $path, $line, $visible, $accessibleName);
            }
        }
    }

    expect($offenders)->toBe([], "Visible label text must be part of the accessible name. Offenders:\n  ".implode("\n  ", $offenders));
});
