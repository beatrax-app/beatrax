<?php

declare(strict_types=1);

// A class_exists() guard around a binding says the class might not be there.
// For a first-party class in the module doing the binding, that is never true,
// and the guard is worse than redundant: a typo in the name, or a class that
// really did get deleted, stops being an error and becomes a binding that
// silently does not happen — discovered later, from a resolution failure in an
// unrelated place. The guards that ARE load-bearing all name a vendor package
// installed in only one composer root, which is why the rule is scoped to
// first-party names rather than to the function.

/**
 * @return list<string>
 */
function guardedFirstPartyBindings(): array
{
    $offenders = [];

    foreach (glob(base_path('Modules/*/Providers/*.php')) ?: [] as $file) {
        $contents = (string) file_get_contents($file);
        $relative = str_replace(base_path().'/', '', $file);

        // The guard names the class the way the file does — usually the short
        // name an import brought in — so the imports are what say whether it
        // is ours or a vendor package's.
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $contents, $imports);
        $qualify = [];
        foreach ($imports[1] as $fqcn) {
            $parts = explode('\\', $fqcn);
            $qualify[end($parts)] = $fqcn;
        }

        preg_match_all(
            '/(?:class_exists|interface_exists)\(\s*\\\\?([A-Za-z0-9_\\\\]+)(::class)?/',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $named = str_replace('\\\\', '\\', trim($match[1], "'"));
            $named = $qualify[$named] ?? $named;

            if (str_starts_with($named, 'Modules\\')) {
                $offenders[] = $relative.' -> '.$named;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

it('does not guard a first-party binding behind class_exists', function (): void {
    expect(guardedFirstPartyBindings())->toBe(
        [],
        'A provider is gating a binding on whether its own module\'s class exists. Bind it '
        ."outright; a class that is genuinely absent must fail loudly:\n  "
        .implode("\n  ", guardedFirstPartyBindings())
    );
});
