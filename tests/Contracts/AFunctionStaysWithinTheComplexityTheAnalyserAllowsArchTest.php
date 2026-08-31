<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarCognitiveComplexity;
use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/conventions/analyser-rules-enforced-locally.md#s3776--cognitive-complexity
 */

// A local stand-in for the hosted analyser's cognitive-complexity rule, which
// is the single largest source of findings this project has had: 116 of them,
// every one discovered on the dashboard after the branch had already merged.
// The scoring below is the analyser's own, ported rather than approximated,
// and checked against the number it publishes for all 2072 analysed files.
it('leaves no function harder to follow than the analyser allows', function (): void {
    $files = SonarSourceFiles::all();
    expect($files)->not->toBe([]);

    $offenders = [];
    $measured = 0;
    $highest = 0;

    foreach ($files as $path) {
        $reading = SonarCognitiveComplexity::analyse((string) file_get_contents($path));

        foreach ($reading['functions'] as $function) {
            $measured++;
            $highest = max($highest, $function['value']);

            if ($function['value'] > 15) {
                $offenders[] = str_replace(base_path().'/', '', $path)
                    .':'.$function['line'].' '.$function['name'].'() scores '.$function['value'];
            }
        }
    }

    // A walk that stops reading reports a clean tree, and a clean tree is what
    // a green build looks like. These two say the walk actually ran: the tree
    // holds thousands of functions, and the hardest of them scores well into
    // double figures.
    expect($measured)->toBeGreaterThan(1000);
    expect($highest)->toBeGreaterThanOrEqual(10);

    expect($offenders)->toBe([], implode("\n", [
        'These functions score above 15 on cognitive complexity:',
        ...$offenders,
        '',
        'Cognitive complexity is not a line count. It charges 1 for each branch',
        'or loop, plus 1 more for every level of nesting the branch sits under,',
        'so pulling a nested block out into a named method of its own usually',
        'costs more lines and scores far less. A flat sequence of guard clauses',
        'is nearly free; the same conditions nested three deep are not.',
        '',
        'This is a local stand-in for the hosted rule, not a second opinion:',
        'the scoring is the analyser\'s own algorithm and its threshold of 15,',
        'and it agrees with the published per-file figure on every analysed',
        'file. Anything failing here fails the hosted analysis on merge.',
        '',
        'There is no pinned list to add to. The default branch carries no',
        'function above 15 — ten sit exactly on it — so every entry above is',
        'something this branch introduced.',
    ]));
});

// The scoring rules below are the ones that make this an implementation of the
// analyser rather than a rough count. Each pins a case where the obvious
// reading is wrong, so a later simplification cannot quietly change the number
// while the tree still happens to be clean.
it('charges a branch once for itself and once for every level above it', function (): void {
    $source = '<?php function f() { if ($a) { if ($b) { if ($c) { return 1; } } } }';

    expect(SonarCognitiveComplexity::analyse($source)['functions'][0]['value'])->toBe(6);
});

it('separates an else-if from an elseif, which score differently', function (): void {
    $chained = '<?php function f() { if ($a) { return 1; } elseif ($b) { if ($c) { return 2; } } }';
    $nested = '<?php function f() { if ($a) { return 1; } else if ($b) { if ($c) { return 2; } } }';

    expect(SonarCognitiveComplexity::analyse($chained)['functions'][0]['value'])->toBe(4);
    expect(SonarCognitiveComplexity::analyse($nested)['functions'][0]['value'])->toBe(5);
});

it('charges a run of one operator once and every switch between two again', function (): void {
    $same = '<?php function f() { return $a && $b && $c && $d; }';
    $mixed = '<?php function f() { return $a && $b || $c && $d; }';
    $grouped = '<?php function f() { return ($a && $b) || $c; }';
    $called = '<?php function f() { return g($a && $b) || $c; }';

    expect(SonarCognitiveComplexity::analyse($same)['functions'][0]['value'])->toBe(1);
    expect(SonarCognitiveComplexity::analyse($mixed)['functions'][0]['value'])->toBe(3);
    expect(SonarCognitiveComplexity::analyse($grouped)['functions'][0]['value'])->toBe(2);
    expect(SonarCognitiveComplexity::analyse($called)['functions'][0]['value'])->toBe(2);
});

it('nests a ternary inside the branch it was written in', function (): void {
    $flat = '<?php function f() { return $a ? 1 : 2; }';
    $nested = '<?php function f() { return $a ? 1 : ($b ? 2 : 3); }';
    $guarded = '<?php function f() { if ($a) { return $b ? 1 : 2; } return 0; }';

    expect(SonarCognitiveComplexity::analyse($flat)['functions'][0]['value'])->toBe(1);
    expect(SonarCognitiveComplexity::analyse($nested)['functions'][0]['value'])->toBe(3);
    expect(SonarCognitiveComplexity::analyse($guarded)['functions'][0]['value'])->toBe(3);
});

it('counts a nullable return type as a type and never as a ternary', function (): void {
    $arrow = '<?php $f = static fn (mixed $v): ?string => is_string($v) ? $v : null;';
    $typed = '<?php function f(?int $a = null): ?string { return null; }';

    expect(SonarCognitiveComplexity::analyse($arrow)['total'])->toBe(1);
    expect(SonarCognitiveComplexity::analyse($typed)['functions'][0]['value'])->toBe(0);
});

it('folds a closure into the function holding it, one level deeper', function (): void {
    $source = '<?php function f() { g(function () { if ($a) { return 1; } }); }';
    $arrowHolder = '<?php function f() { g(static fn () => $a ? 1 : 2); }';

    expect(SonarCognitiveComplexity::analyse($source)['functions'])->toHaveCount(1);
    expect(SonarCognitiveComplexity::analyse($source)['functions'][0]['value'])->toBe(2);
    expect(SonarCognitiveComplexity::analyse($arrowHolder)['functions'][0]['value'])->toBe(2);
});

it('scores the statements a case label introduces', function (): void {
    $source = '<?php function f() { foreach ($x as $y) { switch ($k) { case 1: if ($a && $b) { return 1; } break; default: return 0; } } }';

    expect(SonarCognitiveComplexity::analyse($source)['functions'][0]['value'])->toBe(7);
});

it('reads a method named for a keyword and a class header carrying a comma', function (): void {
    $keyword = '<?php final class C implements A, B { public function for(): int { if ($a) { return 1; } return 0; } }';

    expect(SonarCognitiveComplexity::analyse($keyword)['functions'][0]['name'])->toBe('for');
    expect(SonarCognitiveComplexity::analyse($keyword)['functions'][0]['value'])->toBe(1);
});

it('adds a file total from the functions in it and the code around them', function (): void {
    $source = '<?php if ($boot) { echo 1; } function f() { if ($a) { return 1; } } class C { public function m() { if ($b) { if ($c) { return 1; } } } }';

    expect(SonarCognitiveComplexity::analyse($source)['total'])->toBe(5);
});
