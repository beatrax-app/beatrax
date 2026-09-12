<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/a-check-names-the-value-it-tests.md
 */

// Below this the walk read almost nothing and a clean answer means the scope
// moved, not that the tree is clean.
const COERCING_CHECK_FILE_FLOOR = 5000;

// `empty` after one of these is the name of a method, a constant or an enum
// case rather than the language construct, and the tree has four of those.
const COERCING_CHECK_NAME_MARKERS = ['::', '->', '?->'];

const COERCING_CHECK_NAME_TOKENS = [T_FUNCTION, T_CONST, T_CASE, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

/**
 * Every check in $source that answers by coercion rather than by naming the
 * value it tests, as a spelling and the line it stands on.
 *
 * @return list<array{line: int, spelling: string}>
 */
function coercingChecks(string $source): array
{
    $tokens = @token_get_all($source);

    if (! is_array($tokens)) {
        return [];
    }

    $significant = [];

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $significant[] = is_array($token) ? [$token[0], $token[1], $token[2]] : [-1, $token, 0];
    }

    $found = [];
    $line = 1;

    foreach ($significant as $index => [$id, $text, $at]) {
        if ($at > 0) {
            $line = $at;
        }

        [$beforeId, $beforeText] = $significant[$index - 1] ?? [0, ''];
        $names = in_array($beforeText, COERCING_CHECK_NAME_MARKERS, true)
            || in_array($beforeId, COERCING_CHECK_NAME_TOKENS, true);

        if ($id === T_EMPTY && ! $names) {
            $found[] = ['line' => $line, 'spelling' => 'empty()'];
        }

        if ($id === T_STRING && strtolower($text) === 'is_null' && ! $names && ($significant[$index + 1][1] ?? '') === '(') {
            $found[] = ['line' => $line, 'spelling' => 'is_null()'];
        }

        if ($id === T_IS_EQUAL) {
            $found[] = ['line' => $line, 'spelling' => '=='];
        }

        if ($id === T_IS_NOT_EQUAL) {
            $found[] = ['line' => $line, 'spelling' => '!='];
        }
    }

    return $found;
}

it('leaves no check that answers by coercion where naming the value would answer exactly', function (): void {
    $files = RepoTree::files(RepoTree::EVERY_PHP_FILE);

    expect(count($files))->toBeGreaterThan(
        COERCING_CHECK_FILE_FLOOR,
        'The walk opened '.count($files).' PHP files, so a clean answer here is a walk that read almost nothing.'
    );

    $offenders = [];
    $root = RepoTree::root().'/';

    foreach ($files as $path) {
        // Every one of the 49 offences this rule was written for stood in a
        // .blade.php file, which ends in .php and so lands in this walk while
        // token_get_all reads the whole template as one T_INLINE_HTML. Read
        // raw, the guard would have passed on the day it was written.
        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        foreach (coercingChecks($source) as $check) {
            $offenders[] = str_replace($root, '', $path).':'.$check['line'].'  '.$check['spelling'];
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A check here answers by coercion. `empty()` is one spelling for null, \'\', \'0\', 0, 0.0, [] and false,',
        'so the line does not say which of them the author meant to exclude; `is_null($x)` is `$x === null`',
        'written longer; `==` asks a different question from `===` while reading as a typo for it.',
        'Compare against what the value holds: `$rows !== []`, `($row[\'note\'] ?? \'\') !== \'\'`, `$rate === null`.',
        'A variable that may be undefined takes the coalesce, `($x ?? []) !== []`, not `empty()`. Offenders:',
        ...array_slice($offenders, 0, 40),
        count($offenders) > 40 ? '… and '.(count($offenders) - 40).' more' : '',
    ]));
});

// The reader half, proved against planted sources rather than against the tree:
// a reader that stopped recognising the construct would report a clean tree,
// and one that could not tell a bare `empty(` from `RateSet::empty(` would
// report four offences that are names rather than checks.
it('tells a coercing check from a name that merely shares its spelling', function (string $body, array $spellings): void {
    expect(array_column(coercingChecks('<?php '.$body), 'spelling'))->toBe(
        $spellings,
        'The reader answered '.json_encode(array_column(coercingChecks('<?php '.$body), 'spelling')).' for: '.$body
    );
})->with([
    'the bare construct' => ['if (empty($rows)) { return; }', ['empty()']],
    'the construct negated' => ['$has = ! empty($rows);', ['empty()']],
    'a static method of that name' => ['$set = RateSet::empty($currency);', []],
    'a declaration of that name' => ['public static function empty(): self {}', []],
    'an enum case of that name' => ['case Empty = \'empty\';', []],
    'an enum case read back' => ['$s = PreviewSectionStatus::Empty;', []],
    'the name inside a string' => ['$hint = \'empty($rows) was here\';', []],
    'the null predicate' => ['if (is_null($rate)) { return; }', ['is_null()']],
    'a method sharing the predicate name' => ['if ($this->is_null($rate)) { return; }', []],
    'the predicate as a callable string' => ['$n = array_filter($all, \'is_null\');', []],
    'loose equality' => ['if ($a == $b) { return; }', ['==']],
    'loose inequality' => ['if ($a != $b) { return; }', ['!=']],
    'the angled spelling of loose inequality' => ['if ($a <> $b) { return; }', ['!=']],
    'strict equality' => ['if ($a === $b) { return; }', []],
    'assignment, which is neither' => ['$a = $b;', []],
    'a comparison written into a string' => ['$sql = \'WHERE a == b\';', []],
]);

// The planted control for the half of the tree the rule was written for. A
// template holds its checks inside Blade constructs the raw tokeniser never
// enters, so the same source is read twice and the two answers are asserted
// against each other.
it('finds in a template the checks the raw tokeniser cannot see', function (): void {
    $template = implode("\n", [
        '<div>',
        '    @if (! empty($rows))',
        '        <span>{{ empty($row[\'note\']) ? \'—\' : $row[\'note\'] }}</span>',
        '    @endif',
        '    @php',
        '        $flag = is_null($rate);',
        '    @endphp',
        '    <?php $same = $a == $b; ?>',
        '</div>',
    ]);

    // Line 8 is the only construct in that template opening PHP mode with a
    // literal <?php, so it is the whole of the raw reading.
    expect(coercingChecks($template))->toBe(
        [['line' => 8, 'spelling' => '==']],
        'The raw tokeniser reached more than the one line that opens PHP mode, so the two readings below no '
        .'longer say anything about each other.'
    );

    expect(coercingChecks(BladePhpSource::of($template)))->toBe(
        [
            ['line' => 2, 'spelling' => 'empty()'],
            ['line' => 3, 'spelling' => 'empty()'],
            ['line' => 6, 'spelling' => 'is_null()'],
            ['line' => 8, 'spelling' => '=='],
        ],
        'The seam missed a Blade construct holding a check, and a construct short is a template reported part-read.'
    );
});
