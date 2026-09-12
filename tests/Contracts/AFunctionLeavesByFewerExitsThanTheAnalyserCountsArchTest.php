<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarClassShape;
use Tests\Contracts\Support\SonarReturnStatements;
use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/conventions/analyser-rules-enforced-locally.md#s1142--too-many-return-statements
 */

// The `max` the hosted rule ships with and this profile leaves alone, so a
// fourth `return` in one body is a finding. Twenty-seven of them have been
// raised here, every one after the branch that wrote them had merged.
const SONAR_RETURN_CEILING = 3;

// The analysed roots hold 2,620 files declaring 9,509 bodies. Both floors sit
// far below what is there: a walk that stopped reading inspects nothing and
// reports the same clean tree a walk that found nothing does.
const SONAR_RETURN_FILE_FLOOR = 1_000;

const SONAR_RETURN_DECLARATION_FLOOR = 4_000;

// 686 bodies leave by exactly three exits. That is what a codebase refactored
// down to a threshold looks like, and it is the number that falls first if the
// reader stops crediting returns — long before the offender list changes.
const SONAR_RETURN_ON_THE_CEILING_FLOOR = 400;

it('leaves no function with more returns than the analyser counts to', function (): void {
    $files = SonarSourceFiles::all();

    expect(count($files))->toBeGreaterThan(
        SONAR_RETURN_FILE_FLOOR,
        'The walk opened '.count($files).' of the files the hosted analysis reads, so its verdict covers a fraction of them.'
    );

    $offenders = [];
    $inspected = 0;
    $highest = 0;
    $onTheCeiling = 0;

    foreach ($files as $path) {
        $tokens = SonarSourceFiles::tokens((string) file_get_contents($path));
        $brackets = SonarSourceFiles::brackets($tokens);

        $owners = [];
        foreach (SonarClassShape::types($tokens, $brackets) as $type) {
            foreach (SonarClassShape::methods($tokens, $brackets, $type['open'], $type['close']) as $method) {
                $owners[$method['nameIndex']] = $type['name'].'::'.$method['name'];
            }
        }

        foreach (SonarReturnStatements::read($tokens, $brackets) as $function) {
            $inspected++;
            $highest = max($highest, $function['returns']);
            $onTheCeiling += $function['returns'] === SONAR_RETURN_CEILING ? 1 : 0;

            if ($function['returns'] <= SONAR_RETURN_CEILING) {
                continue;
            }

            $named = $owners[$function['nameIndex']]
                ?? ($function['name'] === '{closure}' ? 'a closure declared here' : $function['name'].'()');

            $offenders[] = str_replace(base_path().'/', '', $path)
                .':'.$function['line'].' '.$named.' returns '.$function['returns'].' times';
        }
    }

    // A walk that stops reading inspects nothing and reports a clean tree.
    // These say it ran, and that it was still crediting returns when it
    // finished rather than only opening files.
    expect($inspected)->toBeGreaterThan(
        SONAR_RETURN_DECLARATION_FLOOR,
        'The tokeniser found '.$inspected.' bodies in '.count($files)
        .' files, which is what a reader that stopped recognising a declaration looks like.'
    );

    expect($highest)->toBeGreaterThanOrEqual(
        SONAR_RETURN_CEILING,
        'The most exits any one body was credited with is '.$highest.', below the ceiling itself, so the '
        .'counter stopped crediting returns rather than the tree getting simpler.'
    );

    expect($onTheCeiling)->toBeGreaterThan(
        SONAR_RETURN_ON_THE_CEILING_FLOOR,
        'Only '.$onTheCeiling.' bodies were credited with exactly '.SONAR_RETURN_CEILING.' returns, so the counter is '
        .'answering with numbers this repository could not produce.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These leave by more than '.SONAR_RETURN_CEILING.' returns, which the analyser counts as a finding:',
        ...$offenders,
        '',
        'The reflex fix is a single exit — one `$result` variable, assigned in',
        'each branch and returned at the end — and it is the wrong one: it',
        'turns a flat run of guard clauses into nesting, and the nesting is',
        'charged again by the cognitive-complexity rule beside this one. What',
        'actually reduces the count is giving the second decision its own',
        'method, so each body answers one question and leaves.',
        '',
        'Moving a branch into a closure also makes the number go down without',
        'making anything simpler. A `return` belongs to the closest body around',
        'it, so a callback carries its own count — the analyser reads it that',
        'way and so does this. An arrow function has no return statement at',
        'all and is never a finding.',
        '',
        'This is a local stand-in for the hosted rule, reading the same maximum',
        'of '.SONAR_RETURN_CEILING.' and crediting a return to the same body. Replayed over the',
        'revisions the hosted analysis ran on, it reproduces each of the',
        'findings it published at the same declaration and the same count.',
        'Anything failing here fails the hosted analysis on merge.',
        '',
        'There is no pinned list to add to. The default branch carries no body',
        'above the ceiling — 686 sit exactly on it — so every entry above is',
        'something this branch introduced.',
    ]));
});

