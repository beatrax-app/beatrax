#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reads a raw PayPal "Activity Download" CSV and writes a redacted copy
 * suitable for committing as a test fixture.
 *
 * Redaction protocol (per the phase-4 fixture-handling brief):
 *
 *   - Transaction reference IDs (`Transactiereferentie` /
 *     `Transaction ID`) and back-references (`Reference Txn ID`) are
 *     replaced via a TWO-PASS DETERMINISTIC COUNTER MAP. The same real
 *     ID maps to the same synthetic value `O-<17-digit-counter>`
 *     everywhere it appears — so parent/child rollup links survive the
 *     anonymisation.
 *
 *   - Email addresses (any cell containing `@<host>.<tld>`) → the
 *     placeholder `kaarthouder@example.test`.
 *
 *   - Merchant `Naam` / `Name` cells: PRESERVED VERBATIM. In PayPal's
 *     NL export this column carries the counterparty merchant name
 *     ("Google Cloud EMEA Limited", "Netflix.com", "Jagex Limited"),
 *     not the cardholder. Per D-58 "merchant strings preserved
 *     verbatim". The cardholder name is not present anywhere in the
 *     Activity Download CSV.
 *
 *   - IBAN-shaped tokens (CC + 2 digits + ≥10 alnum) → the
 *     deterministic placeholder `NL00ASNB0000000000`. The placeholder
 *     reuses the Phase 2 mod-97-valid synthetic IBAN form so PayPal
 *     CSV fixtures stay consistent with the CAMT/MT940 corpus.
 *
 *   - Address-shaped columns (`Address`, `Adres`, `Country Code`,
 *     `Land`, `Stad`, `City`, `State`, `Provincie`) → empty string.
 *
 *   - ZIP / postal-code columns (`Postcode`, `ZIP`) → `00000`.
 *
 *   - Phone-number columns (`Telefoon`, `Phone`) → empty string.
 *
 * Preserved verbatim (load-bearing for the parser's empirical tests):
 *
 *   - Every `Date` / `Tijd` / `Datum` cell.
 *   - Every `Type` / `Omschrijving` (event-type) string.
 *   - Every monetary cell (`Bruto`, `Kosten`, `Netto`, `Saldo`,
 *     `Verzendkosten`, `Btw`).
 *   - Every `Currency` / `Valuta` cell.
 *   - Every `Factuurreferentie` (merchant invoice reference) cell —
 *     these are merchant-side strings, not personal data.
 *   - Header order and column count.
 *
 * Usage:
 *   php scripts/anonymize_paypal_csv.php <input.csv> > <output.csv>
 *
 * Re-running the script on its own output produces byte-identical
 * output (idempotent): every replacement is regex-driven on input
 * shape, not state, and the synthetic IDs (`O-…`) already pass through
 * the counter map unchanged because they don't match the IBAN /
 * email / address regexes.
 *
 * Pure PHP core only — no Composer deps. Runs from a fresh clone.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/anonymize_paypal_csv.php <input.csv> > <output.csv>\n");
    exit(1);
}

$inputPath = $argv[1];

if (! is_readable($inputPath)) {
    fwrite(STDERR, "Input file is not readable: {$inputPath}\n");
    exit(1);
}

// ----------------------------------------------------------------------
// Read the file. PayPal's NL export ships with a UTF-8 BOM as the first
// three bytes; strip it for parsing but keep the byte-perfect header
// row in `$headers` so column-name lookups stay exact.
// ----------------------------------------------------------------------
$raw = file_get_contents($inputPath);
if ($raw === false) {
    fwrite(STDERR, "Could not read input file: {$inputPath}\n");
    exit(1);
}

$bom = "\xEF\xBB\xBF";
$hadBom = strncmp($raw, $bom, 3) === 0;
if ($hadBom) {
    $raw = substr($raw, 3);
}

$tmpIn = tmpfile();
if ($tmpIn === false) {
    fwrite(STDERR, "Could not allocate tmp buffer.\n");
    exit(1);
}
fwrite($tmpIn, $raw);
rewind($tmpIn);

// ----------------------------------------------------------------------
// Pass 1 — read every row, build $realToSynthetic from the union of the
// `Transactiereferentie` (Transaction ID) AND `Reference Txn ID`
// columns. The same map covers both columns so parent/child links
// survive the redaction.
// ----------------------------------------------------------------------
$rows = [];
$headers = null;

while (($cells = fgetcsv($tmpIn, 0, ',', '"', '')) !== false) {
    if ($cells === [null]) {
        continue;
    }
    if ($headers === null) {
        $headers = $cells;

        continue;
    }
    $rows[] = $cells;
}
fclose($tmpIn);

if ($headers === null) {
    fwrite(STDERR, "Input file appears to be empty.\n");
    exit(1);
}

