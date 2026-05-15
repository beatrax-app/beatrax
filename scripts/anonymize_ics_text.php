#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reads an extracted ICS PDF text dump (produced via
 * `pdftotext -layout -enc UTF-8 -eol unix -nopgbrk`) and writes a
 * redacted version suitable for committing as a test fixture.
 *
 * Redaction protocol:
 *   - Card-number runs (>= 12 contiguous digits, optionally interrupted
 *     by spaces or hyphens) -> `****-****-****-XXXX` where XXXX is
 *     randomised digits (NOT the original last-four).
 *   - Spaced-IBAN tokens like `NL75 ABNA 0844 9970 56` -> the deterministic
 *     placeholder `NL00 BANK 0000 0000 00` (spacing preserved).
 *   - IBAN-shaped tokens (NL / DE / BE / etc. + 2 check digits +
 *     >= 10 alnum) -> `NL00BANK0000000000` (deterministic placeholder).
 *   - Card-last-four lines `Uw Card met als laatste vier cijfers NNNN` ->
 *     `Uw Card met als laatste vier cijfers XXXX`.
 *   - Cardholder-name line (heuristic: a line containing only initials +
 *     surname in all caps after the card-last-four line) -> literal
 *     `KAARTHOUDER` (Dutch, all caps).
 *   - ICS klantnummer (11-digit run preceded by `klantnummer` or appearing
 *     at the statement-header customer-id column) -> `00000000000`.
 *   - Email addresses -> `kaarthouder@example.invalid`.
 *   - Phone numbers (NL-style runs) -> `+31 6 0000 0000`.
 *   - Dates, amounts, currency codes, merchant strings -> preserved
 *     VERBATIM (load-bearing for the parser's empirical tests).
 *
 * Usage:
 *   php scripts/anonymize_ics_text.php <input.txt> <output.txt>
 *
 * Re-running on a fresh extraction is safe (idempotent — every
 * replacement is regex-driven on input shape, not state).
 *
 * Pure PHP core only — no Composer deps. Runs from a fresh clone.
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/anonymize_ics_text.php <input.txt> <output.txt>\n");
    exit(1);
}

$inputPath = $argv[1];
$outputPath = $argv[2];

if (! is_readable($inputPath)) {
    fwrite(STDERR, "Input file is not readable: $inputPath\n");
    exit(1);
}

$contents = file_get_contents($inputPath);
if ($contents === false) {
    fwrite(STDERR, "Could not read input file: $inputPath\n");
    exit(1);
}

$cardCount = 0;
$ibanCount = 0;
$ibanSpacedCount = 0;
$nameCount = 0;
$customerIdCount = 0;
$emailCount = 0;
$phoneCount = 0;
$cardLast4Count = 0;

// 1. Card-last-four line: `Uw Card met als laatste vier cijfers NNNN` -> XXXX.
//    Also injects a synthetic full-card placeholder `****-****-****-XXXX`
//    on the same line so the fixture carries the canonical four-group
//    redaction shape (the empirical ICS PDF only renders the last-four,
//    but the canonical placeholder is the four-group form so downstream
//    tests can grep for either tail).
//    Must run BEFORE the generic 12+ digit run pattern.
$contents = preg_replace_callback(
    '/(Uw Card met als laatste vier cijfers\s+)\d{4}/u',
    function (array $m) use (&$cardLast4Count): string {
        $cardLast4Count++;

        return $m[1].'XXXX (****-****-****-XXXX)';
    },
    $contents
) ?? $contents;

// 2. Spaced IBAN tokens like `NL75 ABNA 0844 9970 56` (two-letter country +
//    2 digits + four 4-char alnum groups + 2-char tail). Run BEFORE the
//    plain-IBAN regex so the spacing is preserved as `NL00 BANK 0000 0000 00`.
$contents = preg_replace_callback(
    '/\b[A-Z]{2}\d{2}(?: [A-Z0-9]{4}){3} \d{2}\b/u',
    function (array $m) use (&$ibanSpacedCount): string {
        $ibanSpacedCount++;

        return 'NL00 BANK 0000 0000 00';
    },
    $contents
) ?? $contents;

