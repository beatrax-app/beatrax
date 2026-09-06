<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A class_exists() guard around a binding says the class might not be there.
// For a first-party class in the module doing the binding, that is never true,
// and the guard is worse than redundant: a typo in the name, or a class that
// really did get deleted, stops being an error and becomes a binding that
// silently does not happen — discovered later, from a resolution failure in an
// unrelated place. The guards that ARE load-bearing all name a vendor package
// installed in only one composer root, which is why the rule is scoped to
// first-party names rather than to the function.

// Every provider a module ships, not the Modules/<X>/Providers/ directory
// alone: six of them sit in Internal/Providers/ or beside the code they
// register, and the old glob could not see one.
/**
 * @return list<string> absolute paths to every provider file under Modules/
 */
function providerSourceFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS),
    ) as $file) {
        $path = $file->getPathname();

        if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/tests/') && str_contains($file->getBasename(), 'Provider')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<string> "$label -> $fqcn" for every first-party guard the source makes
 */
function guardedFirstPartyBindingsIn(string $contents, string $label): array
{
    // The guard names the class the way the file does — usually the short
    // name an import brought in — so the imports are what say whether it
    // is ours or a vendor package's.
    $imports = PatternScan::all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $contents);
    $qualify = [];
    foreach ($imports[1] as $fqcn) {
        $parts = explode('\\', $fqcn);
        $qualify[end($parts)] = $fqcn;
    }

    $matches = PatternScan::sets(
        '/(?:class_exists|interface_exists)\(\s*\\\\?([A-Za-z0-9_\\\\]+)(::class)?/',
        $contents,
    );

    $offenders = [];
    foreach ($matches as $match) {
        $named = str_replace('\\\\', '\\', trim($match[1], "'"));
        $named = $qualify[$named] ?? $named;

        if (str_starts_with($named, 'Modules\\')) {
            $offenders[] = $label.' -> '.$named;
        }
    }

    return $offenders;
}

/**
 * @return list<string>
 */
function guardedFirstPartyBindings(): array
{
    $offenders = [];

    foreach (providerSourceFiles() as $file) {
        foreach (guardedFirstPartyBindingsIn((string) file_get_contents($file), str_replace(base_path().'/', '', $file)) as $offender) {
            $offenders[] = $offender;
        }
    }

    sort($offenders);

    return $offenders;
}

it('does not guard a first-party binding behind class_exists', function (): void {
    expect(count(providerSourceFiles()))->toBeGreaterThan(30, 'The provider walk found almost nothing, so a clean answer below is the walk being broken rather than the providers being right.');

    $offenders = guardedFirstPartyBindings();

    expect($offenders)->toBe(
        [],
        'A provider is gating a binding on whether its own module\'s class exists. Bind it '
        ."outright; a class that is genuinely absent must fail loudly:\n  "
        .implode("\n  ", $offenders)
    );
});

it('reads a first-party guard and leaves the vendor guards that are load-bearing', function (): void {
    // A name no module declares, so the plant cannot read as a real crossing to
    // the boundary scan that walks this tree in another worker.
    $firstParty = "<?php\n\nuse Modules\\Example\\Public\\Services\\PlantedBinding;\n\n"
        ."if (class_exists(PlantedBinding::class)) {\n    \$this->app->bind(PlantedBinding::class);\n}\n";

    // The near miss the rule is deliberately scoped around: a guard naming a
    // vendor package installed in only one composer root really can be false,
    // and reading it as an offender would delete a check the bundle needs.
    $vendor = "<?php\n\nuse Native\\Laravel\\Facades\\Window;\n\n"
        ."if (class_exists(Window::class)) {\n    \$this->app->singleton('desktop.window');\n}\n";

    expect(guardedFirstPartyBindingsIn($firstParty, 'v'))->toBe(['v -> Modules\\Example\\Public\\Services\\PlantedBinding'])
        ->and(guardedFirstPartyBindingsIn($vendor, 'v'))->toBe([]);
});
