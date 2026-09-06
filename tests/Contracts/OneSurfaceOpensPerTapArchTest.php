<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Which surface a control opens is a viewport decision: a bottom sheet below
// 768px, the modal above it. A component that also announces `modal-show` from
// the server makes that decision a second time and gets it wrong on a phone --
// both open, and a modal <dialog> owns the hit test for the whole viewport, so
// the tap that should press the sheet's own button dismisses the modal over it.
// Goals found this and fixed it; Budgets carried the same shape untouched.

/** @return list<string> every `.blade.php` a reader is shown, in both roots that hold them */
function everyModuleBlade(): array
{
    $blades = [];

    foreach (['Modules', 'resources'] as $root) {
        $directory = base_path($root);

        if (! is_dir($directory)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $blades[] = $file->getPathname();
            }
        }
    }

    sort($blades);

    return $blades;
}

/** @return list<string> the sheet names one blade opens */
function sheetNamesInBladeSource(string $source): array
{
    return array_values(array_unique(
        PatternScan::all("/open-sheet['\"]?\s*,\s*\{\s*name:\s*'([a-z0-9-]+)'/", $source)[1],
    ));
}

/** @return list<string> the sheet names any blade opens */
function sheetNamesOpenedInBlades(): array
{
    $names = [];

    foreach (everyModuleBlade() as $blade) {
        foreach (sheetNamesInBladeSource((string) file_get_contents($blade)) as $name) {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

/** @return list<string> the names one component announces a modal for from the server */
function serverModalNamesInSource(string $source): array
{
    return array_values(array_unique(
        PatternScan::all("/dispatch\(\s*'modal-show'\s*,\s*name:\s*'([a-z0-9-]+)'/", $source)[1],
    ));
}

it('never announces a modal from the server for a name that also opens as a sheet', function (): void {
    $blades = everyModuleBlade();

    expect(count($blades))->toBeGreaterThan(150, 'The Blade walk found almost nothing, so the sheet roster below is short for a reason that is not the tree.');

    $sheets = sheetNamesOpenedInBlades();

    expect(count($sheets))->toBeGreaterThan(3, 'Almost no blade was read as opening a bottom sheet, so the comparison below proves nothing.');

    $offenders = [];
    $walked = 0;

    foreach (['Modules', 'resources'] as $root) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $walked++;

            foreach (serverModalNamesInSource((string) file_get_contents($path)) as $name) {
                if (in_array($name, $sheets, true)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).' → '.$name;
                }
            }
        }
    }

    expect($walked)->toBeGreaterThan(2000, 'The PHP walk read almost nothing, so a clean answer below is the walk being broken rather than the components being right.');

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n", [
        'A component announces `modal-show` from the server for a name a blade also opens as a',
        'bottom sheet. Below 768px both surfaces open, and a modal <dialog> owns the hit test for',
        'the whole viewport — so the tap meant for the sheet dismisses the modal standing over it.',
        'Let the viewport decide: dispatch the sheet name from the client and leave the server out',
        'of it. Offenders:',
        ...array_values(array_unique($offenders)),
    ]));
});

it('reads a sheet a blade opens and a modal a component announces, and neither near miss', function (): void {
    $opens = "<button x-on:click=\"\$dispatch('open-sheet', { name: 'edit-goal' })\">Edit</button>";
    // The near miss on the blade side: a sheet DECLARED is not a sheet opened,
    // and reading one as the other would put every sheet in the roster.
    $declares = '<x-core::bottom-sheet name="edit-goal" />';

    $announces = "\$this->dispatch('modal-show', name: 'edit-goal');";
    // The near miss on the component side: closing a modal is not opening one.
    $closes = "\$this->dispatch('modal-close', name: 'edit-goal');";

    expect(sheetNamesInBladeSource($opens))->toBe(['edit-goal'])
        ->and(sheetNamesInBladeSource($declares))->toBe([])
        ->and(serverModalNamesInSource($announces))->toBe(['edit-goal'])
        ->and(serverModalNamesInSource($closes))->toBe([]);
});
