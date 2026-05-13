---
phase: 02-asn-statement-coverage-camt-053-mt940
plan: 02
subsystem: ledger
tags:
  - wave-1
  - foundation
  - fingerprint-v3
  - migration
  - di-only
  - schema
  - dto
dependency_graph:
  requires:
    - 02-01-PLAN
  provides:
    - "`FingerprintComposer::NORMALIZATION_VERSION === 3` — the v3 tuple drops `source_ref` and widens with `booked_at` (second-resolution) so CSV and CAMT entries for the same logical transaction hash identically; downstream Wave 2 adapters (02-03, 02-04) hash through this surface verbatim"
    - "`diederik:rederive-fingerprints` artisan command at `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` — collision pre-check + dry-run/--confirm flags + transactional apply; registered behind `runningInConsole()` and statically forbidden from any Http/Routes namespace by a BoundaryArchTest rule"
    - "`transactions.enriched_from` — nullable JSON column (after `source_ref`); Eloquent cast `AsArrayObject`; consumed by Plan 02-05's enrichment writer"
    - "`import_runs.enriched_count` — unsigned integer column defaulted to 0 (after `duplicate_count`); Eloquent cast `integer`; consumed by Plan 02-05's wizard results summary"
    - "Composite UNIQUE `transactions_fingerprint_uq` recreated over the v3 tuple `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)` — DB-layer enforcement now matches the SHA-256 hash"
    - "`Modules/Import/Public/Dto/FingerprintDisposition.php` (abstract) + `NewRowDisposition` / `DuplicateDisposition` / `EnrichedDisposition` final variants + `PendingEnrichment` DTO — the public surface Wave 3 enrichment pipeline consumes"
    - "Ledger TestCase `seedV2Row` / `seedTwoNonCollidingV2Rows` / `seedCollidingV2Rows` / `seedOneV3Row` helpers — reusable by Wave 2 + Wave 3 tests that need to assert fingerprint behaviour against pre-seeded rows"
  affects:
    - "Plans 02-03 (CSV adapter) + 02-04 (CAMT adapter) — both adapters now consume `FingerprintComposer::compose()` under the v3 tuple verbatim; same logical row in CSV + CAMT yields the same SHA-256"
    - "Plan 02-05 (enrichment writer + wizard) — reads `transactions.enriched_from` + `import_runs.enriched_count`; constructs `FingerprintDisposition::enriched(...)` + `PendingEnrichment(...)` directly without import-edge gymnastics"
tech_stack:
  added: []
  patterns:
    - "DI-only artisan command pattern at `Modules/<Module>/Internal/Console/<Name>Command.php` extending the InstallCommand precedent — `final class … extends Command` with `/** @var string */`-annotated `$signature` + `$description`, constructor DI of every collaborator + `parent::__construct()`, `handle(): int` returning `self::SUCCESS` / `self::FAILURE`, registered in the module's ServiceProvider behind `$this->app->runningInConsole()`"
    - "Two-layer guarding for console-only commands: runtime via `runningInConsole()` in the ServiceProvider boot and compile-time via a pest-plugin-arch `not->toBeUsedIn(...Http..., ...Routes...)` rule in `tests/Contracts/BoundaryArchTest.php`. The phase-2 dev loop has its own mirror under `Modules/Ledger/tests/Feature/` so a regression is caught by `vendor/bin/pest --group=phase-2 --bail` without depending on the global Contracts suite."
    - "Migration ordering for index-shape swaps where the new shape implies the rows MUST already match it: re-derive command runs FIRST (its collision pre-check is the gate), column adds in the middle (they don't depend on row content), UNIQUE-index swap LAST (operating on rows that are guaranteed to fit the new tuple)."
    - "Test-only schema mutation idiom: when a test must seed rows the production index forbids, `DROP INDEX IF EXISTS` inside the seed helper relies on RefreshDatabase to restore a clean schema for the next test. The dropped index is the trigger for the code-under-test (here, the collision pre-check), so the simulation must NOT recreate it before the command runs."
    - "Discriminated-DTO factory pattern carried over from `AccountResolution`: abstract base extends `Spatie\\LaravelData\\Data`, named-constructor methods (`newRow()` / `duplicate()` / `enriched(...)`) return one of three `final class … extends <BaseAbstract>` variants. `is*()` predicates on the base discriminate via `instanceof` so callers don't switch on string tags."
    - "`stdClass` row decoder pattern matching `UncategorizedTriageQuery` — raw `DatabaseManager->table()->get()` returns a `Collection<int, stdClass>`; PHPStan strict refuses naïve `(int) $row->prop` casts so the command pipes every column through private `toInt` / `toIntOrNull` / `toString` / `toStringOrNull` static helpers that test type with `is_numeric` / `is_string` first."
