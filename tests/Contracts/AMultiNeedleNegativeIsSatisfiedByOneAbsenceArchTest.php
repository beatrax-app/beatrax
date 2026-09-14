<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarSourceFiles;
use Tests\Contracts\Support\TopLevelDeclarations;

// `toContain(mixed ...$needles)` is variadic and takes no message, while its
// neighbours — `toHaveKeys(array $keys, string $message = '')` among them — do.
// Handed a message, it reads it as one more needle, and negated it then passes
// on that needle's absence alone, whatever the real one is doing.

/**
 * Every `->not->toContain(` in $source that was handed more than one argument,
 * as a list of line numbers. Read from the token stream because a comma inside
 * a nested call or a quoted string is not an argument separator.
 *
 * @return list<int>
 */
function multiNeedleNegatives(string $source): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $lines = [];

    foreach ($tokens as $i => $token) {
        if ($token[0] !== T_STRING || $token[1] !== 'toContain') {
            continue;
        }

        $isNegated = ($tokens[$i - 1][0] ?? null) === T_OBJECT_OPERATOR
            && ($tokens[$i - 2][1] ?? '') === 'not'
            && ($tokens[$i - 3][0] ?? null) === T_OBJECT_OPERATOR;

        $open = $i + 1;

        if (! $isNegated || ($tokens[$open][1] ?? '') !== '(' || ! isset($brackets[$open])) {
            continue;
        }

        $close = $brackets[$open];
        $commas = 0;

        for ($j = $open + 1; $j < $close; $j++) {
            if (isset($brackets[$j]) && $brackets[$j] > $j) {
                $j = $brackets[$j];

                continue;
            }

            if ($tokens[$j][0] === null && $tokens[$j][1] === ',') {
                $commas++;
            }
        }

        // PHP allows a trailing comma after the last argument, and it separates
        // nothing. Counted, it turns every single needle written across lines
        // into a reported list of two.
        if (($tokens[$close - 1][1] ?? '') === ',') {
            $commas--;
        }

        if ($commas > 0) {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

it('leaves no negated toContain holding more than one needle', function (): void {
    $files = TopLevelDeclarations::testFiles();

    expect(count($files))->toBeGreaterThan(
        2_000,
        'the walk collected almost no test file, so the clean answer below is a tree nobody read',
    );

    $offenders = [];

    foreach ($files as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        if (! str_contains($source, 'toContain')) {
            continue;
        }

        foreach (multiNeedleNegatives($source) as $line) {
            $offenders[] = $relative.':'.$line;
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A negated toContain() passes as soon as ONE of its needles is absent, so a second '
        .'argument satisfies it whatever the first is doing — a message satisfies it always. '
        .'Chain them instead: ->not->toContain($a)->not->toContain($b). Offenders:',
        ...$offenders,
    ]));
});

it('reads a needle list and leaves a nested comma and the positive form alone', function (): void {
    expect(multiNeedleNegatives('<?php expect($a)->not->toContain("x", "y");'))->toBe([1]);

    expect(multiNeedleNegatives('<?php expect($a)->not->toContain("x");'))
        ->toBe([], 'one needle is the shape this rule is asking for');

    expect(multiNeedleNegatives('<?php expect($a)->not->toContain(sprintf("%s, %s", $b, $c));'))
        ->toBe([], 'the commas belong to the nested call, not to the needle list');

    expect(multiNeedleNegatives('<?php expect($a)->not->toContain("a, b");'))
        ->toBe([], 'a comma inside a quoted needle separates nothing');

    expect(multiNeedleNegatives('<?php expect($a)->toContain("x", "y");'))
        ->toBe([], 'the positive form asserts every needle is present, which is what it says');

    expect(multiNeedleNegatives('<?php expect($a)->not->toContain("only-one",);'))
        ->toBe([], 'a trailing comma after the last argument separates nothing');

    expect(multiNeedleNegatives('<?php expect($a)->not->toContain("x", "y",);'))
        ->toBe([1], 'a trailing comma does not excuse the needle before it');
});
