<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/** @return list<string> every class name app.css declares a rule for */
function styleClassNamesDefinedIn(string $css): array
{
    return array_values(array_unique(PatternScan::all('~\.([a-z][a-z0-9]*(?:-[a-z0-9]+)*)~', $css)[1]));
}

/**
 * The tokens in one class attribute that name a family app.css defines and are
 * themselves defined by nothing. Named rather than written inline so the
 * control below drives the same reader the walk drives.
 *
 * @param  list<string>  $definedNames
 * @return list<string>
 */
function styleClassFamilyStemsIn(string $attribute, array $definedNames): array
{
    $defined = array_flip($definedNames);
    $found = [];

    // Interpolated halves are dropped rather than guessed at: a token
    // assembled at render time is not a literal this file can resolve.
    $literal = preg_replace('~\{\{.*?\}\}|\{!!.*?!!\}~s', ' ', $attribute) ?? $attribute;

    foreach (PatternScan::split('~\s+~', trim($literal)) as $token) {
        if ($token === '' || isset($defined[$token])) {
            continue;
        }

        // Only multi-segment names, because Tailwind's own bare words
        // (flex, hidden, grid) collide with app family stems and the
        // framework — not app.css — is what defines those.
        if (preg_match('~^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$~', $token) !== 1) {
            continue;
        }

        foreach ($definedNames as $name) {
            if (str_starts_with($name, $token.'-')) {
                $found[] = $token;

                break;
            }
        }
    }

    return $found;
}

it('defines every class a Blade applies out of a family app.css names', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $definedNames = styleClassNamesDefinedIn($css);

    $blades = [];
    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $blades[] = $file->getPathname();
            }
        }
    }
    sort($blades);

    $offenders = [];
    $attributes = 0;

    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);
        $read = PatternScan::allWithOffsets('~\bclass="([^"]*)"~', $source);

        foreach ($read[1] as [$value, $offset]) {
            $attributes++;

            foreach (styleClassFamilyStemsIn($value, $definedNames) as $token) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $offenders[] = str_replace(base_path().'/', '', $path).':'.$line.'  .'.$token;
            }
        }
    }

    $offenders = array_values(array_unique($offenders));

    // Three denominators, all before the verdict. Each of them alone reports a
    // clean tree when it collapses: no stylesheet read means every token is
    // "defined by nothing named", no template read means nothing applies a
    // class, and no attribute read means the walk opened files it never looked
    // inside.
    expect(count($definedNames))->toBeGreaterThan(
        100,
        'app.css yielded '.count($definedNames).' class names, which is what a stylesheet that failed to '
        .'load looks like rather than one somebody emptied.'
    );

    expect(count($blades))->toBeGreaterThan(
        100,
        'The walk opened '.count($blades).' templates, which is too few to be the Blade tree.'
    );

    expect($attributes)->toBeGreaterThan(
        1000,
        'The reader found '.$attributes.' class attributes across the whole Blade tree, which is what a '
        .'broken attribute pattern looks like rather than markup that stopped carrying classes.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These Blade class tokens name a member of a family app.css defines,',
        'but app.css defines no rule for the token itself, so the control is',
        'painted by nothing:',
        ...$offenders,
        '',
        'Either add the rule to resources/css/app.css — theme-aware, so it',
        'holds in light and dark — or apply the class that already exists.',
    ]));
});

// A guard that cannot go red is a guard that says nothing. The reader is driven
// here against a stylesheet and an attribute written to hold each shape, so a
// rewrite of either pattern cannot quietly stop finding them.
it('names a family stem nothing defines and leaves every near miss alone', function (string $attribute, array $expected): void {
    $defined = styleClassNamesDefinedIn('.srch-sheet-apply { color: red } .srch-chip { color: red } .tap-link { color: red }');

    expect(styleClassFamilyStemsIn($attribute, $defined))->toBe($expected);
})->with([
    'a stem of a family that has members and no rule of its own' => ['srch-sheet-apply srch-sheet', ['srch-sheet']],
    'a stem app.css defines outright' => ['srch-chip', []],
    'a single-segment Tailwind word' => ['flex hidden grid', []],
    'a token belonging to no family app.css names' => ['grid-cols-4', []],
    'a stem hidden inside an interpolated half' => ['{{ $active ? "srch-sheet" : "" }}', []],
]);