key_files:
  created:
    - Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php
    - Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php
    - Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php
    - Modules/Ledger/Database/Migrations/2026_05_13_010003_add_enriched_count_to_import_runs.php
    - Modules/Ledger/Database/Migrations/2026_05_13_010004_replace_transactions_fingerprint_unique_index.php
    - Modules/Ledger/tests/Unit/FingerprintComposerV3Test.php
    - Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php
    - Modules/Ledger/tests/Feature/RederiveFingerprintsHttpUnreachableArchTest.php
    - Modules/Ledger/tests/Feature/Phase2SchemaShapeTest.php
    - Modules/Import/Public/Dto/FingerprintDisposition.php
    - Modules/Import/Public/Dto/NewRowDisposition.php
    - Modules/Import/Public/Dto/DuplicateDisposition.php
    - Modules/Import/Public/Dto/EnrichedDisposition.php
    - Modules/Import/Public/Dto/PendingEnrichment.php
    - Modules/Import/tests/Unit/FingerprintDispositionTest.php
  modified:
    - Modules/Ledger/Public/Services/FingerprintComposer.php
    - Modules/Ledger/Providers/LedgerServiceProvider.php
    - Modules/Ledger/Models/Transaction.php
    - Modules/Ledger/Models/ImportRun.php
    - Modules/Ledger/tests/TestCase.php
    - tests/Contracts/BoundaryArchTest.php
decisions:
  - "Migration 010001 invokes the artisan command via `Container::getInstance()->make(Kernel::class)->call(...)` rather than the global `app(Kernel::class)` helper. Both work inside migration files (the BoundaryArchTest facade ban excludes `Modules/*/Database/Migrations/*` by phpstan-config convention), but the explicit container call reads more clearly and stays close to the DI-only stance the rest of the codebase enforces."
  - "Collision pre-check emits three separate `error()` / `error()` / `line()` calls instead of one `sprintf(\"…\\n%s\")` block. `expectsOutputToContain` matches per-line, and a single multi-line string would have meant the assertion 'output contains \"1 collision(s) detected\"' silently failed when the substring sat on a non-first line of the rendered output."
  - "`AsArrayObject` over `AsCollection` for `transactions.enriched_from`. The Wave 3 enrichment writer appends provenance entries on every enrichment, and `AsArrayObject` allows `$tx->enriched_from[] = […]; $tx->save();` mutation-in-place which is the cleanest read-modify-write for the eventual `ApplyEnrichments` action. The downside (no Collection methods) is irrelevant — the column is append-only."
  - "PHPDoc generic on `Transaction::$enriched_from` is `ArrayObject<int, array<string, mixed>>|null` rather than the tighter `array{format: string, ran_at: string, import_run_id: int, added: list<string>}` shape from the column's contract. Larastan level 10 strict happily accepts the inner-array `mixed` and the tighter shape would need PHPStan's `array{}` syntax to round-trip through Eloquent's `AsArrayObject` cast — which Larastan currently cannot infer back into the property accessor. The contract-precise shape lives in the column's contract comment in the migration + the SUMMARY here."
  - "`seedCollidingV2Rows` drops the v3 UNIQUE index and does NOT restore it. RefreshDatabase handles schema reset between tests, and restoring the index after inserting the colliding rows would itself fail. The dropped index IS the simulation — it's what would be the case if old v2 data existed before migration 010004 ran."
metrics:
  duration_minutes: 13
  completed_at: "2026-05-13T14:46:20Z"
  task_count: 3
  files_created: 15
  files_modified: 6
