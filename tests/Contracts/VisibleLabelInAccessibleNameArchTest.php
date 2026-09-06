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

/**
 * @return array{labelled: int, compared: int, offenders: list<string>} controls
 *                                                                      carrying an accessible name, the statically-decidable pairs among them,
 *                                                                      and the ones whose two labels disagree
 */
function labelInNameVerdict(string $source, string $label): array
{
    $labelled = 0;
    $compared = 0;
    $offenders = [];

    foreach (labelInNameControls($source) as $control) {
        // WCAG 2.5.3 binds only where an accessible name has been given: a
        // control with no aria-label already announces its own text.
        $accessibleName = $control->attribute('aria-label');

        if ($accessibleName === null || $control->inner === null) {
            continue;
        }

        $labelled++;

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

        $compared++;

        if (! str_contains(mb_strtolower($accessibleName), mb_strtolower($visible))) {
            $offenders[] = sprintf('%s:%d — shows "%s", announces "%s"', $label, $control->line($source), $visible, $accessibleName);
        }
    }

    return ['labelled' => $labelled, 'compared' => $compared, 'offenders' => $offenders];
}

// Buttons and links, which is what MarkupSource is asked for. A `[role=button]`
// div, a `<summary>` or an `input[type=submit]` carrying a value is the same
// defect and is not covered here.
//
// The word doing the work is STATIC. Every user-facing string in this tree is
// translated, so 93 of the 105 aria-labelled controls carry `{{ }}` on one side
// or the other and are skipped by design: today the rule decides zero pairs and
// can only fail on a hard-coded English pair somebody adds. The floors below
// are on what the walk reached rather than on what it decided, because a floor
// on the decided count would be a floor of zero.
it('has every button and link\'s static visible label contained in its accessible name (WCAG 2.5.3)', function (): void {
    $files = labelInNameBladeFiles();

    expect(count($files))->toBeGreaterThan(100, 'the Blade walk read almost nothing — the roots are wrong, not the tree.');

    $offenders = [];
    $labelled = 0;

    foreach ($files as $path) {
        $verdict = labelInNameVerdict((string) file_get_contents($path), $path);

        $labelled += $verdict['labelled'];
        $offenders = [...$offenders, ...$verdict['offenders']];
    }

    // 105 today. A parser that stopped reading elements, or an attribute reader
    // that stopped resolving aria-label, empties this while the offender list
    // below stays every bit as empty as a clean tree's.
    expect($labelled)->toBeGreaterThan(40, 'almost no control was read as carrying an aria-label at all — the markup reader is broken, not the templates.');

    expect($offenders)->toBe([], "Visible label text must be part of the accessible name. Offenders:\n  ".implode("\n  ", $offenders));
});

it('sees a name that hides its own visible label, and lets the four exempt shapes through', function (): void {
    $planted = <<<'BLADE'
        <button aria-label="Dismiss">Close</button>
        <button aria-label="Save changes">Save</button>
        <a href="/x" aria-label="Open the {{ $name }} report">Open</a>
        <button aria-label="Close"><span aria-hidden="true">×</span></button>
        <button aria-label="Remove"><span aria-hidden="true">Delete</span></button>
        BLADE;

    $verdict = labelInNameVerdict($planted, 'planted.blade.php');

    expect($verdict['offenders'])->toHaveCount(
        1,
        'The reader must flag "shows Close, announces Dismiss" and nothing else: a contained '
        .'label, an interpolated name, a glyph-only label and a label hidden from the reader '
        .'are each outside this rule for a reason of their own.',
    );

    expect(str_contains($verdict['offenders'][0], 'shows "Close", announces "Dismiss"'))->toBeTrue(
        'The reader flagged something, but not the pair whose two labels actually disagree.',
    );

    expect($verdict['compared'])->toBe(
        2,
        'The reader compared the wrong number of pairs: two of these five are statically decidable.',
    );

    expect($verdict['labelled'])->toBe(
        5,
        'The reader no longer sees every aria-labelled control, which is the count the real walk floors itself on.',
    );
});
