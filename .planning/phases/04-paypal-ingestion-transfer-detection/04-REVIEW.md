---
phase: 04-paypal-ingestion-transfer-detection
reviewed: 2026-05-16T00:00:00Z
depth: standard
files_reviewed: 53
files_reviewed_list:
  - Modules/Import/Internal/Http/Livewire/PreviewWizard.php
  - Modules/Import/Internal/Http/Livewire/UploadWizard.php
  - Modules/Import/Internal/Pipeline/ImportPipeline.php
  - Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php
  - Modules/Import/Public/Actions/ConfirmImport.php
  - Modules/Import/Public/Events/TransactionImported.php
  - Modules/Import/Public/Services/SourceRefRanker.php
  - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
  - Modules/Import/Resources/views/livewire/upload-wizard.blade.php
  - Modules/Import/tests/Feature/ClassifyTransactionTypeTest.php
  - Modules/Import/tests/Feature/PaypalCsvImportTest.php
  - Modules/Import/tests/Feature/UploadWizardPaypalTest.php
  - Modules/Import/tests/Feature/UploadWizardTest.php
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php
  - Modules/Ingestion/Providers/IngestionServiceProvider.php
  - Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php
  - Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php
  - Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php
  - Modules/Ingestion/Public/Services/HeaderSniffer.php
  - Modules/Ingestion/tests/Feature/HeaderSnifferPaypalTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalAmountParserTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalDateParserTest.php
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalTransactionRollupTest.php
  - Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php
  - Modules/Ledger/Models/Transaction.php
  - Modules/Ledger/Public/Actions/RecordTransactions.php
  - Modules/Ledger/Public/Contracts/RecordsTransactions.php
  - Modules/Ledger/Public/Dto/CanonicalTransaction.php
  - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
  - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php
  - Modules/Ledger/tests/Feature/DashboardIncomeTest.php
  - Modules/Ledger/tests/Feature/PairTransactionSchemaTest.php
  - Modules/Ledger/tests/Feature/RecordTransactionsDispatchesEventTest.php
  - Modules/Ledger/tests/Feature/RecordTransactionsTest.php
  - Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php
  - Modules/Transfers/Internal/Listeners/PairTransferCandidates.php
  - Modules/Transfers/Providers/TransfersServiceProvider.php
  - Modules/Transfers/composer.json
  - Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php
  - Modules/Transfers/tests/Pest.php
  - Modules/Transfers/tests/TestCase.php
  - bootstrap/providers.php
  - composer.json
  - scripts/anonymize_paypal_csv.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/IdempotencyContractTest.php
  - tests/Pest.php
  - tests/TestCase.php
findings:
  critical: 0
  warning: 7
  info: 8
  total: 15
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-05-16
**Depth:** standard
**Files Reviewed:** 53
**Status:** issues_found

## Summary

Phase 4 lands the PayPal CSV ingestion path, the deterministic pair-detection
listener, and the dashboard/reclassify D-77/D-78 follow-ups. The implementation
is generally well-structured (constructor DI throughout, scoped queries, typed
exceptions, immutable DTOs) and the test coverage is broad. However, the review
surfaced two notable bug-class defects:

1. **Reconciliation gate is mathematically tautological** in `PaypalCsvAdapter`
   — `reconciliationGap` is hardcoded to `0` by construction, so the soft-warning
   panel in the preview wizard can never fire for PayPal imports. This silently
   defeats the documented walker-correctness sentinel and the reconciliation
   guidance the Blade view promises the user.
2. **`ConfirmImport` re-confirm returns the wrong field as `duplicates`** —
   when a user double-clicks Confirm or refreshes the post-confirm page, the
   action returns `$importRun->inserted_count` as the `duplicates` value
   instead of `$importRun->duplicate_count`. The prior run's real duplicate
   total is dropped on the floor; the inserted-row count is silently relabelled.

Beyond these, the dominant issue category is **codebase-vs-GSD agnosticism**:
production source files in this phase contain a high volume of references to
`RESEARCH.md`, `WAVE-0-FINDINGS.md`, `CONTEXT.md`, `Wave 0/1/2/3`, `Phase 4 SC #`,
`D-60`..`D-78`, `Pitfall N`, `T-04-W2-NN`, and `UI-SPEC` — process artefacts
that the CLAUDE.md project guidance explicitly forbids in code. None of these
are functional defects, but every one of them is a violation of an enforced
project rule that needs to be cleaned out before this phase ships.