---

# Phase 02 Plan 02: Fingerprint v3 Foundation Summary

**FingerprintComposer bumped to v3 with `booked_at` + dropped `source_ref`, transactional `diederik:rederive-fingerprints` artisan command (collision pre-check + dry-run + console-only via runtime + compile-time guards), four schema migrations (re-derive → enriched_from JSON → enriched_count integer → v3-tuple UNIQUE swap), and the `FingerprintDisposition` + `PendingEnrichment` DTO surface for Wave 2/3 to consume.**

## Performance

- **Duration:** ~13 minutes
- **Started:** 2026-05-13T14:33:24Z
- **Completed:** 2026-05-13T14:46:20Z
- **Tasks:** 3 (all TDD-driven where applicable)
- **Files created:** 15
- **Files modified:** 6

## Accomplishments

- **v3 fingerprint algorithm shipped.** Tuple is `user_id | account_id | posted_at | booked_at | amount_minor | currency | counterparty_normalized`. CSV and CAMT.053 entries for the same real-world transaction now produce the same SHA-256 because `source_ref` is no longer part of the hash, and same-day-same-merchant-same-amount duplicates can no longer collide because `booked_at` carries second-resolution.
- **`diederik:rederive-fingerprints` artisan command lands.** Collision pre-check runs in pure CPU (no DB writes), aborts cleanly if any two rows would collide under the new tuple, and applies the update transactionally on the success path. Three operational modes: `--dry-run`, `--confirm`, or no flag (defaults to dry-run output).
- **Four schema migrations applied in order.** Re-derive runs FIRST (its collision pre-check is the gate); column adds in the middle; UNIQUE-index swap LAST. `php artisan migrate:status` shows all four as Ran; rollback + re-apply works idempotently.
- **Composite UNIQUE on `transactions` now matches the SHA-256 tuple.** The DB-layer enforcement and the application-layer hash agree. The SHA-256 `(user_id, fingerprint)` UNIQUE stays untouched.
- **Five new DTOs under `Modules/Import/Public/Dto/`.** `FingerprintDisposition` abstract base + three final variants + `PendingEnrichment` — the public surface Wave 3 consumes verbatim.
- **HTTP-unreachable command, twice over.** Runtime: `LedgerServiceProvider::boot()` only registers the command when `$this->app->runningInConsole()` is true. Compile-time: a pest-plugin-arch rule in `tests/Contracts/BoundaryArchTest.php` plus a phase-2-grouped mirror under `Modules/Ledger/tests/Feature/` statically forbid any `Http` or `Routes` namespace from importing the command class.
- **Stress-tested at production scale.** A 229-row scale test in `RederiveFingerprintsCommandTest` mirrors the size of the 3-month ASN CAMT corpus from Plan 02-01 and asserts zero collisions over a realistic distribution of dates + counterparties + amounts.
- **Zero regression.** Full Pest suite: 263 passed / 1 skipped / 6 863 assertions. Larastan level 10 strict: `[OK] No errors`. Pint: passed. The Phase-1 idempotency contract test (`IdempotencyContractTest`) still passes — re-importing the same file is still a no-op under the new v3 tuple.

## Task Commits

1. **Task 1: FingerprintComposer v3 + RederiveFingerprintsCommand + HTTP-unreachable arch test** — `15122c3` (feat)
2. **Task 2: Schema migrations (enriched_from, enriched_count, UNIQUE-index swap) + model casts** — `3260102` (feat)
3. **Task 3: FingerprintDisposition discriminated DTO + PendingEnrichment** — `5b8ea12` (feat)

Task 1 was TDD-driven (RED via `FingerprintComposerV3Test` failing on a v2 composer, then GREEN via the constant bump + tuple swap, then a second RED/GREEN cycle for the artisan command). Task 3 was TDD-driven (RED via `FingerprintDispositionTest` against missing classes, GREEN via the five DTOs). Task 2 was not TDD — schema migrations don't fit the RED/GREEN/REFACTOR cycle cleanly; instead the `Phase2SchemaShapeTest` runs alongside the suite to assert the columns + index shape every migration must produce.

