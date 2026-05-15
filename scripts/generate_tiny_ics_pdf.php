#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` —
 * a tiny, deterministically parseable, synthetic PDF used by the ICS
 * PDF idempotency-contract test to exercise the real pdftotext binary
 * path without depending on a real ICS export.
 *
 * The implementation hand-crafts a minimal PDF 1.4 byte stream
 * (one Page, one Type1 Helvetica font, one Tj-based content stream)
 * which keeps the committed fixture under 1 KB.
 *
 * The synthetic content embeds the canonical anonymisation
 * placeholders (`KAARTHOUDER`, `****-****-****-XXXX`) and the
 * empirical statement-summary anchor tokens (`Vorig openstaand
 * saldo`, `Totaal ontvangen betalingen`, `Totaal nieuwe uitgaven`,
 * `Nieuw openstaand saldo`) plus ONE EUR-native transaction row
 * whose load-bearing literal is `SYNTHETIC` — the contract test
 * anchors on that literal to assert one row was parsed.
 *
 * Re-running this script overwrites the committed fixture
 * byte-identically (no entropy in the generator).
 *
 * Usage:
 *     php scripts/generate_tiny_ics_pdf.php
 */
$repoRoot = dirname(__DIR__);
$output = $repoRoot.'/Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf';

// Synthetic content embedding:
//   - canonical anonymisation placeholders (KAARTHOUDER, ****-****-****-XXXX);
//   - the six empirical statement-summary anchor tokens with stub € amounts;
//   - one EUR-native transaction row mirroring the real layout shape
//     (transactiedatum + boekdatum columns, no native-currency column,
//     trailing settled-EUR amount, `Af` direction marker). The
//     `SYNTHETIC` literal is the contract test's anchor.
$contentLines = [
    '15 april 2026',
    'KAARTHOUDER ****-****-****-XXXX',
    'Vorig openstaand saldo  EUR 0,00',
    'Totaal ontvangen betalingen  EUR 0,00',
    'Totaal nieuwe uitgaven  EUR 1,00',
    'Nieuw openstaand saldo  EUR 1,00',
    'Bestedingslimiet  EUR 100,00',
    'Minimaal te betalen bedrag  EUR 1,00',
    '12 apr. 12 apr. SYNTHETIC ICS TINY  1,00  Af',
];

$content = 'BT /F1 10 Tf 50 750 Td ('.$contentLines[0].') Tj';
for ($i = 1, $n = count($contentLines); $i < $n; $i++) {
    $content .= ' 0 -15 Td ('.$contentLines[$i].') Tj';
}
$content .= ' ET';
$contentLen = strlen($content);

$objects = [
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
        .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    4 => "<< /Length {$contentLen} >>\nstream\n{$content}\nendstream",
    5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
];

$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $n => $obj) {
    $offsets[$n] = strlen($pdf);
    $pdf .= "{$n} 0 obj\n{$obj}\nendobj\n";
}
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 ".(count($objects) + 1)."\n";
$pdf .= "0000000000 65535 f \n";
foreach ($offsets as $off) {
    $pdf .= sprintf("%010d 00000 n \n", $off);
}
$pdf .= 'trailer'."\n";
$pdf .= '<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
$pdf .= 'startxref'."\n";
$pdf .= $xrefOffset."\n";
$pdf .= '%%EOF'."\n";

$written = file_put_contents($output, $pdf);
if ($written === false) {
    fwrite(STDERR, "Failed to write {$output}\n");
    exit(1);
}

fwrite(STDERR, "Wrote {$written} bytes to {$output}\n");
exit(0);