Smaller defects: the `Transfers` module sits outside the architectural
boundary arch-test, the pair-detection booked_at window is asymmetric in
practice (3-day-inclusive on one side, exclusive on the other due to time-of-day
clipping), and the pair window is broader than D-73 spec when looking only at
calendar days, plus a few minor migration / Pint / clarity items below.

## Warnings

### WR-01: Reconciliation gate is tautological — gap is always 0 in PayPal adapter

**File:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php:189-192`
**Issue:**
The reconciliation-status calculation in `buildStatementMetadata()` reduces to
the identity `0` by construction:

```php
$closingMinor = $netSumMinor;
$openingMinor = 0;
$reconciliationGap = ($closingMinor - $openingMinor) - $netSumMinor;
//                 = ($netSumMinor - 0) - $netSumMinor
//                 = 0   ALWAYS
$reconciliationStatus = $reconciliationGap === 0 ? 'ok' : 'mismatch';
```

The docblock at lines 49-54 and 163-171 claims a non-zero gap "would indicate
either the walker dropped a row OR PayPal shipped an unexpected event-type
shape." That sentinel never fires — no input data, malformed or otherwise,
can produce a non-zero gap because the gap is derived purely from
`$netSumMinor` cancelling itself out.

The preview-wizard Blade (`preview-wizard.blade.php:80-89`) renders a
reconciliation-warning panel keyed on
`extras.reconciliationStatus === 'mismatch'`. That panel is dead code for
every PayPal import — the integrity check it advertises does not exist.

The unit test `PaypalCsvAdapterTest.php:113-123` asserts
`reconciliationStatus = 'ok'` for the fixture, but no test exercises a
genuine `'mismatch'` path, hiding the defect.

**Fix:**
Either (a) implement a real opening/closing reconciliation by reading the
`Saldo` column on the first and last rows (the empirical PayPal export ships
running balances per row), or (b) drop the reconciliation gate from the
PayPal adapter entirely and remove the Blade branch — fake reassurance is
worse than no panel. Option (a) sketch:

```php
// Use the first row's Saldo (opening balance reading) and the last row's
// Saldo (closing balance reading) as the gate.
$openingMinor = $this->amounts->parseMinor($firstRow['Saldo'] ?? '0,00')
    - $this->amounts->parseMinor($firstRow['Netto'] ?? '0,00');
$closingMinor = $this->amounts->parseMinor($lastRow['Saldo'] ?? '0,00');
$reconciliationGap = ($closingMinor - $openingMinor) - $netSumMinor;
```

Add a test that constructs an input where the walker drops a child row and
asserts `reconciliationStatus = 'mismatch'`.

---

### WR-02: `ConfirmImport` re-confirm returns inserted_count as duplicates field

**File:** `Modules/Import/Public/Actions/ConfirmImport.php:56-64`
**Issue:**
On a refresh / back-button re-confirm against an already-confirmed
`ImportRun`, the action returns the wrong column as the duplicate count:

```php
if ($importRun->status === 'confirmed') {
    return new ImportConfirmResult(
        importRunId: $importRunId,
        inserted: 0,
        duplicates: $importRun->inserted_count,   // <- wrong field
        enriched: $importRun->enriched_count,
        errors: 0,
    );
}
```

The `import_runs` table carries both `inserted_count` (the rows actually
inserted on the original confirm) AND `duplicate_count` (rows the first
confirm detected as duplicates). On re-confirm, the action picks
`inserted_count` and exposes it as the result's `duplicates` value, which
will mislead any UI / results-page surface that reads back the action's
return tuple. The original `duplicate_count` is silently dropped.

If the intent is "everything that was inserted is now a duplicate on re-run,"
that is at least debatable semantics; either way, it conflicts with the
preserved `inserted: 0` (which suggests "no new rows now") on the same
return. Pick one of:
  - `duplicates: $importRun->duplicate_count` (the real, persisted count)
  - `duplicates: $importRun->inserted_count + $importRun->duplicate_count`
    (combined view if you really want to surface "nothing was new this time")
**Fix:**
```php
duplicates: $importRun->duplicate_count,
```
or document the intent inside the result construction so the next reader
doesn't have to reconstruct it from git.

---

### WR-03: Pair-detection booked_at window over-broadens beyond the documented D-73 ±3 days

**File:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php:112-134`
**Issue:**
The listener uses `subDays(3)`/`addDays(3)` against a `booked_at` value that
already carries a time-of-day component (the Phase 3 adapters book at
`12:00:00` and the PayPal `startOfDay` parser yields `00:00:00`). The
resulting `whereBetween` window straddles seven calendar days in some
cases:

