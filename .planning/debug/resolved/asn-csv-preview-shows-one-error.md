---
status: awaiting_human_verify
trigger: "Importing ASN bank CSV through desktop app: preview screen shows only one line with an error instead of parsed transactions"
created: 2026-05-23T00:00:00Z
updated: 2026-05-24T00:00:00Z
---

## Current Focus

reasoning_checkpoint:
  hypothesis: "HeaderSniffer rejects real ASN CSV exports because EXPECTED_COLUMN_COUNT is locked to 20 but real exports ship 19 columns (no trailing Categorie column). The SniffMismatchException propagates to ImportPipeline's outer Throwable catch which produces exactly one error row."
  confirming_evidence:
    - "User's actual upload at storage/app/private/imports/1/4ac03b16...csv has 19 comma-separated columns; header ends at 'Afschriftnummer'."
    - "Fixture asn-sample-1.csv has 20 columns ending in 'Afschriftnummer,Categorie'."
    - "Repro script with a mismatched-shape file produced row_count=1, status=error, message='Expected 20 columns, got X. This file does not match the ASN CSV layout.' — bit-for-bit matches the user's reported symptom."
    - "AsnCsvColumnMap maps columns 0..18 (POSTED_DATE..STATEMENT_NUMBER) which are all present in the real 19-col export; only column 19 (CATEGORY) is missing."
  falsification_test: "If I relax EXPECTED_COLUMN_COUNT to accept both 19 and 20 columns, and update the adapter to tolerate a missing CATEGORY cell, the user's real upload should produce a full row preview."
  fix_rationale: "Tightening the sniff was correct in principle but locked to one observed export variant. ASN exports both 19-col and 20-col shapes (Categorie was added in some periods). Accept either, and treat the trailing Categorie column as optional in the column map."
  blind_spots: "Older 17/18-col variants mentioned in asn-sample-1.md may still exist but are outside user's reported case. Not addressed here."

## Symptoms

expected: Importing an ASN CSV produces a preview screen with multiple parsed transaction rows
actual: Preview screen shows only one line, status=error, message "Expected 20 columns, got 19. This file does not match the ASN CSV layout."
errors: SniffMismatchException from Modules/Ingestion/Public/Services/HeaderSniffer.php sniffAsnCsv()
reproduction: Drop a real ASN export (any export from the user's Mijn ASN portal) into the desktop import wizard
started: Pre-existing since Phase 11 sniffer was added; only surfaces when the user uploads a real export rather than the in-repo fixture

## Eliminated

## Evidence

- timestamp: 2026-05-23T00:00:00Z
  checked: tests/fixtures/asn-sample-1.csv format
  found: 20 columns, comma-delimited, header row present, dates DD-MM-YYYY
  implication: Fixture is the 20-col variant with trailing Categorie column

- timestamp: 2026-05-23T00:00:00Z
  checked: Run full pipeline (RunImport->runFromUpload) against fixture
  found: 229 rows, 0 errors — pipeline works correctly against fixture
  implication: Bug is not in the parser code path; symptom requires a different input shape

- timestamp: 2026-05-23T00:00:00Z
  checked: User's real upload at storage/app/private/imports/1/4ac03b16...csv
  found: 19 columns; header is "Datum,Je rekening,Van / naar,Naam,Adres,Postcode,Woonplaats,Valuta saldo,Saldo voor boeking,Valuta,Bedrag bij / af,Verwerkingsdatum,Valutadatum,Code,Type,Volgnummer,Betalingskenmerk,Omschrijving,Afschriftnummer" — no Categorie column
  implication: Real ASN exports ship 19 cols; sniff fails because EXPECTED_COLUMN_COUNT is locked to 20

- timestamp: 2026-05-23T00:00:00Z
  checked: Reproduced symptom by feeding pipeline a malformed CSV
  found: Output was exactly "Row count: 1, Error: Expected 20 columns, got 1. This file does not match the ASN CSV layout."
  implication: Confirms ImportPipeline's outer Throwable catch produces exactly the reported single-error preview when sniff fails

## Resolution

root_cause: HeaderSniffer.sniffAsnCsv() required EXPECTED_COLUMN_COUNT=20 exactly, but ASN exports also ship a 19-column variant (no trailing Categorie column). Real user uploads hit this path; the strict equality check rejected them; SniffMismatchException was caught by ImportPipeline's outer Throwable handler and rendered as a single error row.
fix: Added AsnCsvHeaderProfile::ACCEPTED_COLUMN_COUNTS = [19, 20] and switched sniffAsnCsv() from `!== 20` to `! in_array($count, ACCEPTED_COLUMN_COUNTS, strict)`. Updated user-facing message to "Expected 19 or 20 columns, got X". AsnCsvAdapter needed no change — it only reads columns 0..17.
verification: |
  Reproduced the symptom by feeding a malformed CSV through the live pipeline (row_count=1, status=error). Captured the user's real 19-col upload from storage/app/private/imports/1/. With the fix applied, that same file parses to 229 rows. All 2192 Pest tests pass (24845 assertions). Larastan level 10 strict clean. Pint clean. Two new tests added — adapter parses 19-col input + sniffer accepts 19-col input.
files_changed:
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php
  - Modules/Ingestion/Public/Services/HeaderSniffer.php
  - Modules/Ingestion/tests/Feature/HeaderSnifferTest.php
  - Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php
