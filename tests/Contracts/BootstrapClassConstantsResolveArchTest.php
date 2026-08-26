<?php

declare(strict_types=1);

// `mobile-app/bootstrap/app.php` is outside PHPStan's `paths` on purpose (it
// names classes that exist only in `mobile-app/vendor`), and no test boots it,
// so a constant renamed out from under it reaches the phone as a blank screen
// on every request. This is the only gate that reads it.

/** @return list<array{string, string, string}> */
function classConstantReferences(string $bootstrapPath): array
{
    $contents = (string) file_get_contents($bootstrapPath);

    preg_match_all('/^use\s+([\w\\\\]+);$/m', $contents, $imports);

    $shortToFqcn = [];
    foreach ($imports[1] as $fqcn) {
        $short = substr((string) strrchr('\\'.$fqcn, '\\'), 1);
        $shortToFqcn[$short] = $fqcn;
    }

    // `::class` is a magic constant every class answers, and `::name(` is a
    // method call — neither can be resolved with `constant()`.
    preg_match_all('/\b(\w+)::([A-Z][A-Z0-9_]*)\b(?!\s*\()/', $contents, $uses, PREG_SET_ORDER);

    $found = [];
    foreach ($uses as [, $short, $constant]) {
        if ($constant === 'class' || ! isset($shortToFqcn[$short])) {
            continue;
        }
        $found[] = [$short, $constant, $shortToFqcn[$short]];
    }

    return $found;
}

it('resolves every class constant both bootstrap roots name', function (string $root): void {
    $references = classConstantReferences(base_path($root));

    expect($references)->not->toBeEmpty();

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