- For an ASN row with `booked_at = 2026-05-15 12:00:00`, the window is
  `2026-05-12 12:00:00` … `2026-05-18 12:00:00` — but a same-`booked_at-12h`
  partner landing at `2026-05-12 00:00:00` (e.g., from a PayPal startOfDay
  parse) falls OUTSIDE the window, while a counterpart at `2026-05-18 23:59:00`
  could be inside if it ever existed.

This produces two asymmetric off-by-one risks:

1. A genuine half-pair where the partner row's date is 3 calendar days
   away but the time-of-day pushes it past `12:00:00` will be missed.
2. The pair window can stretch up to 7 calendar days in edge cases, wider
   than the documented "±3 days" guarantee.

For the empirical Phase 4 flows this is mostly latent (the test fixtures
all use `12:00:00` or `startOfDay`), but the contract D-73 declares ("±3
days") is not faithfully enforced.

**Fix:**
Normalize to date-only or extend the window symmetrically:

```php
$windowStart = $tx->booked_at->copy()->startOfDay()->subDays(self::WINDOW_DAYS);
$windowEnd = $tx->booked_at->copy()->endOfDay()->addDays(self::WINDOW_DAYS);
```

Add a test that constructs a partner row 3 calendar days away with a
later time-of-day to pin the symmetric window contract.

---

### WR-04: GSD-process leakage in production source files (project rule violation)

**Files (representative — full sweep needed):**

- `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php:14,23,38,53,63,64,132`
- `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php:13,26,34,54,142,152`
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php:20,25-26,45,49,116`
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php:47,50,54,193`
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php:13,20`
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php:18,21`
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php:15`
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php:20`
- `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php:13,27,32,39,82,84`
- `Modules/Import/Public/Services/SourceRefRanker.php:34,39`
- `Modules/Import/Internal/Http/Livewire/PreviewWizard.php:42`
- `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php:26,90`
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php:76,78,150`
- `Modules/Ledger/tests/Feature/DashboardIncomeTest.php` (heading + many)
- `Modules/Ledger/tests/Feature/PairTransactionSchemaTest.php`
- `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php`
- `Modules/Ingestion/tests/Unit/Adapters/Paypal/*` (multiple)
- `Modules/Import/tests/Feature/PaypalCsvImportTest.php`

**Issue:**
The project rule states "the codebase must stay GSD-agnostic — flag any
`.planning/`, `PLAN.md`, `RESEARCH.md`, `SUMMARY.md`, `VERIFICATION.md`
references in code or PHPDocs." Production code in this phase is heavily
laced with process tokens that map only to the planning artefacts:

- `RESEARCH.md` reference inline (`ClassifyTransactionType.php:23`):
  "verbatim from RESEARCH.md Example 3"
- `04-WAVE-0-FINDINGS.md` references (`PaypalCsvEventTypeMap.php:32,84`)
- `CONTEXT.md` reference (`PaypalCsvEventTypeMap.php:39`)
- Decision-ID references: `D-60`, `D-61`, `D-62`, `D-63`, `D-64`, `D-65`,
  `D-66`, `D-73`, `D-74`, `D-75`, `D-76`, `D-77`, `D-78` (dozens of
  occurrences, see grep output)
- `Pitfall 1/2/3/5` references (multiple)
- `Wave 0/1/2/3` references (dozens, e.g. "Wave 0 fixture", "Wave 2", "Wave 3")
- `Phase 4 SC #`, `Phase 4 SC#3`, `Phase 4 SC #4` (Success Criteria are
  internal planning concepts)
- `T-04-W2-01`, `T-04-W2-02` (planning task identifiers)
- `UI-SPEC-locked copy` (`PreviewWizard.php:42`)
- `04-WAVE-0-FINDINGS.md` (multiple — direct file reference)

The rule also forbids "historical / change-motivation" comments. The
`PaypalCsvEventTypeMap.php:80-85` block ("Both observed parent forms in
the Wave 0 fixture map to 'expense'. Wave 0 surfaced no refund / withdrawal /
transfer rows; their NL strings are deferred until empirically observed
(see 04-WAVE-0-FINDINGS.md ...)") is exactly the historical motivation
pattern called out as forbidden.

**Fix:**
For every production source file in the list above, rewrite PHPDocs and
inline comments to describe what the code does today, without reference
to planning artefacts:

- Replace "per D-77" / "Pitfall 3" with the substantive rule (e.g.,
  "subtractive income rule: positive amount → income unless transfer/refund/fee").
- Drop `Wave 0/1/2/3`, `Phase 4`, `SC #N`, `T-04-W2-NN`, `UI-SPEC`
  tokens — they have no meaning outside the planning corpus.
- Drop file references to `RESEARCH.md`, `CONTEXT.md`,
  `04-WAVE-0-FINDINGS.md`, `PATTERNS.md`.
- Reword historical commentary ("Wave 0 surfaced no X; deferred until
  empirically observed") to current-state ("Only `parent` event types map
  to `expense`; new types raise `UnknownPaypalEventTypeException`").

Tests (`Modules/.../tests/`) are subject to the same rule per CLAUDE.md
("test contracts may reference `.planning/` only as test-data inputs,
not in comments"). The `tests/Contracts/IdempotencyContractTest.php`
"Phase 4 Wave 0 baseline" comment and the dataset name `phase-4` are
borderline; `tests/Contracts/BoundaryArchTest.php` and the dashboard /
transfer tests are clean of file refs but use phase/wave/SC tokens in
narrative comments.

---

### WR-05: Anonymous migration uses `app()` global helper — DI-only rule states the bypass should not be expressed as `app(...)`

**File:** `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php:78,89`
**Issue:**
The migration's PHPDoc justifies calling `app(DatabaseManager::class)`
inside `schema()` and `db()` private helpers because "Anonymous migrations
are instantiated by Laravel's migrator with no constructor arguments, so
the schema builder is resolved from the container at the migration boundary.
This is the standing Laravel-migration exception to the DI-only rule."

The project's DI-only rule does not declare such an exception. Other
migrations on `main` likely use `Schema::table(...)` (also forbidden) or
`$this->schema()` from `Illuminate\Database\Migrations\Migration` (which
Laravel resolves through its own service container, no `app()` call
needed).

Calling `app(DatabaseManager::class)` twice (one helper for `db()`, one
for `schema()`) compounds the issue. The two helpers also re-resolve the
container on every call.

**Fix:**
Use the `\Illuminate\Support\Facades\Schema` facade IF the codebase
allows that, OR — to honour the strict no-facade/no-helper rule —
extract a `class extends Migration` (not anonymous) that DI-receives a
`DatabaseManager`. Failing that, accept the standard
`use Illuminate\Support\Facades\Schema; Schema::table(...)` pattern but
flag it consistently as the documented migration exception (and audit
the rest of the codebase for the same precedent so the rule statement
itself stays accurate).

Minimum fix without restructuring: resolve once in a memoized property
to avoid double container lookups:

```php
private ?DatabaseManager $resolvedDb = null;

private function db(): DatabaseManager
{
    return $this->resolvedDb ??= app(DatabaseManager::class);
}

private function schema(): Builder
{
    return $this->db()->connection($this->getConnection())->getSchemaBuilder();
}
```

---

### WR-06: `Modules\Transfers\Internal` is not covered by the boundary arch test

**File:** `tests/Contracts/BoundaryArchTest.php:1-50`
**Issue:**
The arch test enforces "`Modules\X\Internal` is only used inside
`Modules\X`" for `Ledger`, `Core`, `Ingestion`, `Import`, and
`Categorization`, but the brand-new `Transfers` module is NOT in the
list. Nothing in the test suite forbids a future change from importing
`Modules\Transfers\Internal\Listeners\PairTransferCandidates` directly
from another module, violating the module-boundary discipline that
CLAUDE.md describes as enforced.

The boot-time listener registration uses the FQN
`Modules\Transfers\Internal\Listeners\PairTransferCandidates::class` from
the `TransfersServiceProvider` (also in `Modules\Transfers`, so this is
in-bounds), but the arch test should still pin it.

**Fix:**
Add one entry to `BoundaryArchTest.php`:

```php
arch('Modules\\Transfers\\Internal is only used inside Modules\\Transfers')
    ->expect('Modules\\Transfers\\Internal')
    ->toOnlyBeUsedIn('Modules\\Transfers');
```

---

### WR-07: PaypalTransactionRollup throws unchecked InvalidAmountException for malformed child amount; surfaces as PreviewRow error rather than typed user message

**File:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php:201-227`
**Issue:**
Inside `buildDto()`, `$this->amounts->parseMinor($childGross)` is called
for every `child-fx` sibling without try/catch. If a malformed Bruto cell
ever lands on a child row (e.g. a future PayPal export where the FX leg
uses `1,234.56`), the parser throws `InvalidAmountException` from inside
the rollup walker — propagating up through `PaypalCsvAdapter::parse()`'s
Generator and being caught only by the outer `ImportPipeline::preview`
catch-all that produces a single "ERROR row covering the whole file" at
`rowIndex: 0`. The user loses the per-row context and the wizard has no
way to tell them which child row was malformed.

The parent's Gross has the same issue (line 188) — one bad parent kills
the entire 41-row preview.

This is also a quality problem: the rollup tests
(`PaypalTransactionRollupTest.php`) only feed well-formed rows; there is
no negative-input coverage for parent or child amounts.

**Fix:**
Either wrap the per-row parse in try/catch and surface a per-row PreviewRowDto
error (preferred), or document loudly that any malformed row drops the
entire file. The pipeline's outer catch already provides graceful failure
at the file level, but per-row failure is the better UX:

```php
try {
    $childAmountMinor = $this->amounts->parseMinor($childGross);
} catch (InvalidAmountException $e) {
    // Skip this child + count it for audit; the parent still produces
    // a canonical DTO without the FX pair filled in.
    $this->orphanChildCount++;
    continue;
}
```

Add a test feeding a malformed child Bruto and asserting the parent DTO
still emits.

## Info

### IN-01: `nameAccount()` does not assert the supplied IBAN was in the unknown list

**File:** `Modules/Import/Internal/Http/Livewire/PreviewWizard.php:82-123`
**Issue:**
The Livewire `nameAccount(string $iban, string $name, ...)` action
accepts the IBAN as a method argument with no check that it actually
appears in `$preview->accountsToName`. A crafted wire request could
attempt to name an arbitrary IBAN for the current user. The downstream
`AccountNamer` validates the structural IBAN shape and the name, and
all writes are scoped to `$user`, so this is bounded — but the wizard's
intent ("only name IBANs we surfaced as unknown for this import run")
isn't enforced at this seam.
**Fix:**
Cross-check `$iban` against the cached preview's `accountsToName` list
before invoking the namer, or accept the bounded risk and document it
explicitly.

---

### IN-02: `PaypalCsvEventTypeMap::transactionType()` reuses one exception type for two semantically-different errors

**File:** `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php:91-111`
**Issue:**
Both `classify()` and `transactionType()` throw
`UnknownPaypalEventTypeException`, but the failure modes differ:
- `classify()` fires when an event type is entirely unmapped (the row
  must be rejected).
- `transactionType()` fires when an event type exists in MAP as a parent
  but has no `TRANSACTION_TYPE` row — a code-internal inconsistency, not
  a user-data issue.

`ClassifyTransactionType.php:117-128` silently swallows the
`transactionType()` exception by catching `Throwable`, treating the
inconsistency as "fall through to default." A typed split (e.g.,
`MissingTransactionTypeMapException extends UnknownPaypalEventTypeException`)
would let the stage tell the difference between "PayPal shipped a new
event type" and "we forgot to populate TRANSACTION_TYPE for a known
parent" — the second case should ideally surface as a hard error during
development.
**Fix:**
Split the exception type, or document explicitly that the catch in
`ClassifyTransactionType` is intentional swallowing.

---

### IN-03: `ClassifyTransactionType` catches `Throwable` (broader than needed)

**File:** `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php:117-128`
**Issue:**
The catch block uses `catch (Throwable)`. The only documented thrower
upstream is `UnknownPaypalEventTypeException`. Catching `Throwable`
silently swallows unrelated failures (OOM, DI errors, type-cast issues)
that should propagate.
**Fix:**
Narrow the catch to the specific exception type:

```php
} catch (UnknownPaypalEventTypeException) {
    // ...
}
```

---

### IN-04: Empty PayPal date string raises `InvalidAmountException` (wrong exception type)

**File:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php:33-35`
**Issue:**
An empty input throws `InvalidAmountException` from a date parser. The
type is misleading; readers will struggle to grep for "date" failures
because they surface as amount failures.

```php
if ($trimmed === '') {
    throw new InvalidAmountException('Empty PayPal date string.');
}
```

The same misuse appears at lines 43, 61, 73.
**Fix:**
Introduce `InvalidDateException` (or reuse a generic
`InvalidSourceCellException`) and use it consistently in the date parser.

---

### IN-05: `PaypalTransactionRollup::buildDto()` uses assignment-as-argument anti-pattern

**File:** `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php:256`
**Issue:**
```php
sourceRef: $parentTxnId = $this->columns->value('transactionId', $language, $parentRow),
```
Assigning inside a named-argument expression hides intent and surprises
readers. `$parentTxnId` is not used afterwards in the function body,
making the assignment dead. Pint will tolerate this but a careful reader
won't.
**Fix:**
```php
sourceRef: $this->columns->value('transactionId', $language, $parentRow),
```

---

### IN-06: Listener pair lookup uses Eloquent re-load even though raw row already returned the id

**File:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php:140-168`
**Issue:**
The listener already has `$partnerRow->id` from the raw query builder
read (line 139), but then loads the partner row again through Eloquent
to use the model save path. This is a quality concern (an extra round
trip per pair) rather than a correctness one; in tight pair-detection
loops over a multi-month import this adds up.
**Fix:**
Use a raw `update()` on the partner row's id with `pair_transaction_id`:

```php
$connection->table('transactions')
    ->where('user_id', $user->id)
    ->where('id', $partnerId)
    ->update(['pair_transaction_id' => $tx->id, 'updated_at' => $now]);
```

This is a perf note — flagged as Info because the brief excludes perf
from v1 review scope, BUT correctness of the eloquent re-fetch is fine.

---

### IN-07: `scripts/anonymize_paypal_csv.php` reads file into memory twice (once via file_get_contents, once via tmpfile)

**File:** `scripts/anonymize_paypal_csv.php:80-99`
**Issue:**
The script does:
1. `file_get_contents($inputPath)` (whole file in `$raw`)
2. `tmpfile()` + `fwrite($tmpIn, $raw)` (whole file in temp buffer)
3. `fgetcsv($tmpIn, ...)` loop, accumulating into `$rows`

For a multi-MB monthly PayPal export this is fine, but is wasteful and
non-streaming. Mitigations are simple; flagged here for cleanliness, not
defectiveness.
**Fix:**
Stream the file with `fopen()` directly, BOM-strip the first line
manually if present, and feed `fgetcsv` directly.

---

### IN-08: `PaypalCsvEventTypeMap::TRANSACTION_TYPE` only covers `parent` action types, but the contract is implicit

**File:** `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php:79-90`
**Issue:**
A subtle invariant: only event types whose `MAP[lang][type] === 'parent'`
should appear in `TRANSACTION_TYPE`. If a future contributor adds a row
to `TRANSACTION_TYPE` for a child-classified event by mistake (e.g.,
mapping `Algemene valutaomrekening` to `'expense'`), the stage will
silently retype FX child rows. Today's call site only invokes
`transactionType()` from `ClassifyTransactionType` for already-parent
event-types coming from `rawPayload.events[0]`, so the practical risk
is low, but the invariant is unwritten.
**Fix:**
Add an `assertParentOnly()` test that iterates
`TRANSACTION_TYPE['nl']` keys and asserts each one is mapped as `parent`
in `MAP['nl']`.

---

_Reviewed: 2026-05-16_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
