<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// `mobile-app/bootstrap/app.php` is outside PHPStan's `paths` on purpose (it
// names classes that exist only in `mobile-app/vendor`), and no test boots it,
// so a constant renamed out from under it reaches the phone as a blank screen
// on every request. This is the only gate that reads it.

/** @return list<array{string, string, string}> */
function classConstantReferences(string $bootstrapPath): array
{
    $contents = (string) file_get_contents($bootstrapPath);

    $imports = PatternScan::all('/^use\s+([\w\\\\]+);$/m', $contents);

    $shortToFqcn = [];
    foreach ($imports[1] as $fqcn) {
        $short = substr((string) strrchr('\\'.$fqcn, '\\'), 1);
        $shortToFqcn[$short] = $fqcn;
    }

    // `::name(` is a method call rather than a constant, and `::class` is a
    // magic constant every class answers; neither resolves with `constant()`.
    // The uppercase-initial requirement is what excludes `::class`.
    $uses = PatternScan::sets('/\b(\w+)::([A-Z][A-Z0-9_]*)\b(?!\s*\()/', $contents);

    $found = [];
    foreach ($uses as [, $short, $constant]) {
        if (! isset($shortToFqcn[$short])) {
            continue;
        }
        $found[] = [$short, $constant, $shortToFqcn[$short]];
    }

    return $found;
}

/**
 * The constant references this file names in a form the reader above cannot
 * resolve: a fully-qualified `\A\B::CONST`, or a short name aliased on import.
 *
 * @return list<string>
 */
function bootstrapUnresolvableConstantForms(string $bootstrapPath): array
{
    $contents = (string) file_get_contents($bootstrapPath);

    return [
        ...PatternScan::all('/\\\\\w+(?:\\\\\w+)+::[A-Z][A-Z0-9_]*\b(?!\s*\()/', $contents)[0],
        ...PatternScan::all('/^use\s+[\w\\\\]+\s+as\s+\w+;$/m', $contents)[0],
    ];
}

it('resolves every class constant both bootstrap roots name through an imported class', function (string $root): void {
    $references = classConstantReferences(base_path($root));

    // One reference stands in each file today. A run that resolved none read a
    // broken scan rather than a bootstrap that names no constant.
    expect($references)->not->toBeEmpty(
        "{$root} named no class constant at all, so the verdict below is about a file nobody parsed.",
    );

    $unresolved = [];
    foreach ($references as [$short, $constant, $fqcn]) {
        if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! enum_exists($fqcn)) {
            $unresolved[] = "{$short}::{$constant} — class {$fqcn} does not exist";

            continue;
        }
        if (! defined($fqcn.'::'.$constant)) {
            $unresolved[] = "{$short}::{$constant} — {$fqcn} has no such constant";
        }
    }

    expect($unresolved)->toBe(
        [],
        "{$root} names class constants that do not exist:\n  ".
        implode("\n  ", $unresolved)."\n".
        'Every request through this file dies with "Undefined constant", and the reader sees a blank page.',
    );
})->with(['bootstrap/app.php', 'mobile-app/bootstrap/app.php']);

// The reader above resolves a short name against the file's own `use` lines, so
// a constant reached any other way is not checked and not reported — which is
// the shape that reads as coverage. Rather than teach it two more spellings,
// the two spellings are forbidden: both have a plain import that works.
it('names no class constant in a form the resolver above cannot follow', function (string $root): void {
    $unreadable = bootstrapUnresolvableConstantForms(base_path($root));

    expect($unreadable)->toBe([], implode("\n  ", [
        $root.' reaches a class constant in a form this gate cannot resolve, so nothing checks that the',
        'constant exists — and nothing boots this file until a reader does:',
        ...$unreadable,
        '',
        'Import the class with a plain `use A\B;` and name it `B::CONST`.',
    ]));
})->with(['bootstrap/app.php', 'mobile-app/bootstrap/app.php']);

// Both readers answer "nothing" for a clean file and for a file they failed to
// parse. These plant each answer so the difference is an assertion.
it('reads a constant it can resolve, and reports the two forms it cannot', function (): void {
    $probe = tempnam(sys_get_temp_dir(), 'bootstrap-constants').'.php';

    try {
        file_put_contents($probe, <<<'PHP'
            <?php

            use Modules\Core\Public\Support\PatternScan;

            $a = PatternScan::SOME_CONSTANT;
            $b = PatternScan::first('/x/', 'x');
            $c = PatternScan::class;
            PHP);

        expect(classConstantReferences($probe))->toBe(
            [['PatternScan', 'SOME_CONSTANT', 'Modules\\Core\\Public\\Support\\PatternScan']],
            'the reader must see the constant fetch and none of: a method call, or a ::class magic constant',
        );
        expect(bootstrapUnresolvableConstantForms($probe))->toBe([], 'a plainly imported short name is resolvable and must not be reported');

        file_put_contents($probe, <<<'PHP'
            <?php

            use Modules\Core\Public\Support\PatternScan as Scanner;

            $a = \Modules\Core\Public\Support\PatternScan::SOME_CONSTANT;
            PHP);

        expect(bootstrapUnresolvableConstantForms($probe))->toBe([
            '\\Modules\\Core\\Public\\Support\\PatternScan::SOME_CONSTANT',
            'use Modules\\Core\\Public\\Support\\PatternScan as Scanner;',
        ], 'both the fully-qualified reference and the aliased import must be reported as unreadable');
    } finally {
        @unlink($probe);
    }
});
