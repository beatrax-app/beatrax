#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates the two tiny, deterministically parseable, synthetic PDFs under
 * `Modules/Ingestion/tests/fixtures/ics/` used by the ICS PDF
 * idempotency-contract test and by the receipt/statement fingerprint-parity
 * contract, so both exercise the real pdftotext binary path without
 * depending on a real ICS export:
 *
 *   - `ics-sample-tiny.pdf`         one EUR-native transaction row.
 *   - `ics-sample-tiny-foreign.pdf` one row billed in USD and settled in
 *                                   euros, plus its `Wisselkoers` line —
 *                                   the shape whose settled leg the receipt
 *                                   matcher has to agree with.
 *
 * The implementation hand-crafts a minimal PDF 1.4 byte stream
 * (one Page, one Type1 Helvetica font, one Tj-based content stream)
 * which keeps each committed fixture under 1 KB.
 *
 * The synthetic content embeds the canonical anonymisation
 * placeholders (`KAARTHOUDER`, `****-****-****-XXXX`) and the
 * empirical statement-summary anchor tokens (`Vorig openstaand
 * saldo`, `Totaal ontvangen betalingen`, `Totaal nieuwe uitgaven`,
 * `Nieuw openstaand saldo`); the load-bearing row literal is
 * `SYNTHETIC` — the contract tests anchor on that literal to assert
 * one row was parsed.
 *
 * Re-running this script overwrites the committed fixtures
 * byte-identically (no entropy in the generator).
 *
 * Usage:
 *     php scripts/generate_tiny_ics_pdf.php
 */
$repoRoot = dirname(__DIR__);
$fixtureDir = $repoRoot.'/Modules/Ingestion/tests/fixtures/ics';

// Synthetic content embedding:
//   - canonical anonymisation placeholders (KAARTHOUDER, ****-****-****-XXXX);
//   - the six empirical statement-summary anchor tokens with stub € amounts;
//   - one transaction row mirroring the real layout shape (transactiedatum +
//     boekdatum columns, trailing settled-EUR amount, `Af` direction marker).
$statementLines = static fn (string $newCharges, string $closing, string $minDue): array => [
    '15 april 2026',
    'KAARTHOUDER ****-****-****-XXXX',
    'Vorig openstaand saldo  EUR 0,00',
    'Totaal ontvangen betalingen  EUR 0,00',
    'Totaal nieuwe uitgaven  EUR '.$newCharges,
    'Nieuw openstaand saldo  EUR '.$closing,
    'Bestedingslimiet  EUR 100,00',
    'Minimaal te betalen bedrag  EUR '.$minDue,
];

$fixtures = [
    'ics-sample-tiny.pdf' => [
        ...$statementLines('1,00', '1,00', '1,00'),
        '12 apr. 12 apr. SYNTHETIC ICS TINY  1,00  Af',
    ],
    // The foreign column carries the figure the merchant billed and the euro
    // column the movement the card made — the two are one charge written
    // twice, which is why the euro one is the settled leg on both sides.
    'ics-sample-tiny-foreign.pdf' => [
        ...$statementLines('43,71', '43,71', '43,71'),
        '12 apr. 12 apr. SYNTHETIC ICS FOREIGN  50,00 USD  43,71  Af',
        'Wisselkoers USD  1,14390',
    ],
];

foreach ($fixtures as $filename => $contentLines) {
    $output = $fixtureDir.'/'.$filename;

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
        4 => sprintf("<< /Length %s >>\nstream\n%s\nendstream", $contentLen, $content),
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $n => $obj) {
        $offsets[$n] = strlen($pdf);
        $pdf .= sprintf("%s 0 obj\n%s\nendobj\n", $n, $obj);
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
        fwrite(STDERR, sprintf("Failed to write %s\n", $output));
        exit(1);
    }

    fwrite(STDERR, sprintf("Wrote %s bytes to %s\n", $written, $output));
}

exit(0);