// 3. Card-number runs of 12+ contiguous digits (optionally hyphen/space
//    separated in 4-digit groups). Replace with `****-****-****-XXXX`.
$contents = preg_replace_callback(
    '/\b(?:\d[\s-]?){11,}\d\b/u',
    function (array $m) use (&$cardCount): string {
        $cardCount++;

        return '****-****-****-XXXX';
    },
    $contents
) ?? $contents;

// 4. ICS klantnummer (11-digit run) — catches the customer-id column
//    that wasn't caught by the 12+ pattern above. Conservative: only
//    runs of EXACTLY 11 digits that stand alone (whitespace-bounded).
//    Replaced with a non-digit placeholder so the phone regex below
//    cannot cascade-match it together with neighbouring page-number
//    columns.
$contents = preg_replace_callback(
    '/(?<![\d-])\d{11}(?![\d-])/u',
    function (array $m) use (&$customerIdCount): string {
        $customerIdCount++;

        return 'KLANTNUMMER';
    },
    $contents
) ?? $contents;

// 5. Compact IBAN-shaped tokens (no spaces): country + 2 check digits +
//    >= 10 alnum. Replaced with the project-wide anonymised-IBAN
//    placeholder so the redacted fixture stays grep-stable.
$contents = preg_replace_callback(
    '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,}\b/u',
    function (array $m) use (&$ibanCount): string {
        // Don't double-redact our placeholder.
        if ($m[0] === 'NL00BANK0000000000') {
            return $m[0];
        }
        $ibanCount++;

        return 'NL00BANK0000000000';
    },
    $contents
) ?? $contents;

// 6. Cardholder-name line. Heuristic: a standalone line consisting of
//    1..3 single-letter initials each followed by a period and a space,
//    then a surname token in all-caps Latin (allowing hyphens or short
//    Dutch tussenvoegsels in caps). Conservative on purpose — keeps
//    statement-summary headings ('Periode', 'Beginsaldo' etc.) untouched.
$contents = preg_replace_callback(
    '/^\s*(?:[A-Z]\.\s?){1,3}[A-Z][A-Z\-\']{1,}(?:\s+[A-Z][A-Z\-\']{1,}){0,3}\s*$/um',
    function (array $m) use (&$nameCount): string {
        $nameCount++;
        // Preserve the leading whitespace so column-alignment stays.
        $leading = '';
        if (preg_match('/^(\s*)/', $m[0], $lm) === 1) {
            $leading = $lm[1];
        }

        return $leading.'KAARTHOUDER';
    },
    $contents
) ?? $contents;

// 7. Email addresses.
$contents = preg_replace_callback(
    '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u',
    function (array $m) use (&$emailCount): string {
        $emailCount++;

        return 'kaarthouder@example.invalid';
    },
    $contents
) ?? $contents;

// 8. NL-style phone numbers anchored on a `Telefoon` / `Tel.` keyword.
//    Conservative on purpose — a free-floating digit-and-separator regex
//    cascade-matches across statement columns (page numbers, klantnummer
//    placeholders, valutadatum sequences). The ICS statement renders the
//    issuer phone number with a `Telefoon ` prefix on line 4; that's the
//    only phone number in the document, so the anchored regex is exact.
$contents = preg_replace_callback(
    '/(Telefoon\s+|Tel\.\s+)\+?\d[\d\s\-]{6,}\d/u',
    function (array $m) use (&$phoneCount): string {
        $phoneCount++;

        return $m[1].'+31 6 0000 0000';
    },
    $contents
) ?? $contents;

if (file_put_contents($outputPath, $contents) === false) {
    fwrite(STDERR, "Could not write output file: $outputPath\n");
    exit(1);
}

fwrite(STDERR, sprintf(
    "Redacted %d card-numbers, %d compact-IBANs, %d spaced-IBANs, %d names, %d customer-ids, %d emails, %d phones, %d card-last-fours — wrote %s\n",
    $cardCount,
    $ibanCount,
    $ibanSpacedCount,
    $nameCount,
    $customerIdCount,
    $emailCount,
    $phoneCount,
    $cardLast4Count,
    $outputPath
));

exit(0);
