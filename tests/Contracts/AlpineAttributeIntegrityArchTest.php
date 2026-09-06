<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/00-index.md
 */

// HTML tokenises before JavaScript ever sees it. A double quote anywhere inside
// a double-quoted attribute closes it, and the first `>` after that closes the
// tag — so the rest of the expression becomes page text. Alpine then reports
// only that some property "is not defined", pointing away from the real cause.
//
// This shipped: a JS comment inside `x-data="…"` quoted a phrase, and the whole
// recovery-codes screen on the import path rendered raw source, with a Copy
// button that had no label and a Download button that did nothing. The codes
// are shown once and are the only way back into an account.

/** @return list<string> absolute paths to every in-scope Blade template */
function alpineAttributeBladeFiles(): array
{
    $roots = [base_path('Modules'), base_path('resources')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// Alpine's scripting attributes, the ones whose values are JavaScript rather
// than a class list or a URL.
const ALPINE_SCRIPTING_ATTRIBUTES = ['x-data', 'x-init', 'x-effect'];

/** @return list<string> every scripting attribute in one template that HTML does not deliver whole */
function alpineAttributeFaults(string $where, string $source): array
{
    $faults = [];

    foreach (ALPINE_SCRIPTING_ATTRIBUTES as $attribute) {
        $offset = 0;

        while (($start = strpos($source, $attribute.'="', $offset)) !== false) {
            $valueStart = $start + strlen($attribute) + 2;
            $valueEnd = strpos($source, '"', $valueStart);
            $offset = $valueEnd === false ? $valueStart : $valueEnd + 1;

            if ($valueEnd === false) {
                $faults[] = $where.':'.alpineAttributeLine($source, $start).' — '.$attribute.' is never closed';

                continue;
            }

            // The value HTML actually delivers, which is the only thing Alpine
            // gets to parse.
            $value = substr($source, $valueStart, $valueEnd - $valueStart);

            if (alpineBraceBalance($value) !== 0) {
                $faults[] = $where.':'.alpineAttributeLine($source, $start)
                    .' — '.$attribute.' is cut short by a quote inside it, so HTML ends the attribute mid-expression';
            }
        }
    }

    return $faults;
}

it('closes every Alpine attribute where its author meant to close it', function (): void {
    $offenders = [];

    foreach (alpineAttributeBladeFiles() as $path) {
        $offenders = array_merge($offenders, alpineAttributeFaults(
            str_replace(base_path().'/', '', $path),
            (string) file_get_contents($path),
        ));
    }

    expect($offenders)->toBe(
        [],
        'A double quote inside a double-quoted attribute ends it, and everything after spills into the page as '
        ."text. Use single quotes inside the expression, or reword the comment.\n  "
        .implode("\n  ", $offenders),
    );
});

/** @return int the number of unclosed braces in the delivered attribute value */
function alpineBraceBalance(string $value): int
{
    $depth = 0;
    foreach (str_split($value) as $character) {
        if ($character === '{') {
            $depth++;
        }
        if ($character === '}') {
            $depth--;
        }
    }

    return $depth;
}

/** @return int the 1-indexed line the offset falls on */
function alpineAttributeLine(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

// A rule that finds nothing because its scan is broken looks exactly like a
// clean tree.
it('scans the Alpine attributes it claims to scan', function (): void {
    $seen = 0;

    foreach (alpineAttributeBladeFiles() as $path) {
        $seen += substr_count((string) file_get_contents($path), 'x-data="');
    }

    expect($seen)->toBeGreaterThan(50, 'Read '.$seen.' x-data attributes, too few to have covered the product.');
});

it('reads an attribute a quote cuts short, and leaves a whole one alone', function (): void {
    $cut = <<<'HTML'
        <div x-data="{ open: false, label: 'a "quoted" phrase' }"></div>
        HTML;

    $whole = <<<'HTML'
        <div x-data="{ open: false, label: 'a quoted phrase' }"></div>
        HTML;

    $unclosed = '<div x-data="{ open: false }>';

    expect(alpineAttributeFaults('a.blade.php', $cut))
        ->toHaveCount(1, 'a double quote inside the value ends the attribute, and the rest spills into the page as text');

    expect(alpineAttributeFaults('a.blade.php', $whole))
        ->toBe([], 'the expression HTML delivers whole is what every other template writes');

    expect(alpineAttributeFaults('a.blade.php', $unclosed))
        ->toHaveCount(1, 'an attribute with no closing quote at all is the same defect one step further on');
});
