---
status: resolved
trigger: PayPal section renders "0 ROWS · ✓ READY" in FirstImportStep after ConnectPaypalStep submits a valid PayPal Activity Download CSV
created: 2026-05-27T14:05:24Z
updated: 2026-05-27T16:45:00Z
phase: 16.1.2
related_plans:
  - 16.1.2-02-PLAN.md
round: 2
---

## Round 2 — Real root cause found and fixed

The Round 1 fix (account-first, then preview) closed a real design defect but
was NOT the live root cause. Driving the evidence-gathering plan (probes A–E)
against the user's actual NativePHP SQLite DB and on-disk file cache revealed
the true bug: the file the user uploaded was a **PayPal `BALANCE_RECONCILIATION_REPORT`**
CSV — header row `"RH","Naam rapport","Status rapport",...` — NOT the
**PayPal "Activity Download"** CSV the step expects. The `HeaderSniffer` already
detects this shape and throws `UnsupportedPaypalCsvShapeException` with an
actionable message; the `ImportPipeline` correctly catches that and emits a
single `'error'`-status `PreviewRowDto` at `rowIndex 0` so the standalone
`/imports/upload` flow can render the message inline.

But the wizard step's `ConnectPaypalStep::submit()` only treated a re-thrown
`Throwable` as a failure. A "successful" preview return carrying one error row
flowed straight through to:
- stashing `paypal_import_run_id` in `wizard_progress`,
- dispatching `wizard.step.completed`,
which advanced the wizard with a poisoned cache. `BuildConsolidatedPreviewQuery`
then read the cache (1 error row → `$allRows` non-empty → status `'ready'`;
zero `'new'`/`'enriched'` rows → `totalRows = 0`) and rendered
`FROM PAYPAL · 0 ROWS · ✓ READY`.

## Evidence gathering (Round 2 probes)

- timestamp: 2026-05-27T16:25:00Z
  probe: A — recent paypal-csv ImportRun rows
  command: `sqlite3 database/nativephp.sqlite "SELECT id, user_id, source_format, status, sha256, raw_file_path FROM import_runs WHERE source_format = 'paypal-csv' ORDER BY id DESC LIMIT 3;"`
  found: id=2, user_id=1, status=`previewed`, raw_file_path points at the Library/Application Support imports dir
  implication: ImportRun row was created; pipeline ran to completion (no fatal abort)

- timestamp: 2026-05-27T16:25:30Z
  probe: B — synthetic PayPal account state
  command: `sqlite3 database/nativephp.sqlite "SELECT * FROM accounts WHERE iban = 'PAYPAL' OR kind = 'paypal';"`
  found: account id=2, user_id=1, kind=paypal, iban=PAYPAL exists
  implication: Round 1 fix (ensure-then-preview) is working — the account exists. Bug is NOT account-resolution.

- timestamp: 2026-05-27T16:25:45Z
  probe: C — wizard_progress stashed value
  command: `sqlite3 database/nativephp.sqlite "SELECT data FROM wizard_progress WHERE step_key = 'connect-paypal';"`
  found: `{"paypal_import_run_id":2}`
  implication: ConnectPaypalStep stashed the run id and advanced the wizard — symptom-side confirmation

- timestamp: 2026-05-27T16:26:00Z
  probe: D — the actual CSV the user uploaded
  command: Read the file at the raw_file_path from probe A
  found: 5 lines, UTF-8 with BOM. Header row starts with `"RH","Naam rapport"...`. Body contains `RH` (Report Header), `RD` (Record Detail schema), `RF` (Report Footer) rows. Footer says `"Totaal aantal records": "0"`.
  implication: **The file is a PayPal `BALANCE_RECONCILIATION_REPORT`, not an "Activity Download". Zero data rows by design.**

- timestamp: 2026-05-27T16:27:00Z
  probe: E — actual preview cache content for run id 2
  command: `grep` cache files at `/Users/wesselverheij/Library/Application Support/diederik/storage/framework/cache/data/` for `importRunId`
  found: cache file `ad/08/ad08b0f1...` contains exactly one row with `status:"error"` and error message: `"This looks like a PayPal Balance Reconciliation Report CSV, not the Activity Download. In the PayPal portal, open Activity → Statements → Activity Download (CSV) and re-export."`
  implication: **Pipeline produced exactly 1 error row at rowIndex 0 from the file-level Throwable catch — the HeaderSniffer rejected the BRR shape, the exception was converted to a single-error-row preview, and ConnectPaypalStep blindly stashed the run id.**

## Resolution (Round 2 — the real fix)