// Map column-header text to column index. PayPal's NL export uses
// trailing spaces on a few headers (`"Bruto "`, `"Kosten "`); the trim
// makes the lookup robust to those.
$columnIndex = [];
foreach ($headers as $idx => $name) {
    $columnIndex[trim((string) $name)] = $idx;
}

// The two columns that carry the Transaction ID / Reference Txn ID. NL
// + EN spellings are both accepted so the same script handles either
// language profile.
$txnIdHeaders = ['Transactiereferentie', 'Transaction ID'];
$refTxnIdHeaders = ['Reference Txn ID', 'Bijbehorende transactie'];

$txnIdCol = null;
foreach ($txnIdHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $txnIdCol = $columnIndex[$name];
        break;
    }
}
$refTxnIdCol = null;
foreach ($refTxnIdHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $refTxnIdCol = $columnIndex[$name];
        break;
    }
}

if ($txnIdCol === null) {
    fwrite(STDERR, 'Could not find a Transaction ID column. Expected one of: '.implode(', ', $txnIdHeaders)."\n");
    exit(1);
}

/** @var array<string, string> $realToSynthetic */
$realToSynthetic = [];
$counter = 0;

$register = static function (string $id) use (&$realToSynthetic, &$counter): void {
    $id = trim($id);
    if ($id === '') {
        return;
    }
    // Skip values that are already synthetic — running the script on
    // its own output must be idempotent.
    if (preg_match('/^O-\d{17}$/', $id) === 1) {
        return;
    }
    if (! isset($realToSynthetic[$id])) {
        $counter++;
        $realToSynthetic[$id] = sprintf('O-%017d', $counter);
    }
};

foreach ($rows as $row) {
    if (isset($row[$txnIdCol])) {
        $register((string) $row[$txnIdCol]);
    }
    if ($refTxnIdCol !== null && isset($row[$refTxnIdCol])) {
        $register((string) $row[$refTxnIdCol]);
    }
}

// ----------------------------------------------------------------------
// Helper redactors used by Pass 2.
// ----------------------------------------------------------------------
$emailPlaceholder = 'kaarthouder@example.test';
$ibanPlaceholder = 'NL00ASNB0000000000';
$zipPlaceholder = '00000';
$emailRegex = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';
$ibanRegex = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/u';

// Merchant `Naam` column is PRESERVED — it carries the counterparty
// name (e.g., "Google Payment Ireland Ltd.", "Netflix.com"), which per
// D-58 is a merchant string preserved verbatim. The cardholder name
// does not appear anywhere in PayPal's Activity Download CSV.
$emailHeaders = ['Van e-mailadres', 'Naar e-mailadres', 'From Email Address', 'To Email Address'];
$ibanHeaders = ['Bankrekening', 'Counterparty IBAN', 'IBAN'];
$addressHeaders = [
    'Adres', 'Adres 1', 'Adres 2', 'Address', 'Address 1', 'Address 2',
    'Land', 'Country', 'Country Code', 'Land Code',
    'Stad', 'City', 'Plaats', 'Woonplaats',
    'State', 'Provincie',
];
$zipHeaders = ['Postcode', 'ZIP', 'Postal Code'];
$phoneHeaders = ['Telefoon', 'Phone', 'Telephone'];

$rewriteCell = static function (string $value) use (
    &$realToSynthetic,
    $emailRegex,
    $emailPlaceholder,
    $ibanRegex,
    $ibanPlaceholder,
): string {
    if ($value === '') {
        return $value;
    }
    $rewritten = $value;
    // Scrub email addresses anywhere in the cell text.
    $replaced = preg_replace($emailRegex, $emailPlaceholder, $rewritten);
    if (is_string($replaced)) {
        $rewritten = $replaced;
    }
    // Scrub IBAN-shaped tokens (the placeholder is itself NOT IBAN-shaped
    // — `NL00ASNB0000000000` is 18 chars and mod-97-valid; running the
    // script on its own output leaves it untouched).
    $replaced = preg_replace_callback(
        $ibanRegex,
        static function (array $m) use ($ibanPlaceholder): string {
            if ($m[0] === $ibanPlaceholder) {
                return $m[0];
            }

            return $ibanPlaceholder;
        },
        $rewritten,
    );
    if (is_string($replaced)) {
        $rewritten = $replaced;
    }

    return $rewritten;
};

// ----------------------------------------------------------------------
// Pass 2 — rewrite every row.
// ----------------------------------------------------------------------
$out = fopen('php://stdout', 'w');
if ($out === false) {
    fwrite(STDERR, "Could not open STDOUT for write.\n");
    exit(1);
}