## v3 Tuple Shape — Exact Diff

`Modules/Ledger/Public/Services/FingerprintComposer.php` `::compose()`:

```php
// Previous tuple (v2):
//   (string) ($tx->userId ?? 0)
//   (string) $tx->accountId
//   $tx->postedAt->toDateString()
//   (string) $tx->amountMinor
//   $tx->currency
//   $tx->counterpartyNormalized
//   $tx->sourceRef ?? ''

// Current tuple (v3):
//   (string) ($tx->userId ?? 0)
//   (string) $tx->accountId
//   $tx->postedAt->toDateString()
//   $tx->bookedAt->toDateTimeString()   <-- NEW
//   (string) $tx->amountMinor
//   $tx->currency
//   $tx->counterpartyNormalized
//                                       <-- source_ref removed
```

Verified by `FingerprintComposerV3Test`:
- `'exposes NORMALIZATION_VERSION as 3'` — the version stamp.
- `'produces an identical hash when only sourceRef differs'` — the CSV-↔-CAMT dedup property D-21 mandates.
- `'produces a different hash when bookedAt differs by one second'` — same-day-same-merchant-same-amount disambiguation D-22 mandates.
- `'is sensitive to every other v3 tuple field'` (Pest dataset over `userId | accountId | postedAt | amountMinor | currency | counterpartyNormalized`) — every other dimension still flips the hash.
- `'treats a null userId as zero in the v3 tuple'` — the `?? 0` fallback survives the rewrite.

## Artisan Command + HTTP-Unreachability

**Body shape** (lives at `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php`, ~225 lines):

