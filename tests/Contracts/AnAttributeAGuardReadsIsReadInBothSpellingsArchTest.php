<?php

declare(strict_types=1);

use Tests\Contracts\Support\GuardFiles;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-reads-html-with-a-regex
 */

// The sibling rule beside this one stopped guards reading markup with a
// tag-shaped pattern. This one closes the other half: a guard that searches for
// `role="tablist"` or `x-data="` as a plain string is asking about one of the
// two delimiters HTML accepts, and a template picks the other one exactly when
// the value already contains the first.
//
// It was silent here. One shipped component writes its Alpine expression as
// `x-data='{ … "…" … }'`, and the rule on unbalanced Alpine attributes searched
// for `x-data="` alone: the defect was planted in that component and the whole
// suite stayed green. Five guards read attributes this way, across tab strips,
// the drawer and the segmented toggle.

// The functions whose string arguments are questions put to a subject rather
// than prose printed back to a reader. A failure message may quote an attribute
// however it likes; a needle may not.
const ATTRIBUTE_NEEDLE_READERS = [
    'str_contains', 'str_starts_with', 'str_ends_with', 'strpos', 'stripos',
    'substr_count', 'preg_match', 'preg_match_all', 'preg_split', 'explode',
];

// Each entry is a literal that is a question about a string rather than about
// an element, so reading the other delimiter would answer nothing.
/** @var array<string, list<string>> repo-relative path => the literals it keeps */
const ATTRIBUTE_NEEDLES_KEPT = [];

function attributeShapedLiteral(string $body): bool
{
    return preg_match('/(^|[\s<])[a-zA-Z][a-zA-Z0-9:._-]*=("|\')/', $body) === 1;
}

/**
 * Literals handed to one of the readers above, which is where a spelling-bound
 * question is asked. A literal anywhere else in the file is prose.
 *
 * @return list<array{line: int, literal: string}>
 */
function attributeNeedlesIn(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $needles = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ATTRIBUTE_NEEDLE_READERS, true)) {
            continue;
        }

        $next = $tokens[$i + 1] ?? null;

        if (($next === null) || (is_array($next) ? $next[1] : $next) !== '(') {
            continue;
        }

        $depth = 0;

        for ($j = $i + 1; $j < $count; $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $body = substr($tokens[$j][1], 1, -1);

                if (attributeShapedLiteral($body)) {
                    $needles[] = ['line' => $tokens[$j][2], 'literal' => $body];
                }
            }
        }
    }

    return $needles;
}

it('asks about an attribute through the reader that knows both delimiters', function (): void {
    $offenders = [];
    $walked = 0;

    $self = str_replace(base_path().'/', '', __FILE__);

    foreach (GuardFiles::all() as $path) {
        $file = str_replace(base_path().'/', '', $path);

        // This file spells every shape it forbids as a literal of its own.
        if ($file === $self) {
            continue;
        }

        $walked++;
        $source = (string) file_get_contents($path);

        foreach (attributeNeedlesIn($source) as $needle) {
            if (in_array($needle['literal'], ATTRIBUTE_NEEDLES_KEPT[$file] ?? [], true)) {
                continue;
            }

            $offenders[] = $file.':'.$needle['line'].'  '.$needle['literal'];
        }
    }

    // A walk that opened nothing reports the same clean tree a walk that found
    // nothing does, and this rule's subject is the guards themselves.
    expect($walked)->toBeGreaterThan(
        250,
        'Only '.$walked.' guard files were read, so a clean answer below is the walk being narrow rather than the guards being right.'
    );

    expect($offenders)->toBe([], implode("\n  ", [
        'These ask about an HTML attribute in one of the two spellings HTML accepts, so the '
        .'element stops being covered the moment a template writes the other one:',
        ...$offenders,
        '',
        'Read it through Tests\Contracts\Support\MarkupAttribute::carries(), ::valueOf(), '
        .'::present() or ::countIn(), which try both delimiters. If the literal really is a '
        .'question about a string rather than about an element, name it in ATTRIBUTE_NEEDLES_KEPT '
        .'with the reason.',
    ]));
});

it('reads a needle in either delimiter and leaves prose and a whole reader alone', function (): void {
    $needle = <<<'PHP'
        <?php
        $a = str_contains($attributes, 'role="tablist"');
        PHP;

    $singleQuoted = <<<'PHP'
        <?php
        $a = str_contains($attributes, "x-data='{ }'");
        PHP;

    $prose = <<<'PHP'
        <?php
        $message = 'A role="tablist" is a promise that the strip behaves like one.';
        PHP;

    $seam = <<<'PHP'
        <?php
        $a = MarkupAttribute::carries($attributes, 'role', 'tablist');
        PHP;

    expect(attributeNeedlesIn($needle))->toHaveCount(1, 'the double-quoted spelling this rule exists to catch went unread')
        ->and(attributeNeedlesIn($singleQuoted))->toHaveCount(1, 'the single-quoted spelling is the half that was silent, and it must be read too')
        ->and(attributeNeedlesIn($prose))->toBe([], 'a failure message may quote an attribute however it likes — it asks the subject nothing')
        ->and(attributeNeedlesIn($seam))->toBe([], 'the seam takes the name and the value apart, so neither argument is attribute-shaped');
});