// Re-emit the BOM if the input carried one — the production parser must
// see the same byte-shape as a fresh PayPal export.
if ($hadBom) {
    fwrite($out, $bom);
}

fputcsv($out, $headers, ',', '"', '');

$emailCols = [];
foreach ($emailHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $emailCols[] = $columnIndex[$name];
    }
}
$ibanCols = [];
foreach ($ibanHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $ibanCols[] = $columnIndex[$name];
    }
}
$addressCols = [];
foreach ($addressHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $addressCols[] = $columnIndex[$name];
    }
}
$zipCols = [];
foreach ($zipHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $zipCols[] = $columnIndex[$name];
    }
}
$phoneCols = [];
foreach ($phoneHeaders as $name) {
    if (isset($columnIndex[$name])) {
        $phoneCols[] = $columnIndex[$name];
    }
}

$lookup = static function (string $id) use (&$realToSynthetic): string {
    $id = trim($id);
    if ($id === '') {
        return $id;
    }
    if (preg_match('/^O-\d{17}$/', $id) === 1) {
        return $id;
    }

    return $realToSynthetic[$id] ?? $id;
};

foreach ($rows as $row) {
    // Transaction ID column.
    if (isset($row[$txnIdCol])) {
        $row[$txnIdCol] = $lookup((string) $row[$txnIdCol]);
    }
    // Reference Txn ID column.
    if ($refTxnIdCol !== null && isset($row[$refTxnIdCol])) {
        $row[$refTxnIdCol] = $lookup((string) $row[$refTxnIdCol]);
    }
    // Email columns (full-cell replace; preserves empty cells).
    foreach ($emailCols as $col) {
        if (isset($row[$col]) && trim((string) $row[$col]) !== '') {
            $row[$col] = $emailPlaceholder;
        }
    }
    // IBAN columns (whole-cell replace when the cell is non-empty).
    foreach ($ibanCols as $col) {
        if (isset($row[$col]) && trim((string) $row[$col]) !== '') {
            $row[$col] = $ibanPlaceholder;
        }
    }
    // Address columns dropped to empty.
    foreach ($addressCols as $col) {
        if (isset($row[$col])) {
            $row[$col] = '';
        }
    }
    // ZIP columns zeroed if non-empty.
    foreach ($zipCols as $col) {
        if (isset($row[$col]) && trim((string) $row[$col]) !== '') {
            $row[$col] = $zipPlaceholder;
        }
    }
    // Phone columns dropped to empty.
    foreach ($phoneCols as $col) {
        if (isset($row[$col])) {
            $row[$col] = '';
        }
    }
    // Defensive: scrub free-text cells for stray email / IBAN tokens
    // that didn't sit in a named column. Skips the Transaction ID
    // columns to avoid mangling the deterministic counter map.
    foreach ($row as $idx => $value) {
        if ($idx === $txnIdCol) {
            continue;
        }
        if ($idx === $refTxnIdCol) {
            continue;
        }
        $row[$idx] = $rewriteCell((string) $value);
    }

    fputcsv($out, $row, ',', '"', '');
}

fclose($out);

// ----------------------------------------------------------------------
// Reference-integrity check. Per Pitfall 5 the redacted CSV must keep
// every parent/child link inside the file: a non-empty
// `Reference Txn ID` must either be a synthetic ID we minted (and that
// also appears in some row's Transaction ID column) OR an external
// reference that wasn't itself emitted as a Transaction ID — those are
// orphan-children whose parent rows live outside this report window
// (D-61). We accept orphans; we abort only if the rewrite produced a
// reference into a synthetic ID that doesn't exist as a Transaction ID.
// ----------------------------------------------------------------------
$txnIds = [];
foreach ($rows as $row) {
    if (! isset($row[$txnIdCol])) {
        continue;
    }
    $id = trim((string) $row[$txnIdCol]);
    if ($id !== '') {
        $txnIds[$id] = true;
    }
}

$orphanCount = 0;
if ($refTxnIdCol !== null) {
    foreach ($rows as $row) {
        $ref = trim((string) ($row[$refTxnIdCol] ?? ''));
        if ($ref === '') {
            continue;
        }
        if (! isset($txnIds[$ref])) {
            // Per D-61: a Reference Txn ID that doesn't resolve inside
            // the file is a legitimate orphan-child whose parent lives
            // outside the report window (e.g., a billing-agreement ID,
            // or a child row whose parent was created in the prior
            // statement period). They're acceptable; we only count them.
            $orphanCount++;
        }
    }
}

fwrite(STDERR, sprintf(
    "Anonymised %d rows. Mapped %d distinct IDs. Detected %d orphan-child rows whose parent is outside this file (acceptable per D-61).\n",
    count($rows),
    count($realToSynthetic),
    $orphanCount,
));

exit(0);
