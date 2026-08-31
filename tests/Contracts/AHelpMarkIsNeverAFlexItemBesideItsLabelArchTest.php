<?php

declare(strict_types=1);

// The mark is lifted onto the cap height of the text beside it by an offset
// written in that text's own units, which only works while the mark and the
// label resolve to one font-size. A flex row breaks both halves at once: the
// item inherits the ROW's type rather than the label's, and `vertical-align`
// is ignored on a flex item, so the offset aims at the wrong text and the
// alignment it was correcting comes back. Measured at 375px: the mark sat
// 5.98px low beside a 28px heading, and on a heading that wrapped it was also
// squeezed from 18px to 14.56px, because a flex item with no basis gives way.

/** @return list<string> every blade that draws a help mark */
function helpMarkBlades(): array
{
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.blade\.php$/',
    );
    foreach ($found as $file) {
        $source = (string) file_get_contents($file->getPathname());
        if (str_contains($source, '<x-core::help-tip')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// The element each mark is written inside, read by tracking open tags rather
// than by taking a window before it: the row that used to hold a mark opens
// four tags above the one that used to be its parent.
/** @return list<array{line: int, tag: string, class: string}> */
function helpMarkParents(string $source): array
{
    $source = (string) preg_replace_callback(
        '~\{\{--.*?--\}\}~s',
        static fn (array $match): string => str_repeat(' ', strlen($match[0])),
        $source,
    );

    $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    $matched = preg_match_all(
        '~<(/?)([a-zA-Z][-a-zA-Z0-9:.]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(/?)>~s',
        $source,
        $tags,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );

    expect($matched)->not->toBeFalse('The tag scan gave up on this file, so its answer would be a guess.');

    $stack = [];
    $parents = [];

    foreach ($tags as $tag) {
        [$whole, $offset] = $tag[0];
        $name = $tag[2][0];

        if ($name === 'x-core::help-tip') {
            $parent = end($stack);
            expect($parent)->not->toBeFalse('A help mark sits outside every element in '.$name.'.');

            $parents[] = [
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'tag' => $parent['tag'],
                'class' => $parent['class'],
            ];
        }

        if ($tag[4][0] === '/' || in_array(strtolower($name), $void, true)) {
            continue;
        }

        if ($tag[1][0] === '/') {
            array_pop($stack);

            continue;
        }

        preg_match('/class="([^"]*)"/', $tag[3][0], $class);
        $stack[] = ['tag' => $name, 'class' => $class[1] ?? ''];
    }

    return $parents;
}

it('never writes a help mark as a flex item beside the label it explains', function (): void {
    $offenders = [];

    foreach (helpMarkBlades() as $path) {
        foreach (helpMarkParents((string) file_get_contents($path)) as $parent) {
            if (str_starts_with($parent['tag'], 'x-')) {
                continue;
            }

            if (preg_match('/(^|\s)(flex|inline-flex|grid|inline-grid)(\s|$)/', $parent['class']) !== 1) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path).':'.$parent['line']
                .' — <'.$parent['tag'].' class="'.$parent['class'].'">';
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "These marks are laid out by a row instead of by the line of text they explain:\n  ".implode("\n  ", $offenders)
    );
});

// The two marks whose parent is a component take their type from it, so the
// component has to carry one.
it('gives the column header a size of its own, since the mark inside it inherits one', function (): void {
    $th = (string) file_get_contents(base_path('Modules/Core/Resources/views/components/th.blade.php'));

    expect(preg_match('/\btext-(xs|sm|base|md|lg|xl)\b/', $th))->toBe(1);
});
