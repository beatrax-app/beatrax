<?php

declare(strict_types=1);

use Modules\Ledger\Public\Support\CategoryDisplayName;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/features/ledger/category-display-names.md
 */

// A hand-written category select list is invisible to CategoryDisplayName's
// PARTS, so a fourth part added there reaches the seam's callers and nobody
// else — and fromRow() answers a missing part with an InvalidArgumentException,
// one screen at a time. The parts below come from the seam, not from a literal.
const CATEGORY_COLUMN_SEAM_ALLOWED = [
    // PARTS itself, and the mass-assignment list, which is not a select.
    'Modules/Ledger/Public/Support/CategoryDisplayName.php',
    'Modules/Ledger/Models/Category.php',
];

it('selects the category name parts through the seam that owns them, everywhere', function (): void {
    $parts = CategoryDisplayName::bareColumns();
    $offenders = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if (in_array($relative, CATEGORY_COLUMN_SEAM_ALLOWED, true)) {
            continue;
        }

        foreach (categorySeamArrayLiterals(BackendSourceFiles::codeTokens($path)) as $line => $elements) {
            if (array_diff($parts, $elements) === []) {
                $offenders[] = $relative.':'.$line;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "Select the category name parts through CategoryDisplayName::bareColumns()\n".
        "(or columns(\$table, \$alias) when the row needs a prefix), in:\n  ".
        implode("\n  ", $offenders),
    );
});

/**
 * Every array literal in the file, as line number => its plain string elements.
 * A `'key' => value` pair contributes nothing: only bare elements are columns.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array<int, list<string>>
 */
function categorySeamArrayLiterals(array $tokens): array
{
    $elements = [];
    $lines = [];
    $open = [];
    $nextId = 0;

    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;

        if ($text === '[') {
            $open[] = $nextId++;

            continue;
        }

        if ($text === ']') {
            array_pop($open);

            continue;
        }

        if ($open === [] || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $before = categorySeamNeighbour($tokens, $index, -1);
        $after = categorySeamNeighbour($tokens, $index, 1);

        if (! in_array($before, ['[', ','], true) || ! in_array($after, [']', ','], true)) {
            continue;
        }

        $id = $open[count($open) - 1];
        $elements[$id][] = trim($token[1], "'\"");
        $lines[$id] ??= $token[2];
    }

    $byLine = [];
    foreach ($elements as $id => $columns) {
        $byLine[$lines[$id]] = array_values($columns);
    }

    return $byLine;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function categorySeamNeighbour(array $tokens, int $index, int $step): string
{
    for ($i = $index + $step; isset($tokens[$i]); $i += $step) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if (trim($text) !== '') {
            return $text;
        }
    }

    return '';
}