root_cause: |
  `ConnectPaypalStep::submit()` did not detect the "preview produced only error
  rows" case. The shared `ImportPipeline::preview()` converts every typed parse-
  time exception (`UnsupportedPaypalCsvShapeException`,
  `UnsupportedPaypalCsvLanguageException`, `SniffMismatchException`, and any
  other Throwable that escapes the per-row try/catch) into a single
  ERROR-status `PreviewRowDto` at `rowIndex 0` so the standalone
  `/imports/upload` preview surface can render the message inline. For the
  wizard step that contract masquerades as a successful preview:
  `runFromUpload` returns normally with a non-empty rows list, so the step's
  only failure path (a re-thrown `Throwable`) never fires. Without the gate
  the step stashed the poisoned import-run id, dispatched
  `wizard.step.completed`, and the FirstImportStep consolidated section
  rendered `FROM PAYPAL · 0 ROWS · ✓ READY` because no row carries status
  `'new'` or `'enriched'`.

  The user's specific failure-shape was a PayPal Balance Reconciliation
  Report (`BALANCE_RECONCILIATION_REPORT`) CSV uploaded by mistake instead
  of an Activity Download CSV. The same bug fires for any other fatal-
  parse path: unsupported PayPal locale, sniff mismatch, malformed file.

fix: |
  Added a `fatalParseMessage()` gate to `ConnectPaypalStep::submit()` that
  inspects the `ImportPreviewResult` returned by `runFromUpload`. When every
  row carries status `'error'` AND there are no unknown-IBAN naming prompts
  (so the preview produced zero committable signal), the first error row's
  message is surfaced verbatim on `$uploadError`, a warning is logged, and
  the step returns WITHOUT stashing the run id or dispatching
  `wizard.step.completed`. The typed-exception hint
  (`"open Activity → Statements → Activity Download (CSV) and re-export"`)
  reaches the user inline, and FirstImportStep omits the PayPal section
  because `wizard_progress.data['paypal_import_run_id']` is absent.

  The Round 1 fix (ensure-then-preview order) is preserved — it closes a
  separate real design defect that would have produced an all-error cache
  on fresh users even with a valid Activity Download CSV (resolver returns
  UnknownAccount when the synthetic PayPal account doesn't yet exist).

verification: |
  Reproduction test (red on broken code, green after fix):
    Modules/Onboarding/tests/Feature/ConnectPaypalStepFatalParseUploadTest.php
  Asserts that uploading a BRR-shaped CSV:
   - Sets `$uploadError` containing "Activity Download"
   - Does NOT dispatch `wizard.step.completed`
   - Does NOT stash `paypal_import_run_id` in `wizard_progress.data`

  Test runs:
  - `vendor/bin/pest --filter ConnectPaypal` → 6 passed, 46 assertions
  - `vendor/bin/pest --testsuite Feature --filter 'Paypal|paypal'` → 40 passed, 476 assertions, no regressions
  - `vendor/bin/pest --testsuite Feature --filter 'Onboarding|Import|Ingestion'` → 269 passed, 2 skipped, no regressions
  - `vendor/bin/pint Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectPaypalStep.php Modules/Onboarding/tests/Feature/ConnectPaypalStepFatalParseUploadTest.php` → passed
  - `vendor/bin/phpstan analyse --memory-limit=1G` on changed files → No errors

  Pre-existing failures in `Modules/Onboarding/tests/Unit/WizardProgressInitializerTest.php`
  (`expect(7)->toBe(6)` — stale step-count expectation) also fail on `main` without
  any of this debug session's changes; unrelated to this fix.

files_changed:
  - Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectPaypalStep.php (Round 1 + Round 2)
  - Modules/Onboarding/tests/Feature/ConnectPaypalStepCacheContentsTest.php (Round 1, new)
  - Modules/Onboarding/tests/Feature/ConnectPaypalStepConsolidatedPreviewTest.php (Round 1, new)
  - Modules/Onboarding/tests/Feature/ConnectPaypalStepReuseExistingAccountTest.php (Round 1, new)
  - Modules/Onboarding/tests/Feature/ConnectPaypalStepFatalParseUploadTest.php (Round 2, new — reproduces live symptom)

# Debug session: paypal-zero-rows-stash

## Symptoms

- **Expected:** After uploading a valid PayPal Activity Download CSV on `ConnectPaypalStep`, the FirstImportStep page should render a PayPal section with the same Type-chip rows as the standalone `/imports/upload` preview shows for the same file.
- **Actual:** FirstImportStep renders the PayPal section header as `"FROM PAYPAL · 0 ROWS · ✓ READY"` with empty placeholder rows. Status is `READY` (so an `import_runs` row exists), but `totalRows = 0` — the preview cache for the stashed run id contains no canonical rows.
- **Visible context (live screenshot 2026-05-27):**
  - The PayPal section is rendered between the ASN bank section ("Load more (224 remaining)") and the ICS card section ("FROM YOUR ICS CARD STATEMENTS · 137 ROWS · ✓ READY").
  - Two accounts detected ("STARTING BALANCES · 2 ACCOUNTS DETECTED") — one for ASN bank, one for ICS card. PayPal having no starting-balance candidate is INTENTIONAL: `PaypalCsvStartingBalanceDetector::detect()` returns `[]` by design.
- **Reproduction (Round 2 — what actually triggered the user's bug):**
  1. Fresh wizard run, drop a PayPal **Balance Reconciliation Report** CSV on `ConnectPaypalStep` (looks superficially identical to the Activity Download — both `.csv`, comma-delimited, UTF-8 with BOM)
  2. Step submits successfully (no inline error visible because `$uploadError` was not set), wizard advances
  3. Advance through `ConnectCardStep` and `ConnectEmailStep`
  4. Land on `FirstImportStep` — PayPal section shows `READY · 0 ROWS`

## Eliminated (from Round 1)

- hypothesis: ConnectPaypalStep stashes the wrong id (a second ImportRun is created)
  evidence: RunImport.runFromUpload is idempotent on (user_id, sha256); same file twice REUSES the row. Stashed id IS the correct id.
  timestamp: 2026-05-27T14:25:00Z

- hypothesis: BuildConsolidatedPreviewQuery's stale-window or status filter is dropping the run
  evidence: Status `READY` means the section saw cached rows. The filter let the run through.
  timestamp: 2026-05-27T14:27:00Z

- hypothesis: Rows are getting status `'duplicate'`
  evidence: Fresh wizard run → empty `transactions` table → FingerprintStage returns `newRow()` for every fingerprint. Duplicate is impossible.
  timestamp: 2026-05-27T14:27:00Z

- hypothesis: PayPal NOT contributing to starting-balance candidates is a related symptom
  evidence: `PaypalCsvStartingBalanceDetector::detect()` returns `[]` by design (per-event Saldo resets to zero, so no usable opening-balance signal).
  timestamp: 2026-05-27T14:45:00Z

- hypothesis: File-layer idempotency short-circuit returns rows=[] so cache is never populated
  evidence: Short-circuit only fires when status='confirmed'. Fresh wizard run has no prior confirmed run for the SHA. Additionally if the short-circuit fired, the surviveBoundaryFilters would drop the confirmed run id and the PayPal section would not render at all.
  timestamp: 2026-05-27T14:50:00Z

## Eliminated (from Round 2)

- hypothesis: ROUND 1's all-`'error'`-rows-from-UnknownAccount is the live cause
  evidence: Probe B shows the synthetic PayPal account already exists at user_id=1 (the Round 1 fix is working). Probe E shows the cache contains exactly ONE error row at rowIndex 0 with a message about the BRR export type — that's the pipeline's file-level Throwable catch, NOT the per-row UnknownAccount path (which would produce N error rows, one per data row).
  timestamp: 2026-05-27T16:27:00Z

- hypothesis: PreviewCache file backend behaves differently from the array test backend
  evidence: Probe E proves the file backend serializes and persists correctly — the file at `ad/08/...` contains a fully-deserialisable `ImportPreviewResult`. The cache backend is not the issue.
  timestamp: 2026-05-27T16:27:00Z

- hypothesis: AccountResolver scope problem (user_id, IBAN, soft-delete, etc.)
  evidence: Probe B shows the account exists and matches `user_id = 1` AND `iban = 'PAYPAL'`. The IBAN literal `'PAYPAL'` is hard-coded in three places that all match: `PaypalCsvAdapter::PAYPAL_OWN_IBAN`, `EnsurePaypalAccountAction::PAYPAL_OWN_IBAN`, and `PreviewWizard::PAYPAL_OWN_IBAN`. Not the live failure mode.
  timestamp: 2026-05-27T16:27:30Z

- hypothesis: A different live submit handler exists (queue worker, alt code path)
  evidence: Probe A's import_run row id=2 was created by `ConnectPaypalStep::submit()` (no queue dispatch involved; the submit is synchronous Livewire). Probe C confirms `wizard_progress.data['paypal_import_run_id']` = 2 was written by the same submit handler.
  timestamp: 2026-05-27T16:28:00Z

## Evidence (Round 1, preserved)

- timestamp: 2026-05-27T14:25:00Z
  checked: `Modules/Import/Public/Contracts/RunsImports.php` and `Modules/Import/Public/Actions/RunImport.php::runFromUpload()`
  found: `runFromUpload` is **idempotent on (user_id, sha256)** — same file twice REUSES the existing ImportRun row. Returned `importRunId` is the same on both calls.
  implication: Stashed id is correct. Bug must lie in cache content.

- timestamp: 2026-05-27T14:26:00Z
  checked: `Modules/Import/Public/Actions/RunImport.php::runFromUpload()` line 129
  found: After the pipeline runs, `$this->cache->put($importRun->id, ...)` writes UNCONDITIONALLY. Second call OVERWRITES first call's cache.
  implication: If both calls succeed, the cache reflects the second pass.

- timestamp: 2026-05-27T14:27:00Z
  checked: `Modules/Import/Public/Services/BuildConsolidatedPreviewQuery.php::buildSection()` lines 209-243
  found: `totalRows` counts only `'new'`/`'enriched'` rows; status `'ready'` only requires `$allRows` to be non-empty. `READY + 0 rows` is mathematically possible only when every row is `'error'` (or `'duplicate'`, but fresh-DB rules that out).
  implication: Cached rows must all be `'error'`.

- timestamp: 2026-05-27T14:28:00Z
  checked: `Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectPaypalStep.php::submit()` lines 130-172 (pre-Round-1)
  found: First `runFromUpload` ran BEFORE `EnsurePaypalAccountAction`. Re-preview was guarded by `if ($created)` + `file_exists(...)` + `try/catch(Throwable) { $logger->warning(...) }`.
  implication: Three-layer silent-failure fallback could leave the all-error cache in place. Round 1 closed this — but it was not the live bug.

## Evidence (Round 2, the real story)

- timestamp: 2026-05-27T16:27:00Z
  checked: Actual on-disk PayPal CSV at the run's raw_file_path
  found: Header row is `"RH","Naam rapport","Status rapport",...` — the `RH`/`RD`/`RF` token pattern of a PayPal Balance Reconciliation Report, NOT an Activity Download. Footer says total records = 0.
  implication: **The user uploaded the wrong PayPal export type.** The bug is downstream of that mistake: the wizard should have surfaced the error inline, not advanced silently.

- timestamp: 2026-05-27T16:27:30Z
  checked: Actual cache file `ad/08/ad08b0f1...` content
  found: Exactly one PreviewRowDto with `status:'error'`, `rowIndex:0`, error: `"This looks like a PayPal Balance Reconciliation Report CSV, not the Activity Download. In the PayPal portal, open Activity → Statements → Activity Download (CSV) and re-export."`
  implication: `ImportPipeline::preview()` correctly converted the typed exception into a single-error-row preview. The pipeline contract is sound. The bug is that ConnectPaypalStep treats this as success.

- timestamp: 2026-05-27T16:28:00Z
  checked: `Modules/Import/Internal/Pipeline/ImportPipeline.php` lines 212-238
  found: The file-level catch wraps the entire parse loop. Any Throwable from `parse->run(...)` (including `UnsupportedPaypalCsvShapeException` raised by `HeaderSniffer::sniffPaypalCsv()` at line 184) is converted to a single ERROR `PreviewRowDto` at `rowIndex:0` with the exception message. `runFromUpload` then returns NORMALLY with `rows: [1 error row]`.
  implication: `ConnectPaypalStep::submit()`'s `catch (Throwable $e)` never fires for parse-time failures. The stash + dispatch run unconditionally.

- timestamp: 2026-05-27T16:35:00Z
  checked: `Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectPaypalStep.php::submit()` (post-Round-1, pre-Round-2)
  found: After `runFromUpload` returns, the step writes `paypal_import_run_id` into `wizard_progress.data` and dispatches `wizard.step.completed` with NO inspection of `$result->rows` for an all-error condition.
  implication: Exact site of the live bug. Fix landed here in Round 2.

- timestamp: 2026-05-27T16:45:00Z
  checked: Post-Round-2 verification — `vendor/bin/pest` + Pint + Larastan
  found: New `ConnectPaypalStepFatalParseUploadTest` reproduces the live symptom on the broken code (red) and passes on the fixed code (green). Broader Onboarding/Import/Ingestion feature sweep: 269 passed, 2 skipped, 0 regressions. Pint clean, Larastan L10 (with `--memory-limit=1G`) clean on changed files.
  implication: Fix is correct, isolated, and locked by a regression test that reproduces the live failure shape directly.
