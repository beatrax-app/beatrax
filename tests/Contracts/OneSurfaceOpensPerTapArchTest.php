<?php

declare(strict_types=1);

// Which surface a control opens is a viewport decision: a bottom sheet below
// 768px, the modal above it. A component that also announces `modal-show` from
// the server makes that decision a second time and gets it wrong on a phone --
// both open, and a modal <dialog> owns the hit test for the whole viewport, so
// the tap that should press the sheet's own button dismisses the modal over it.
// Goals found this and fixed it; Budgets carried the same shape untouched.

/** @return list<string> every `.blade.php` under Modules/ */
function everyModuleBlade(): array
{
    $blades = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
            $blades[] = $file->getPathname();
        }
    }

    sort($blades);

    return $blades;
}

/** @return list<string> the sheet names any blade opens */
function sheetNamesOpenedInBlades(): array
{
    $names = [];

    foreach (everyModuleBlade() as $blade) {
        $source = (string) file_get_contents($blade);

        if (preg_match_all("/open-sheet['\"]?\s*,\s*\{\s*name:\s*'([a-z0-9-]+)'/", $source, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $name) {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

it('never announces a modal from the server for a name that also opens as a sheet', function (): void {
    $sheets = sheetNamesOpenedInBlades();

    expect($sheets)->not->toBe([], 'No blade opens a bottom sheet, so this proves nothing.');

    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (preg_match_all("/dispatch\(\s*'modal-show'\s*,\s*name:\s*'([a-z0-9-]+)'/", $source, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $name) {
            if (in_array($name, $sheets, true)) {
                $offenders[] = str_replace(base_path().'/', '', $path).' → '.$name;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
