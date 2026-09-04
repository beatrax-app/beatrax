<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\FirstPartySymbols;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

/**
 * Every `//` and docblock line, keyed by the 1-based line it starts on. Only
 * comment tokens, so a `Foo::bar()` in live code is never read as a claim
 * about a symbol — the code either compiles or it does not.
 *
 * @return array<int, string>
 */
function commentSymbolsLines(string $source): array
{
    $lines = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT)) {
            continue;
        }

        foreach (preg_split('/\R/', $token[1]) ?: [] as $offset => $line) {
            $lines[$token[2] + $offset] = $line;
        }
    }

    return $lines;
}

/**
 * A wildcard is not a member: `PLATFORM_*` names a family, and the half before
 * the star is nothing any class declares. `::class` is a language construct
 * for the same reason.
 *
 * @return list<array{0: string, 1: string}>
 */
function commentSymbolsMentions(string $line): array
{
    $matches = PatternScan::sets('/\b([A-Z]\w+)::(\w+)/', $line);

    $found = [];

    foreach ($matches as $match) {
        if ($match[2] === 'class' || str_ends_with($match[2], '_')) {
            continue;
        }

        $found[] = [$match[1], $match[2]];
    }

    return $found;
}

it('names no first-party symbol a comment claims but no class has', function (): void {
    $classes = FirstPartySymbols::classes();
    $files = BackendSourceFiles::all();

    expect($classes)->not->toBe([]);
    expect($files)->not->toBe([]);

    $hits = [];

    foreach ($files as $path) {
        $label = str_replace(base_path().'/', '', $path);

        foreach (commentSymbolsLines((string) file_get_contents($path)) as $number => $line) {
            foreach (commentSymbolsMentions($line) as [$class, $member]) {
                if (! isset($classes[$class]) || FirstPartySymbols::hasMember($classes[$class], $member)) {
                    continue;
                }

                $hits[] = $label.':'.$number.'  '.$class.'::'.$member;
            }
        }
    }

    expect($hits)->toBe([], "A comment names a method, constant, property or case that its class does not have. A comment pointing at a renamed or deleted member reads exactly like one pointing at a member that ships, and it is read as an instruction: the next person keeps a redundant read because the comment says something else guards it. Only first-party classes are checked; a `PREFIX_*` wildcard and a class name this repo does not define are both skipped. Offenders:\n  ".implode("\n  ", $hits));
});

it('reads a mention only where it is one', function (): void {
    expect(commentSymbolsMentions('// mirrors Foo::bar() exactly'))->toBe([['Foo', 'bar']]);
    expect(commentSymbolsMentions(' * @param  string  $p  One of Foo::PLATFORM_* values.'))->toBe([]);
    expect(commentSymbolsMentions('// keyed by Foo::class'))->toBe([]);
    expect(commentSymbolsMentions('// a bare Foo name'))->toBe([]);
});

it('reads comment tokens only, never live code', function (): void {
    $source = "<?php\n// see Ghost::vanished()\n\$x = Ghost::vanished();\n";

    expect(commentSymbolsLines($source))->toBe([2 => '// see Ghost::vanished()']);
});