// The rules below are the ones that make this a stand-in for the analyser
// rather than a count of the word `return` between two braces. A naive count
// reports fifteen bodies on the default branch that the hosted analysis says
// nothing about, so each of these is load-bearing rather than decorative.
it('credits a return to the closest body around it and to no other', function (): void {
    $nested = '<?php function f() { if ($a) { return 1; } $g = function () { if ($b) { return 2; } return 3; }; return 4; }';

    expect(sonarReturnCounts($nested))->toBe([2, 2], 'the outer body keeps two, the closure keeps its own two');
});

it('reads an arrow function as a body with no return statement in it', function (): void {
    $arrow = '<?php $f = static fn (int $a): int => $a + 1;';
    $holder = '<?php function f() { return array_map(static fn (int $a): int => $a, [1]); }';

    expect(sonarReturnCounts($arrow))->toBe([], 'an expression body holds no return statement, so there is nothing to count');
    expect(sonarReturnCounts($holder))->toBe([1], 'the arrow function adds no body of its own and takes nothing from the one holding it');
});

it('finds the body past a parameter list, a use clause and a return type', function (): void {
    $closure = '<?php $f = function (int $a = 1) use ($b): ?array { if ($a) { return []; } return null; };';
    $dnf = '<?php function f(): (A&B)|null { return null; }';

    expect(sonarReturnCounts($closure))->toBe([2], 'the braces after `use (...)` and a nullable return type are the body');
    expect(sonarReturnCounts($dnf))->toBe([1], 'a parenthesised intersection in the return type is not the body');
});

it('counts nothing for a declaration that has no body', function (): void {
    $abstract = '<?php abstract class C { abstract public function m(): int; public function n(): int { return 1; } }';
    $interface = '<?php interface I { public function m(): int; }';

    expect(sonarReturnCounts($abstract))->toBe([1], 'an abstract method ends at a semicolon and declares no exits');
    expect(sonarReturnCounts($interface))->toBe([], 'nor does an interface method');
});

it('leaves a return written outside every function uncounted', function (): void {
    $config = '<?php return [\'a\' => 1];';
    $mixed = '<?php function f() { return 1; } return [\'b\' => 2];';

    expect(sonarReturnCounts($config))->toBe([], 'a config file returning its array is not a function and has no ceiling');
    expect(sonarReturnCounts($mixed))->toBe([1], 'the file-level return belongs to no body and is charged to none');
});

it('reports a body over the ceiling under the name of the method that declares it', function (): void {
    $over = '<?php final class C { public function m(int $a): int { if ($a === 1) { return 1; } if ($a === 2) { return 2; } if ($a === 3) { return 3; } return 4; } }';

    expect(sonarReturnCounts($over))->toBe([4]);
    expect(sonarReturnOffenders($over))->toBe(['C::m returns 4 times']);
});

/**
 * @return list<int> one return count per body, in source order
 */
function sonarReturnCounts(string $source): array
{
    return array_column(SonarReturnStatements::functions($source), 'returns');
}

/**
 * @return list<string> the bodies this guard would report, named as the guard names them
 */
function sonarReturnOffenders(string $source): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $offenders = [];

    $owners = [];
    foreach (SonarClassShape::types($tokens, $brackets) as $type) {
        foreach (SonarClassShape::methods($tokens, $brackets, $type['open'], $type['close']) as $method) {
            $owners[$method['nameIndex']] = $type['name'].'::'.$method['name'];
        }
    }

    foreach (SonarReturnStatements::read($tokens, $brackets) as $function) {
        if ($function['returns'] > SONAR_RETURN_CEILING) {
            $offenders[] = ($owners[$function['nameIndex']] ?? $function['name']).' returns '.$function['returns'].' times';
        }
    }

    return $offenders;
}
