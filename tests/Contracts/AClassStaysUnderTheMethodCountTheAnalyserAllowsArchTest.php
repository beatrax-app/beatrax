<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarClassShape;
use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/conventions/analyser-rules-enforced-locally.md#s1448--too-many-methods-in-a-class
 */

// A local stand-in for the hosted analyser's method-count rule, which has
// raised eighteen findings here. The ceiling is 20 and non-public methods
// count towards it, so a class sitting on the line goes over the moment a
// private helper is extracted — which is how most of the eighteen arrived.
const SONAR_METHOD_CEILING = 20;

// Doctrine's entity marker, in the two spellings the analyser accepts. Nothing
// in this tree carries one; it is here so the guard cannot be stricter than
// the rule it stands in for if an ORM ever lands.
const SONAR_METHOD_ENTITY_PATTERN = '/#\[\s*(?:ORM\\\\)?Entity\b|@(?:ORM\\\\)?Entity\b/';

it('leaves no class with more methods than the analyser allows', function (): void {
    $files = SonarSourceFiles::all();
    expect($files)->not->toBe([]);

    $offenders = [];
    $counted = 0;
    $largest = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $tokens = SonarSourceFiles::tokens($source);
        $brackets = SonarSourceFiles::brackets($tokens);
        $lines = explode("\n", $source);

        foreach (SonarClassShape::types($tokens, $brackets) as $type) {
            // A trait's methods belong to whatever uses it and an enum is not
            // a class declaration, so neither is counted — by the analyser or
            // here. Splitting a class into a trait therefore silences this
            // rule without reducing anything, which is worth knowing before
            // reaching for one.
            if (! in_array($type['kind'], ['class', 'interface', 'anonymous'], true)) {
                continue;
            }

            $methods = SonarClassShape::methods($tokens, $brackets, $type['open'], $type['close']);
            $count = count($methods);
            $counted++;
            $largest = max($largest, $count);

            if ($count <= SONAR_METHOD_CEILING) {
                continue;
            }

            $accessorsOnly = true;
            foreach ($methods as $method) {
                $name = strtolower($method['name']);
                if (! str_starts_with($name, 'get') && ! str_starts_with($name, 'set')) {
                    $accessorsOnly = false;

                    break;
                }
            }

            $above = implode("\n", array_slice($lines, max(0, $type['line'] - 21), min(20, $type['line'] - 1)));
            $isEntity = preg_match(SONAR_METHOD_ENTITY_PATTERN, $above) === 1;

            if (! $accessorsOnly && ! $isEntity) {
                $offenders[] = str_replace(base_path().'/', '', $path)
                    .':'.$type['line'].' '.$type['name'].' declares '.$count.' methods';
            }
        }
    }

    // A walk that stops reading finds no class at all and reports a clean
    // tree. These say it ran: the tree holds well over a thousand types, and
    // the largest of them sits near the ceiling rather than nowhere near it.
    expect($counted)->toBeGreaterThan(500);
    expect($largest)->toBeGreaterThanOrEqual(15);

    expect($offenders)->toBe([], implode("\n", [
        'These classes declare more than '.SONAR_METHOD_CEILING.' methods:',
        ...$offenders,
        '',
        'The count is of methods declared in the body, public and non-public',
        'alike. A class that has grown past it is usually two responsibilities',
        'sharing a constructor: give the second one a collaborator class and',
        'the count falls on both sides.',
        '',
        'Moving methods into a trait also makes the number go down, and does',
        'not make the class smaller — the analyser counts declarations, so a',
        'trait hides the growth rather than undoing it. Prefer a collaborator.',
        '',
        'This is a local stand-in for the hosted rule, reading the same ceiling',
        'of '.SONAR_METHOD_CEILING.', the same rule that non-public methods count, and the',
        'same shapes it exempts. Anything failing here fails the hosted',
        'analysis on merge.',
        '',
        'There is no pinned list to add to. The default branch carries no',
        'class over the ceiling — eight sit exactly on it — so every entry',
        'above is something this branch introduced.',
    ]));
});

it('counts a non-public method and a method the interface only declares', function (): void {
    $body = '';
    for ($i = 0; $i < 21; $i++) {
        $visibility = $i % 3 === 0 ? 'private' : 'public';
        $body .= $visibility.' function m'.$i.'(): void {} ';
    }

    $class = '<?php class C { '.$body.'}';
    $interface = '<?php interface I { public function a(): void; public function b(): void; }';

    $tokens = SonarSourceFiles::tokens($class);
    $types = SonarClassShape::types($tokens, SonarSourceFiles::brackets($tokens));
    expect(SonarClassShape::methods($tokens, SonarSourceFiles::brackets($tokens), $types[0]['open'], $types[0]['close']))->toHaveCount(21);

    $interfaceTokens = SonarSourceFiles::tokens($interface);
    $interfaceTypes = SonarClassShape::types($interfaceTokens, SonarSourceFiles::brackets($interfaceTokens));
    expect($interfaceTypes[0]['kind'])->toBe('interface');
    expect(SonarClassShape::methods($interfaceTokens, SonarSourceFiles::brackets($interfaceTokens), $interfaceTypes[0]['open'], $interfaceTypes[0]['close']))->toHaveCount(2);
});

it('credits a method to the body that declares it and to no other', function (): void {
    $source = '<?php class C { use T; public function a(): void { $x = new class { public function inner(): void {} }; } }';

    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $types = SonarClassShape::types($tokens, $brackets);

    expect(array_column($types, 'kind'))->toBe(['class', 'anonymous']);
    expect(SonarClassShape::methods($tokens, $brackets, $types[0]['open'], $types[0]['close']))->toHaveCount(1);
    expect(SonarClassShape::methods($tokens, $brackets, $types[1]['open'], $types[1]['close']))->toHaveCount(1);
});
