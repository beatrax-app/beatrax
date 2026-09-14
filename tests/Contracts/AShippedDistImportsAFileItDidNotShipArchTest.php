<?php

declare(strict_types=1);

use Tests\Contracts\Support\ShippedElectronImports;

// nativephp/desktop 2.3.0 published dist/server/api/system.js importing
// `../pdfPageSize.js` and did not ship that file — src/server/pdfPageSize.ts is
// in the package, the compiled sibling is not. Every desktop platform's release
// build died on it at once, and nothing before the build had opened the tree:
// a Composer package's JavaScript is not analysed, not linted and not tested
// here, so the first reader is a bundler in a job that runs on a tag.

const SHIPPED_DIST_ROOTS = [
    'vendor/nativephp/desktop/resources/electron/electron-plugin/dist',
];

// One entry per specifier this repository supplies itself, named by the script
// that supplies it. A pin is not an exemption: the rule below still reports a
// specifier that no longer appears, so the day 2.3.1 ships the file, this entry
// fails and goes — along with the script.
const SHIPPED_DIST_SUPPLIED = [
    'server/api/system.js' => [
        '../pdfPageSize.js' => 'scripts/nativephp_supply_pdf_page_size.php',
    ],
];

/**
 * @return list<string>
 */
function shippedDistFiles(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'js') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('imports no file the package forgot to ship', function (): void {
    $roots = array_filter(array_map(base_path(...), SHIPPED_DIST_ROOTS), is_dir(...));

    if ($roots === []) {
        $this->markTestSkipped('no shipped electron dist is installed in this Composer root.');
    }

    $offenders = [];
    $supplied = [];
    $specifiers = 0;
    $opened = 0;

    foreach ($roots as $root) {
        foreach (shippedDistFiles($root) as $file) {
            $opened++;
            $result = ShippedElectronImports::scan($file, (string) file_get_contents($file));
            $specifiers += $result['specifiers'];

            $relative = ltrim(str_replace($root, '', $file), '/');

            foreach ($result['unresolved'] as $specifier) {
                if (isset(SHIPPED_DIST_SUPPLIED[$relative][$specifier])) {
                    $supplied[$relative.' '.$specifier] = true;

                    continue;
                }

                $offenders[] = sprintf(
                    '%s imports %s, which the package does not ship and nothing here supplies',
                    str_replace(base_path().'/', '', $file),
                    $specifier,
                );
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));

    // The walk read something, and read imports rather than only files. A
    // vendored layout that moves under this rule reports no offenders for the
    // same reason a sound package does, and only these separate them.
    expect($opened)->toBeGreaterThan(20)
        ->and($specifiers)->toBeGreaterThan(20);

    // Every pin names a script that is still here. Whether the specifier
    // currently resolves is not the question — it resolves precisely when the
    // script has already run in this checkout, and does not in a fresh CI
    // install, so both readings are ordinary. What must not rot is the pin
    // pointing at a supplier nothing has.
    $missing = [];

    foreach (SHIPPED_DIST_SUPPLIED as $file => $specifiers) {
        foreach ($specifiers as $specifier => $script) {
            if (! is_file(base_path($script))) {
                $missing[] = sprintf('%s pins %s to %s, which is not in the tree', $file, $specifier, $script);
            }
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('reads a sibling that is there, one that is not, an extensionless name, and a bare one', function (): void {
    // Planted against the shipped layout itself, from the very file the 2.3.0
    // defect was in, so the resolver is exercised over the tree it judges
    // rather than over a mock of one.
    $subject = base_path(SHIPPED_DIST_ROOTS[0].'/server/api/system.js');

    if (! is_file($subject)) {
        $this->markTestSkipped('no shipped electron dist is installed in this Composer root.');
    }

    $good = ShippedElectronImports::scan($subject, "import { a } from '../state.js';");
    $extensionless = ShippedElectronImports::scan($subject, "import { a } from '../state';");
    $missing = ShippedElectronImports::scan($subject, "import { b } from '../pdfPageSize.js';");
    $bare = ShippedElectronImports::scan($subject, "import express from 'express';");

    // The third is the 2.3.0 defect itself, spelled as the package spelled it.
    expect($good['unresolved'])->toBe([])
        ->and($extensionless['unresolved'])->toBe([])
        ->and($missing['unresolved'])->toBe(['../pdfPageSize.js']);

    // Every relative one was recognised as a specifier first, and the bare name
    // was not, which is what makes a clean answer above a judgement.
    expect([$good['specifiers'], $extensionless['specifiers'], $missing['specifiers'], $bare['specifiers']])
        ->toBe([1, 1, 1, 0]);
});