1. SELECT every column the canonical needs from `transactions` ordered by `id`.
2. Walk rows; for each row whose `normalization_version < targetVersion`, build a `CanonicalTransaction` via `buildCanonicalFromRow()` and compute the new fingerprint via the injected `FingerprintComposer`.
3. Track `seen = ["${user_id}|${fingerprint}" => firstId]`. On second hit of the same key, record a collision entry and keep walking (collect ALL collisions, don't bail on the first).
4. If collisions exist: print `Fingerprint v3 migration ABORTED.`, then `N collision(s) detected:`, then the JSON dump, then `Existing rows left intact…`, return `FAILURE`. The pre-check is entirely in-memory so the table is untouched.
5. If `--dry-run` OR `--confirm` is absent: print `Dry-run OK. N rows would be re-derived to v3.` and return `SUCCESS`.
6. If `pendingCount === 0`: print `0 rows would be re-derived (already on v3).` and return `SUCCESS` (idempotent re-run path).
7. Else: open a DB transaction, walk `updates` and `UPDATE transactions SET fingerprint = ?, fingerprint_version = ?, normalization_version = ? WHERE id = ?` for each. Single transaction wrapping every update so the apply is atomic.

**Registration** in `LedgerServiceProvider::boot()`:
```php
if ($this->app->runningInConsole()) {
    $this->commands([
        RederiveFingerprintsCommand::class,
    ]);
}
```

**Compile-time HTTP-unreachability** in `tests/Contracts/BoundaryArchTest.php`:
```php
arch('RederiveFingerprintsCommand is never imported by any HTTP or routing namespace')
    ->expect('Modules\\Ledger\\Internal\\Console\\RederiveFingerprintsCommand')
    ->not->toBeUsedIn([
        'Modules\\Ledger\\Internal\\Http',
        'Modules\\Ledger\\Public\\Http',
        'Modules\\Ledger\\Routes',
        // … plus every other module's Http + Routes namespaces …
    ]);
```

The phase-2 mirror at `Modules/Ledger/tests/Feature/RederiveFingerprintsHttpUnreachableArchTest.php` carries the same rule chained with `->group('phase-2')` so the focused dev loop catches a regression without depending on the global Contracts suite.

**DI-only.** The command's `__construct` injects `FingerprintComposer` + `DatabaseManager`. The body uses `$this->info(...)` / `$this->error(...)` / `$this->line(...)` / `$this->option(...)` (all inherited from `Illuminate\Console\Command`). Verified by `grep -E 'Auth::|auth\(\)|DB::|Schema::|app\(\)|config\(\)|now\(\)' Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` returning zero matches.

## Migration Ordering — Why This Order

```
2026_05_13_010001_rederive_fingerprints_to_v3      <-- runs FIRST
2026_05_13_010002_add_enriched_from_to_transactions
2026_05_13_010003_add_enriched_count_to_import_runs
2026_05_13_010004_replace_transactions_fingerprint_unique_index  <-- runs LAST
```

- **010001 first**: the re-derive command's collision pre-check is the only place that catches the case where two existing rows would collide under v3. If 010004 (the UNIQUE-index swap) ran first, the existing v2 rows (whose distinguishing dimension was `source_ref`) might violate the new index — and the migration would fail mid-flight with the table in a half-altered state.
- **010002 + 010003 in the middle**: pure column adds, no row-content dependency, no FK between them. Order doesn't matter.
- **010004 last**: by the time it runs, every row carries `normalization_version = 3` AND every row is guaranteed to fit the new tuple shape (because the pre-check in 010001 would have aborted otherwise). The `DROP INDEX IF EXISTS; CREATE UNIQUE INDEX …` pair is safe.

**Idempotency proof.** `php artisan migrate:rollback --step=4 --force` + `php artisan migrate --force` round-trips cleanly. The re-derive command reports `0 rows would be re-derived (already on v3).` on the second run because the rolled-back state still has the right `normalization_version` stamps (migration 010001's `down()` is a documented no-op — restoring a v2 hash on v3-merged rows is destructive).

`Modules/Ledger/Database/Migrations/2026_05_13_010004_*.php` uses `DB::statement` for raw DROP + CREATE UNIQUE INDEX because SQLite cannot `ALTER INDEX`. The facade usage is the documented exception zone — migrations live at `Modules/Ledger/Database/Migrations/` which is excluded from the `BoundaryArchTest.php` facade ban (every Phase-1 migration uses `Schema::` and `DB::` the same way).

## `Phase2SchemaShapeTest` — Schema Gate

Replaces the brittle `php artisan tinker --execute=...` checks the orchestrator considered. Five `it(...)` blocks, all under `->group('phase-2')`:

1. `'adds the enriched_from column to transactions'` — `Schema::hasColumn('transactions', 'enriched_from')`.
2. `'adds the enriched_count column to import_runs'` — `Schema::hasColumn('import_runs', 'enriched_count')`.
3. `'replaces the composite UNIQUE index with the v3 tuple'` — `SELECT sql FROM sqlite_master WHERE type='index' AND name='transactions_fingerprint_uq'` MUST contain `booked_at` and MUST NOT contain `source_ref`.
4. `'keeps the SHA-256 fingerprint UNIQUE index intact'` — `transactions_fingerprint_sha_uq` still selects `user_id, fingerprint`.
5. `'defaults enriched_count to 0 for new import_runs rows'` — `pragma_table_info('import_runs')` reports the default literal as `0`.

The DatabaseManager is injected via `$this->app->make(DatabaseManager::class)` — no facade calls in test code.

## Model Edits — Cast Wiring

`Modules/Ledger/Models/Transaction.php`:
- New `use ArrayObject;` + `use Illuminate\Database\Eloquent\Casts\AsArrayObject;`
- `@property ArrayObject<int, array<string, mixed>>|null $enriched_from` added to the docblock between `$source_ref` and `$fingerprint`
- `$fillable` grows `'enriched_from'` (placed between `'source_ref'` and `'fingerprint'`)
- `casts()` grows `'enriched_from' => AsArrayObject::class`

`Modules/Ledger/Models/ImportRun.php`:
- `@property int $enriched_count` added between `$duplicate_count` and `$error_count`
- `$fillable` grows `'enriched_count'`
- `casts()` grows `'enriched_count' => 'integer'`

The PHPDoc generic on `enriched_from` is the loose `ArrayObject<int, array<string, mixed>>` rather than the tighter `array{format: string, ran_at: string, import_run_id: int, added: list<string>}` shape from the column's contract — Larastan level 10 strict + Eloquent's `AsArrayObject` cast don't round-trip the tighter shape through the property accessor. The contract-precise shape lives in the migration comment + this SUMMARY; Wave 3's enrichment writer is the actual code path that constructs the inner-array shape and a `@var array{format:…}` annotation at THAT call site will tighten the type where it matters.

## Test Helper Additions

`Modules/Ledger/tests/TestCase.php` grows four new `protected` helpers downstream Phase-2 tests can reuse:

| Helper | Purpose |
|--------|---------|
| `seedV2Row(array $overrides = []): int` | Insert one row at `normalization_version = 2` via DatabaseManager. Returns the inserted row's id. |
| `seedTwoNonCollidingV2Rows()` | Seed a clean v2 pair (different bookedAt seconds + different counterparties + different amounts) — the happy path for re-derive. |
| `seedCollidingV2Rows()` | Drop the v3 UNIQUE then seed two rows that share every v3 tuple dimension but differ on source_ref — simulates pre-migration data and triggers the rederive command's collision pre-check. |
| `seedOneV3Row()` | Seed a single row already at v3 so the rederive command's "skip if up-to-date" branch is exercised. |

`seedCollidingV2Rows` does NOT restore the dropped index. RefreshDatabase resets schema between tests, and a restored index would itself fail because the colliding rows already sit in the table.

## Pointers for Plans 02-03 / 02-04 / 02-05

**Plan 02-03 (ASN CSV adapter).** The adapter feeds the existing `NormalizeStage`, which already calls `FingerprintComposer::compose()` — the adapter does not need to import the composer directly. Importantly, the CSV adapter's emitted `CanonicalTransaction` MUST populate `bookedAt` with a real `CarbonImmutable` carrying the booking instant (not just the date), because v3 hashes through `bookedAt->toDateTimeString()`. If the CSV row only carries a date, the adapter must synthesise a stable time component (e.g. 00:00:00) — but then the cross-format dedup test for CSV-↔-CAMT will mis-fire because the CAMT side carries a real time. Plan 02-03 should pick a normalisation rule and document it in `02-03-SUMMARY.md`.

**Plan 02-04 (ASN CAMT.053 adapter).** Same DTO contract. CAMT.053 ships `<BookgDt>` + `<ValDt>` (date) plus `<NtryRef>` / `<EndToEndId>` — the adapter must emit `bookedAt` as a `CarbonImmutable` with the second-precision the cross-format test asserts. If the CAMT entry is date-only, the adapter must agree with the CSV-side normalisation rule from Plan 02-03 so cross-format dedup works.

**Plan 02-05 (enrichment writer + wizard).** The DTOs are now first-class imports:
- `use Modules\Import\Public\Dto\FingerprintDisposition;` — call `::newRow()` / `::duplicate()` / `::enriched(…)` directly.
- `use Modules\Import\Public\Dto\EnrichedDisposition;` — read `$d->existingTransactionId` + `$d->toSourceRef` when the disposition is enriched.
- `use Modules\Import\Public\Dto\PendingEnrichment;` — instantiate from the FingerprintStage's enriched-result and buffer in PreviewCache.
- Read `transactions.enriched_from` via `$tx->enriched_from` (already cast as `ArrayObject`). Append via `$tx->enriched_from = new ArrayObject([...$existing, $newEntry]); $tx->save();` — Eloquent's `AsArrayObject` cast does the JSON serialisation.
- Bump `import_runs.enriched_count` via `$run->increment('enriched_count')` inside the same DB transaction as the canonical insert.

## Decisions Made

- **Console Kernel via `Container::getInstance()->make(Kernel::class)`** rather than `app(Kernel::class)`. Both work inside migration files (the facade ban excludes `Modules/*/Database/Migrations/*`), but the explicit container call reads more clearly and aligns with the DI-only stance the rest of the codebase enforces.
- **Three separate `error()` / `error()` / `line()` calls** for the collision-abort output rather than one `sprintf("…\n%s")` block. `expectsOutputToContain` matches per-line, and a single multi-line string would have silently failed the `'1 collision(s) detected'` assertion when that substring sat on a non-first line.
- **`AsArrayObject` over `AsCollection`** for `transactions.enriched_from` — the column is append-only, mutation-in-place semantics are exactly what Wave 3 needs, and `AsCollection`'s richer API is unused here.
- **Loose `ArrayObject<int, array<string, mixed>>` PHPDoc** on `Transaction::$enriched_from` rather than the contract-precise `array{format: string, ran_at: string, …}` shape — Larastan level 10 strict can't round-trip the tighter shape through `AsArrayObject`. Documented as a deliberate trade-off here so Wave 3 doesn't get blindsided.
- **`seedCollidingV2Rows` drops but does NOT restore the v3 UNIQUE.** The drop IS the simulation; RefreshDatabase resets between tests; restoring after seeding would itself fail.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Missing Critical] Collision-abort output split across three calls instead of one sprintf block**
- **Found during:** Task 1 (RederiveFingerprintsCommandTest first run)
- **Issue:** The plan's RESEARCH.md body sample called `$this->error(sprintf("Fingerprint v%d migration ABORTED. %d collision(s) detected:\n%s", …))`. Pest's `expectsOutputToContain` matches per-line, so the substring `"1 collision(s) detected"` sat on the same line as `"Fingerprint v3 migration ABORTED."` and the test's two-substring assertion silently failed against the joined line. The fix splits the output across three calls (`error('Fingerprint v3 migration ABORTED.')`, `error('1 collision(s) detected:')`, `line($json)`) so each substring lands on its own line and the assertion is robust to future output reformat.
- **Fix:** Three `$this->error(...) / $this->line(...)` calls instead of one sprintf block.
- **Files modified:** Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php
- **Verification:** RederiveFingerprintsCommandTest's collision-abort assertion now passes; the output still carries the verbatim ABORTED / N collision(s) detected / JSON dump / "Existing rows left intact" sequence the migration runner surfaces.
- **Committed in:** 15122c3 (Task 1 commit)

**2. [Rule 1 — Bug] PHPStan level 10 strict rejects naïve `(int) $row->prop` casts on `stdClass` rows**
- **Found during:** Task 1 (post-implementation gate run)
- **Issue:** `DatabaseManager->table()->get()` returns `Collection<int, stdClass>` per PHPDoc, but PHPStan strict treats every property access on `stdClass` as `mixed` and refuses both `(int) $row->user_id` (cast.int on mixed) and `$row->user_id` (property.notFound). 45 errors total. The phpstan.neon banner forbids `@phpstan-ignore`, type casts to silence errors, and `assert()` / inline `@var` overrides.
- **Fix:** Adopted the project's own `UncategorizedTriageQuery` pattern — pipe every column through private static `toInt` / `toIntOrNull` / `toString` / `toStringOrNull` helpers that test type with `is_numeric` / `is_string` first and fall back to a typed default. The helpers return `int` / `?int` / `string` / `?string` so the call-site stays cast-free and PHPStan accepts the resulting `CanonicalTransaction` constructor call.
- **Files modified:** Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php
- **Verification:** `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`.
- **Committed in:** 15122c3 (Task 1 commit)

**3. [Rule 1 — Bug] Test seed helper inserts colliding rows the v3 UNIQUE forbids**
- **Found during:** Task 2 (full Pest run after migrations applied)
- **Issue:** The plan's `seedCollidingV2Rows` writes two rows that share every v3 tuple dimension and differ only on `source_ref`. Once Task 2's migration 010004 ran during `RefreshDatabase` bootstrap, the v3 UNIQUE index on `transactions(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)` rejected the second insert with `SQLSTATE[23000] Integrity constraint violation`. The plan's seed scenario assumes the v2 index is still in place — which is no longer true after the schema-shape migration lands.
- **Fix:** `seedCollidingV2Rows` now issues `DROP INDEX IF EXISTS transactions_fingerprint_uq` before inserting the colliding pair. The drop is the simulation — it models "what if v2 data existed before migration 010004 ran". RefreshDatabase reapplies the v3 schema between tests so the drop doesn't leak. The dropped index does NOT get re-created at the end of the helper because (a) restoring it would itself fail with the colliding rows present and (b) the rederive command's pre-check is in-memory and doesn't care whether the index is in place.
- **Files modified:** Modules/Ledger/tests/TestCase.php
- **Verification:** `vendor/bin/pest Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` — all 7 tests pass including the collision-abort assertion.
- **Committed in:** 3260102 (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (1 missing critical / output format, 2 bugs)
**Impact on plan:** All three were correctness fixes triggered by interaction with the live test environment that the plan's static review couldn't have caught. No scope creep — every fix narrowed onto an existing assertion in an existing test.

## Issues Encountered

None outside the three auto-fixed deviations above.

## Open Follow-ups for Plans 02-03 / 02-04 / 02-05

- **Plan 02-03 + 02-04 must agree on a `bookedAt` synthesis rule when the source row is date-only.** v3 hashes through `bookedAt->toDateTimeString()`, so a CSV row that carries date-only and synthesises `00:00:00` will collide with a CAMT entry that carries an actual booking timestamp — even though both represent the same transaction. Either (a) both adapters truncate to date and overwrite with `00:00:00`, or (b) both adapters preserve whatever the source carries and the cross-format dedup test accepts that the CSV-then-CAMT path enriches (not duplicates). Option (b) is what D-21 was designed for; option (a) is a defensible fallback. Pick before implementing the cross-format test.
- **The `enriched_from` PHPDoc generic is loose (`ArrayObject<int, array<string, mixed>>`).** Wave 3's enrichment writer should declare a `@param array{format: string, ran_at: string, import_run_id: int, added: list<string>} $entry` at the append site so the inner-array shape is enforced where the data actually originates, not where Eloquent's `AsArrayObject` cast obscures it.
- **The v2-coercion-on-rollback escape hatch is not implemented.** Migration 010001's `down()` is a documented no-op because reversing v3 → v2 is destructive on merged rows. The operator-facing recovery path is "restore from a SQLite backup" — Phase 11 (operational hardening) is where a `--force-downgrade` flag would land if ever needed.

## Self-Check: PASSED

- `Modules/Ledger/Public/Services/FingerprintComposer.php` — found
- `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` — found
- `Modules/Ledger/Providers/LedgerServiceProvider.php` (updated) — found, contains `RederiveFingerprintsCommand::class` and `runningInConsole`
- `Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php` — found
- `Modules/Ledger/Database/Migrations/2026_05_13_010002_add_enriched_from_to_transactions.php` — found
- `Modules/Ledger/Database/Migrations/2026_05_13_010003_add_enriched_count_to_import_runs.php` — found
- `Modules/Ledger/Database/Migrations/2026_05_13_010004_replace_transactions_fingerprint_unique_index.php` — found
- `Modules/Ledger/Models/Transaction.php` (updated) — found, contains `'enriched_from'` and `AsArrayObject`
- `Modules/Ledger/Models/ImportRun.php` (updated) — found, contains `'enriched_count'`
- `Modules/Import/Public/Dto/FingerprintDisposition.php` — found
- `Modules/Import/Public/Dto/NewRowDisposition.php` — found
- `Modules/Import/Public/Dto/DuplicateDisposition.php` — found
- `Modules/Import/Public/Dto/EnrichedDisposition.php` — found
- `Modules/Import/Public/Dto/PendingEnrichment.php` — found
- `Modules/Ledger/tests/Unit/FingerprintComposerV3Test.php` — found
- `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` — found
- `Modules/Ledger/tests/Feature/RederiveFingerprintsHttpUnreachableArchTest.php` — found
- `Modules/Ledger/tests/Feature/Phase2SchemaShapeTest.php` — found
- `Modules/Import/tests/Unit/FingerprintDispositionTest.php` — found
- `tests/Contracts/BoundaryArchTest.php` (updated) — found, contains `RederiveFingerprintsCommand` arch rule
- Commit `15122c3` — found in git log
- Commit `3260102` — found in git log
- Commit `5b8ea12` — found in git log

---

*Phase: 02-asn-statement-coverage-camt-053-mt940*
*Plan: 02 (Fingerprint v3 Foundation)*
*Completed: 2026-05-13*
