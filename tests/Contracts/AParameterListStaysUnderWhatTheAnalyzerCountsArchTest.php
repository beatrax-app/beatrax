<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarClassShape;
use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/conventions/analyser-rules-enforced-locally.md#s107--too-many-parameters
 */

// The analyser's ceiling, which it applies to constructors and to everything
// else alike. Counting every declared parameter instead finds 122 functions
// over it on the default branch and the dashboard reports none of them, so
// what this guard counts is the whole rule: see the .docs page above.
const SONAR_PARAMETER_CEILING = 7;

// The analysed roots hold 2,493 files declaring thousands of functions, and the
// two floors sit far under both: a walk that read nothing inspects nothing and
// reports the same clean tree a walk that found nothing does.
const SONAR_PARAMETER_FILE_FLOOR = 1_000;

const SONAR_PARAMETER_FUNCTION_FLOOR = 1_000;

it('leaves no parameter list longer than the analyser counts to', function (): void {
    $files = SonarSourceFiles::all();

    expect(count($files))->toBeGreaterThan(
        SONAR_PARAMETER_FILE_FLOOR,
        'The walk opened '.count($files).' of the files the hosted analysis reads, so its verdict covers a fraction of them.'
    );

    $offenders = [];
    $inspected = 0;
    $largest = 0;

    foreach ($files as $path) {
        $tokens = SonarSourceFiles::tokens((string) file_get_contents($path));
        $brackets = SonarSourceFiles::brackets($tokens);

        $owners = [];
        foreach (SonarClassShape::types($tokens, $brackets) as $type) {
            foreach (SonarClassShape::methods($tokens, $brackets, $type['open'], $type['close']) as $method) {
                $owners[$method['nameIndex']] = ['type' => $type, 'method' => $method];
            }
        }

        foreach ($tokens as $index => $token) {
            if ($token[0] !== T_FUNCTION && $token[0] !== T_FN) {
                continue;
            }

            $nameIndex = ($tokens[$index + 1][0] ?? null) === null && ($tokens[$index + 1][1] ?? '') === '&'
                ? $index + 2
                : $index + 1;
            $owner = $owners[$nameIndex] ?? null;

            $counted = SonarClassShape::countedParameters($tokens, $brackets, $index);
            $inspected++;
            $largest = max($largest, $counted);

            if ($counted <= SONAR_PARAMETER_CEILING) {
                continue;
            }

            // A closure has no parent to override, so it always reports. A
            // method might: when its type extends or implements something the
            // analysis cannot resolve — a framework base class, an interface
            // from a package — the analyser cannot rule out that the signature
            // was inherited, and stays silent. Only a non-public method, which
            // can never be an override, or a type with nothing above it, is a
            // case it will speak on.
            if ($owner !== null) {
                $nonPublic = array_intersect($owner['method']['modifiers'], ['private', 'protected']) !== [];

                if (! $nonPublic && $owner['type']['inherits']) {
                    continue;
                }

                $offenders[] = str_replace(base_path().'/', '', $path)
                    .':'.$owner['method']['line'].' '.$owner['type']['name'].'::'.$owner['method']['name']
                    .'() takes '.$counted;

                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path)
                .':'.$token[2].' a function declared here takes '.$counted;
        }
    }

    // A walk that stops reading inspects nothing and reports a clean tree.
    // These say it ran: the tree declares thousands of functions, and at least
    // one of them sits on the ceiling.
    expect($inspected)->toBeGreaterThan(
        SONAR_PARAMETER_FUNCTION_FLOOR,
        'The tokeniser found '.$inspected.' function declarations in '.count($files)
        .' files, which is what a reader that stopped recognising a declaration looks like.'
    );

    expect($largest)->toBeGreaterThanOrEqual(
        SONAR_PARAMETER_CEILING,
        'The longest counted parameter list in the whole tree was '.$largest
        .', so the counter is answering with a number nothing in this repository could produce.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These take more than '.SONAR_PARAMETER_CEILING.' parameters that the analyser counts:',
        ...$offenders,
        '',
        'A list this long is usually several values that travel together',
        'without a name. Give them one — a small readonly value object, or the',
        'row object they were pulled apart from — and the signature says what',
        'it needs rather than listing it.',
        '',
        'Two things are deliberately NOT counted, because the analyser does not',
        'count them either. A promoted constructor property is a field written',
        'in parameter position, so a fourteen-argument data class is not a',
        'finding. And a public method on a type that extends or implements',
        'anything is left alone: the analyser cannot see whether the signature',
        'was inherited, so it says nothing, and neither does this. That is why',
        'a Livewire mount() or a queued job handle() never appears above.',
        '',
        'This is a local stand-in for the hosted rule, and reproduces its',
        'answer on the default branch exactly. There is no pinned list to add',
        'to: every entry above is something this branch introduced.',
    ]));
});

it('reads a promoted constructor property as a field and not as a parameter', function (): void {
    $promoted = '<?php final class C { public function __construct(private int $a, private int $b, private int $c, private int $d, private int $e, private int $f, private int $g, private int $h) {} }';
    $plain = '<?php final class C { public function __construct(int $a, int $b, int $c, int $d, int $e, int $f, int $g, int $h) {} }';

    expect(sonarParameterCount($promoted))->toBe([0]);
    expect(sonarParameterCount($plain))->toBe([8]);
});

it('counts past an attribute and a default value holding its own commas', function (): void {
    $source = '<?php function f(#[Attr(1, 2)] int $a, array $b = [1, 2, 3], int $c = 4) {}';

    expect(sonarParameterCount($source))->toBe([3]);
});

/**
 * @return list<int> one counted parameter list per declaration, in source order
 */
function sonarParameterCount(string $source): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $counts = [];

    foreach ($tokens as $index => $token) {
        if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
            $counts[] = SonarClassShape::countedParameters($tokens, $brackets, $index);
        }
    }

    return $counts;
}

it('speaks only where an inherited signature is ruled out', function (): void {
    $long = 'int $a, int $b, int $c, int $d, int $e, int $f, int $g, int $h';
    $inheriting = '<?php final class C extends Base { public function m('.$long.') {} private function h('.$long.') {} }';
    $standalone = '<?php final class C { public function m('.$long.') {} }';

    expect(sonarParameterOffenders($inheriting))->toBe(['C::h']);
    expect(sonarParameterOffenders($standalone))->toBe(['C::m']);
});

/**
 * @return list<string> the declarations this guard would report, as `Type::method`
 */
function sonarParameterOffenders(string $source): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $offenders = [];

    foreach (SonarClassShape::types($tokens, $brackets) as $type) {
        foreach (SonarClassShape::methods($tokens, $brackets, $type['open'], $type['close']) as $method) {
            $counted = SonarClassShape::countedParameters($tokens, $brackets, $method['nameIndex']);
            $nonPublic = array_intersect($method['modifiers'], ['private', 'protected']) !== [];

            if ($counted > SONAR_PARAMETER_CEILING && ($nonPublic || ! $type['inherits'])) {
                $offenders[] = $type['name'].'::'.$method['name'];
            }
        }
    }

    return $offenders;
}
