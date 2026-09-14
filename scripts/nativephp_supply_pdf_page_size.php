<?php

declare(strict_types=1);

/*
 * Supplies electron-plugin/dist/server/pdfPageSize.js, which nativephp/desktop
 * 2.3.0 imports and does not ship.
 *
 * dist/server/api/system.js carries
 * `import { parsePdfPageSizePoints, buildNativePrintOptions } from '../pdfPageSize.js'`
 * and the compiled sibling is absent from the package. src/server/pdfPageSize.ts
 * IS in it, so what shipped is a dist built from a source tree one file wider
 * than the dist. electron-vite cannot resolve the import, no bundle is written,
 * and every desktop platform's release build dies at the same line — measured
 * on probe 12, where Linux, Windows and macOS all failed and Android, which
 * builds no electron shell, passed.
 *
 * The module below is that source with its types removed and nothing else
 * changed. It is 70 lines, imports nothing, and its two exported functions are
 * pure, so transcribing it is a smaller risk than reverting the package: 2.2.1
 * declares native:migrate:fresh under both an #[AsCommand] name and a $name
 * property, which the Laravel this tree now runs refuses to register.
 *
 * Written only where it is missing, so the day 2.3.1 ships the file this script
 * does nothing and can be deleted along with the pin in
 * tests/Contracts/AShippedDistImportsAFileItDidNotShipArchTest.php.
 */

const PDF_PAGE_SIZE_JS = <<<'JS'
const mediaBox = /\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/;
export function parsePdfPageSizePoints(buffer) {
    // latin1 keeps byte offsets stable for the ASCII PDF structure tokens.
    const match = buffer.toString('latin1').match(mediaBox);
    if (!match) {
        return null;
    }
    const x0 = parseFloat(match[1]);
    const y0 = parseFloat(match[2]);
    const x1 = parseFloat(match[3]);
    const y1 = parseFloat(match[4]);
    const widthPt = Math.abs(x1 - x0);
    const heightPt = Math.abs(y1 - y0);
    if (!(widthPt > 0) || !(heightPt > 0)) {
        return null;
    }
    return { widthPt, heightPt };
}
// PDF points (1/72") to microns (Electron pageSize unit, 1/25400").
function pointsToMicrons(points) {
    return Math.round((points / 72) * 25400);
}
function toPrintPageSize({ widthPt, heightPt }) {
    return {
        pageSize: {
            width: pointsToMicrons(widthPt),
            height: pointsToMicrons(heightPt),
        },
        landscape: widthPt > heightPt,
    };
}
export function buildNativePrintOptions(deviceName, page) {
    const { pageSize, landscape } = toPrintPageSize(page);
    return {
        silent: true,
        deviceName,
        color: false,
        landscape,
        pageSize,
        margins: { marginType: 'custom', top: 0, bottom: 0, left: 0, right: 0 },
    };
}

JS;

// Both trees, because either can be the one a bundler reads: the scaffold is
// what `native:install --publish` lays down and what electron-vite opens, and
// vendor is what the scaffold is copied from and what the arch rule walks.
const PDF_PAGE_SIZE_TARGETS = [
    'nativephp/electron/electron-plugin/dist/server/pdfPageSize.js',
    'vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/pdfPageSize.js',
];

$root = dirname(__DIR__);
$written = 0;
$present = 0;

foreach (PDF_PAGE_SIZE_TARGETS as $relative) {
    $path = $root.'/'.$relative;

    // The directory standing in for the tree: absent means this root does not
    // hold that half at all, which is ordinary — the scaffold exists only after
    // native:install, and vendor only after composer install.
    if (! is_dir(dirname($path))) {
        continue;
    }

    if (is_file($path)) {
        $present++;

        continue;
    }

    if (@file_put_contents($path, PDF_PAGE_SIZE_JS) === false) {
        fwrite(STDERR, sprintf("nativephp_supply_pdf_page_size: could not write %s\n", $relative));

        exit(1);
    }

    $written++;
}

printf(
    "nativephp_supply_pdf_page_size: %d written, %d already present.\n",
    $written,
    $present,
);
