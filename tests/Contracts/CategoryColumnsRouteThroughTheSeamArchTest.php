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

// "Everywhere" is the backend tree: Modules and app, module Blade views
// included, minus tests and migrations. A view under resources/ is not read.
it('selects the category name parts through the seam that owns them, everywhere the backend tree reaches', function (): void {
    $parts = CategoryDisplayName::bareColumns();
    $files = BackendSourceFiles::all();
    $offenders = [];
    $literals = 0;

    expect(count($parts))->toBeGreaterThan(
        1,
        'The seam names fewer than two parts, so `array_diff($parts, $elements) === []` matches almost any array literal or none at all.',
    );

    expect(count($files))->toBeGreaterThan(
        2_000,
        'The walk read almost nothing, so the empty offender list below is a tree nobody opened.',
    );

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if (in_array($relative, CATEGORY_COLUMN_SEAM_ALLOWED, true)) {
            continue;
        }

        $found = categorySeamArrayLiterals(BackendSourceFiles::codeTokens($path));
        $literals += count($found);

        foreach ($found as $line => $elements) {
            if (array_diff($parts, $elements) === []) {
                $offenders[] = $relative.':'.$line;
            }
        }
    }

    expect($literals)->toBeGreaterThan(
        500,
        'The token reader found almost no array literal at all, so the verdict below is about source nobody parsed.',
    );

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

// A file allowed to name the parts and no longer naming them is excused for
// something it stopped doing, and it stands ready to excuse a hand-written
// select somebody adds there next.
it('keeps no seam exemption for a file that no longer names the parts', function (): void {
    $parts = CategoryDisplayName::bareColumns();
    $dead = [];

    foreach (CATEGORY_COLUMN_SEAM_ALLOWED as $relative) {
        $path = base_path($relative);

        if (! is_file($path)) {
            $dead[] = $relative.' is no longer in the tree';

            continue;
        }

        $names = false;
        foreach (categorySeamArrayLiterals(BackendSourceFiles::codeTokens($path)) as $elements) {
            $names = $names || array_diff($parts, $elements) === [];
        }

        if (! $names) {
            $dead[] = $relative.' no longer lists the category name parts in an array literal';
        }
    }

    expect($dead)->toBe([], implode("\n  ", [
        'These files are excused from routing through CategoryDisplayName and no longer name the parts at',
        'all, so the exemption covers nothing while reading as considered:',
        ...$dead,
    ]));
});

// The reader is a token walk over bracket depth, and a walk that stopped
// answers "no array literal" in the same words a file with none does.
it('reads the bare elements of an array literal and not the keys of a map', function (): void {
    $literals = categorySeamArrayLiterals(BackendSourceFiles::tokensOf(
        'Planted.php',
        "<?php\n\$columns = ['name', 'parent_name', 'group_name'];\n\$map = ['name' => \$a, 'parent_name' => \$b];\n",
    ));

    expect(array_values($literals))->toBe(
        [['name', 'parent_name', 'group_name']],
        'the reader has to see the bare column list and none of the keys of the map beside it',
    );
});
