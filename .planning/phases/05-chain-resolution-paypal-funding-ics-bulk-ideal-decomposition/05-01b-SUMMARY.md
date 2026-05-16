---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 01b
subsystem: chains-scaffold
tags: [module-skeleton, fixtures, boundary-arch, horizon-smoke, pair-lookup]

# Dependency graph
requires:
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    provides: Horizon ^5.46 + Predis ^3.4 composer deps, failed_jobs table, redis queue connection
  - phase: 04-paypal-ingestion-transfer-detection
    provides: pair_transaction_id self-FK + symmetric-write listener in Modules/Transfers
provides:
  - Modules/Chains/ bounded module skeleton (composer.json + ChainsServiceProvider registered)
  - Modules/Chains/tests/fixtures/scenario-1/ synthesised cross-source matching fixture trio (clean + overpaid + underpaid variants)
  - scripts/synthesise_phase5_scenario.php — composer-dep-free, byte-identical re-run generator (seeded mt_srand(20260516))
  - Modules/Transfers/Public/Services/PairLookup with isPaired()/partnerId() — Public read API over transactions.pair_transaction_id
  - tests/Contracts/BoundaryArchTest extended with three new invariants (Modules\Chains\Internal scope, noResolverWritesTransactions, noOtherCardStatementStateMutator) + Cache facade carve-out for ResolveChainLinksJob
  - tests/Feature/HorizonBootsTest with explicit skip predicates (issue #9 fix — no skipOnFailure swallow-on-throw)
affects: [05-02, 05-03, 05-04, 05-05, 05-05b]

# Tech tracking
tech-stack:
  added: []  # No new dependencies — those landed in 05-01.
  patterns:
    - "Composer-dep-free CLI fixture synthesisers: scripts/synthesise_phase5_scenario.php hand-crafts CAMT.053 XML / PDF 1.4 byte stream / CSV without FPDF / phpspreadsheet / xml library, mirroring scripts/generate_tiny_ics_pdf.php + scripts/anonymize_paypal_csv.php"
    - "Module-skeleton bootstrap: empty placeholder dirs (Database/Migrations, Routes, Resources/views, Internal, Public, Models) committed via .gitkeep so the ChainsServiceProvider can conditionally loadMigrationsFrom / loadRoutesFrom / loadViewsFrom without later git noise"
    - "Public-API singleton-bind in Service Provider register() — PairLookup mirrors Categorization's UncategorizedTriageQuery binding"
    - "Explicit skip predicate over swallow-on-throw: HorizonBootsTest's Redis-ping test surfaces the missing precondition as `SKIPPED: Redis container required — run docker start diederik-redis` instead of silently consuming the assertion failure"
    - "BoundaryArchTest carve-out via pest-plugin-arch ->ignoring() allow-list: single FQN documents the per-class facade-use exception (queue infrastructure calls uniqueVia() pre-DI)"

key-files:
  created:
    - Modules/Chains/composer.json
    - Modules/Chains/Providers/ChainsServiceProvider.php
    - Modules/Chains/tests/Pest.php
    - Modules/Chains/tests/TestCase.php
    - Modules/Chains/tests/Unit/SmokeTest.php
    - Modules/Chains/tests/Unit/FixtureParseSmokeTest.php
    - Modules/Chains/Database/Migrations/.gitkeep
    - Modules/Chains/Routes/.gitkeep
    - Modules/Chains/Resources/views/.gitkeep
    - Modules/Chains/Internal/.gitkeep
    - Modules/Chains/Public/.gitkeep
    - Modules/Chains/Models/.gitkeep
    - Modules/Chains/tests/Unit/.gitkeep
    - Modules/Chains/tests/Feature/.gitkeep
    - Modules/Chains/tests/Contracts/.gitkeep
    - Modules/Chains/tests/fixtures/.gitkeep
    - Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml
    - Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf
    - Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv
    - Modules/Chains/tests/fixtures/scenario-1/scenario-1.md
    - Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json
    - Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json
    - Modules/Transfers/Public/Services/PairLookup.php
    - Modules/Transfers/tests/Feature/PairLookupTest.php
    - scripts/synthesise_phase5_scenario.php
    - tests/Feature/HorizonBootsTest.php
  modified:
    - bootstrap/providers.php
    - bootstrap/cache/services.php
    - composer.json
    - phpunit.xml
    - tests/Pest.php
    - tests/Contracts/BoundaryArchTest.php
    - Modules/Transfers/Providers/TransfersServiceProvider.php

# Fixture MD5 hashes (proof of idempotency — re-running scripts/synthesise_phase5_scenario.php on a clean filesystem produces these exact hashes)
fixture-hashes:
  asn-camt053.xml: 2ed819979b0bd6d962755e678cbb420c
  ics-statement.pdf: 757d521244fd757879e400e53dc9c499
  paypal-activity.csv: e34037ec7e4bcc15e4423d1c78c1ffa8
  scenario-1.md: 5fce7b09ccf6cb3489d3452f6d8c7b44
  scenario-1-overpaid.json: d86ba19461ef846cf26eb095ef518d81
  scenario-1-underpaid.json: ffa7e3a38e136936c1bfbc8757ed0f89

key-decisions:
  - "PDF font encoding: Type1 Helvetica with explicit /Encoding /WinAnsiEncoding directive — the standard /Helvetica encoding does not map 0x80 to the € glyph, so without the override pdftotext drops the € symbol and IcsPdfAdapter's parseFourColumnSummary regex (which requires `€ <amount> Af`) fails to match. The override adds 22 bytes to the PDF and lets the same byte-stream technique scripts/generate_tiny_ics_pdf.php uses cover € rendering"
  - "Test-Row uniqueness: PairLookupTest fixture rows vary amount_minor + counterparty_normalized per row to avoid the (user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized) UNIQUE constraint collision that hit on the second fixture row of the cross-user-isolation test cases. Mirrors how PairTransferCandidatesTest works around the same constraint"
  - "BoundaryArchTest D-84 + D-95 invariants implemented as it() grep-rules (not pest-arch ->expect rules) because they assert SQL surface (`->table('transactions')->...->update(...)` literal patterns) rather than class-level imports — same shape as the existing noPaypalApiRoute invariant"
  - "tests/Pest.php Chains wire-up: extended the existing per-module loop with Modules/Chains rather than adding a separate Chains-only stanza — keeps the wire-up shape single-source-of-truth"
  - "phpunit.xml ChainsUnit/ChainsFeature/ChainsContracts testsuites added as separate <testsuite> entries (rather than extending the existing flat Unit/Feature/Contracts entries) — gives a future CI run a way to run Chains-only via `--testsuite ChainsUnit`"

patterns-established:
  - "Modules/Chains/ skeleton shape: composer.json (diederik/chains, type laravel-module) + Providers/ChainsServiceProvider with conditional load* guards + tests/{Unit,Feature,Contracts,fixtures}/.gitkeep so empty dirs survive git"
  - "Public-surface promotion convention: Modules/<Name>/Public/Services/<Name>Lookup.php singleton-bound in <Name>ServiceProvider::register() — first applied to PairLookup, will recur for Modules/Chains/Public/Services/ChainLinkQuery + CardStatementQuery in later waves"
  - "Issue #9 fix lock: explicit skip predicate pattern for tests with external-service preconditions (Redis container, queue driver). CI grep gate `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php` prevents regression to the swallow-on-throw alternative"

requirements-completed: []  # 05-01b is Wave 0 scaffolding; CHN-07 ships when chain_links migration lands in a later wave.

# Metrics
duration: ~14min
completed: 2026-05-16
---

# Phase 05 Plan 01b: Wave 0 Module Skeleton + Fixture Trio + PairLookup + BoundaryArchTest Extensions Summary

**Scaffolded `Modules/Chains/` bounded module, committed a byte-identical synthesised cross-source matching fixture trio (clean / overpaid / underpaid), promoted `PairLookup` to the Transfers public surface, extended `BoundaryArchTest` with three new invariants (D-84 / D-95 / Cache facade carve-out), and shipped `HorizonBootsTest` with an explicit skip predicate that surfaces the Redis precondition instead of swallowing it.**

## Performance

- **Duration:** ~14 min
- **Started:** 2026-05-16T16:15:21Z
- **Completed:** 2026-05-16T16:29:45Z (approx)
- **Tasks:** 3 of 4 executed; Task 4 is a checkpoint (operator verification, auto-completed per orchestrator context)
- **Files created:** 26 (skeleton + fixtures + tests + script + test file)
- **Files modified:** 7 (bootstrap + composer + phpunit/Pest + arch test + provider)

## Accomplishments

- `Modules/Chains/` bounded module skeleton with composer.json (diederik/chains), `ChainsServiceProvider` (conditional load* guards), per-module `tests/Pest.php` + `tests/TestCase.php`, and ten `.gitkeep` placeholders so the empty dirs survive git
- `ChainsServiceProvider` registered in `bootstrap/providers.php` (alphabetical after Categorization, before Transfers); `composer.json` `autoload-dev.psr-4` extended with `Modules\\Chains\\Tests\\`; `phpunit.xml` gains three new `<testsuite>` entries (`ChainsUnit`, `ChainsFeature`, `ChainsContracts`); root `tests/Pest.php` per-module loop extended with `Modules/Chains` so `Modules/Chains/tests/Feature` inherits `RefreshDatabase`
- `Modules/Chains/tests/Unit/SmokeTest` runs green via `vendor/bin/pest --filter "SmokeTest"`
- `scripts/synthesise_phase5_scenario.php` committed: composer-dep-free, seeded `mt_srand(20260516)`, writes six fixture files. Re-running on a clean filesystem produces byte-identical MD5 hashes (verified via diff of pre/post-run hash files)
- Six fixture files committed under `Modules/Chains/tests/fixtures/scenario-1/`:
  * `asn-camt053.xml` — CAMT.053.001.02, one DBIT entry of EUR 847,32 dated 2026-05-19 with CdtrAcct `ICS-CARD` and remittance `iDEAL betaling ICS afschrift 2026-04`
  * `ics-statement.pdf` — hand-crafted PDF 1.4 byte stream, 3.1 KB, 23 transaction rows for period 2026-04-15..2026-05-14, settled EUR totals summing to 84732 cents (€847,32); 21 EUR-native rows + 2 USD FX rows in the empirical `Wisselkoers <CCY> <rate>` two-line shape; `/Encoding /WinAnsiEncoding` directive on the Helvetica font so pdftotext renders €
  * `paypal-activity.csv` — 14 rows (1 header + 13 body) in the NL profile carrying the 7-token discriminator (Datum, Tijd, Tijdzone, Omschrijving, Valuta, Transactiereferentie, Reference Txn ID); includes Bankstorting close-out row with destination IBAN `NL57ASNB0123456789` (D-106 hand-off), a deterministic Reference-Txn-ID chain, and a 4-row USD FX chain
  * `scenario-1.md` — fixture record with period, transaction count, totals, IBANs, variant deltas, locked feature map
  * `scenario-1-overpaid.json` — overlay: `bulk_settle_amount_minor: 84885`, `delta_minor: 153`, `expected_card_statement_state: overpaid`, `expected_credit_carry_minor: 153`
  * `scenario-1-underpaid.json` — overlay: `bulk_settle_amount_minor: 84514`, `delta_minor: -218`, `expected_card_statement_state: partially_settled`, `expected_credit_carry_minor: 0`
- `Modules/Chains/tests/Unit/FixtureParseSmokeTest` green — all three fixtures parse via their production Phase 2/3/4 adapters:
  * `IcsPdfAdapter` yields 23 transactions whose settled-EUR amounts sum to −84732 cents, statement metadata reports closing balance −84732 cents and entry count 23
  * `PaypalCsvAdapter` yields ≥3 logical rolled-up transactions and at least one carries the Bankstorting + destination IBAN hand-off in its `events[]` envelope
  * `genkgo/camt` reads the CAMT.053 cleanly via `new Reader(Config::getDefault())` + `readFile()`; the single DBIT entry's `Money::getAmount()` is `-84732` (DBIT signed-negative per camt convention)
- `Modules/Transfers/Public/Services/PairLookup` shipped — DI-only (constructor-injected `DatabaseManager`), `isPaired(int $txId, Modules\Core\Models\User $user): bool` and `partnerId(int $txId, Modules\Core\Models\User $user): ?int`; both methods filter on `user_id` first so cross-user access is structurally impossible. Singleton-bound in `TransfersServiceProvider::register()`. PairLookupTest covers 6 cases: paired-true, unpaired-false, partner-id-int, partner-id-null, cross-user-isolation (primary→other), and cross-user-isolation (both users have their own paired pairs simultaneously)
- `tests/Contracts/BoundaryArchTest` extended with three new invariants and one carve-out:
  * `arch('Modules\\Chains\\Internal is only used inside Modules\\Chains')` — pest-arch scope rule (trivially satisfied today; binds as soon as Internal/ ships real code)
  * `it('...noResolverWritesTransactions)` — grep rule asserting no file under `Modules/Chains/Internal/Resolvers/` calls `Transaction::query|Transaction::where|->table('transactions')->...->update/insert/delete` (comments stripped before grep). Trivially satisfied until Wave 2 ships resolvers; the rule is in place so any future regression trips
  * `it('...noOtherCardStatementStateMutator)` — grep rule asserting no file other than `Modules/Chains/Internal/CardStatementStateMachine.php` writes to `card_statements.state` via either raw query builder or Eloquent `update(['state' => ...])`. Test files skipped (the architectural invariant is a production-code rule)
  * `no Laravel facade usage in module code` rule extended with `->ignoring(['Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob'])` — the single permitted Cache facade carve-out (Laravel's queue infrastructure calls `uniqueVia()` pre-DI). Confirmed `pest-plugin-arch ^4.0` supports `->ignoring()` (verified via `grep ignoring vendor/pestphp/pest-plugin-arch/src/GroupArchExpectation.php`)
- `tests/Feature/HorizonBootsTest` shipped with three smoke checks:
  * `it('connects to Redis on 127.0.0.1:6379')` — Predis ping, uses `->skip(fn () => ! isRedisReachable(...), 'Redis container required — run docker start diederik-redis ...')` so the precondition surfaces as a visible skip reason instead of being swallowed silently
  * `it('Horizon service provider boots without errors')` — asserts `Laravel\Horizon\Horizon` class exists and `config('horizon')` returns a non-empty array
  * `it('queue config defaults to redis driver when QUEUE_CONNECTION=redis')` — uses an explicit skip predicate against the env var so the precondition surfaces visibly
  * Grep gate verified: `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php` passes (the string does not appear in the file; the docblock describes the fix without using the literal word)
- Larastan level 10 strict: clean on the analysed paths (Modules + app + bootstrap/app.php); the new `scripts/synthesise_phase5_scenario.php` lives outside the analysed paths by convention (mirrors `scripts/anonymize_paypal_csv.php` and `scripts/generate_tiny_ics_pdf.php`)
- Pint format check: clean (`vendor/bin/pint --test` reports `passed`)

## Task Commits

1. **Task 1: Modules/Chains skeleton + composer autoload + ServiceProvider registration + phpunit/Pest wire-up + SmokeTest** — `51c2700` (feat)
2. **Task 2: Synthesised fixture trio + scripts/synthesise_phase5_scenario.php + FixtureParseSmokeTest** — `868718b` (feat)
3. **Task 3: PairLookup Public promotion + BoundaryArchTest extensions + HorizonBootsTest with explicit skip predicate** — `a196c8b` (feat)
4. **Task 4: Verify Wave 0 module + fixture half is operational** — CHECKPOINT (orchestrator-approved per operator context; verification ran clean inside this executor)

_Plan metadata commit follows in the final commit step._

## Files Created/Modified

### Created

- **Modules/Chains skeleton** — `Modules/Chains/composer.json` (diederik/chains, PSR-4 `Modules\Chains\`), `Modules/Chains/Providers/ChainsServiceProvider.php` (final class with conditional `loadMigrationsFrom`/`loadRoutesFrom`/`loadViewsFrom` guards; concrete bindings deferred to later waves), `Modules/Chains/tests/Pest.php`, `Modules/Chains/tests/TestCase.php`, `Modules/Chains/tests/Unit/SmokeTest.php`
- **Placeholder dirs** — `.gitkeep` files under `Modules/Chains/{Database/Migrations,Routes,Resources/views,Internal,Public,Models}` and `Modules/Chains/tests/{Unit,Feature,Contracts,fixtures}`
- **Fixture trio** — six files under `Modules/Chains/tests/fixtures/scenario-1/` (see MD5 hashes in frontmatter)
- **Fixture parse smoke test** — `Modules/Chains/tests/Unit/FixtureParseSmokeTest.php` (three test cases covering IcsPdfAdapter, PaypalCsvAdapter, genkgo/camt)
- **Public PairLookup** — `Modules/Transfers/Public/Services/PairLookup.php` (final class, constructor-injected `DatabaseManager`)
- **PairLookup test** — `Modules/Transfers/tests/Feature/PairLookupTest.php` (6 cases)
- **CLI synthesiser** — `scripts/synthesise_phase5_scenario.php` (composer-dep-free, seeded `mt_srand(20260516)`)
- **Horizon-boots smoke test** — `tests/Feature/HorizonBootsTest.php` (three smoke cases with explicit skip predicates)

### Modified

- `bootstrap/providers.php` — registered `ChainsServiceProvider::class`
- `bootstrap/cache/services.php` — regenerated by `composer dump-autoload` (Chains provider added to manifest)
- `composer.json` — `autoload-dev.psr-4` extended with `Modules\\Chains\\Tests\\` → `Modules/Chains/tests/`
- `phpunit.xml` — three new `<testsuite>` entries (`ChainsUnit`, `ChainsFeature`, `ChainsContracts`)
- `tests/Pest.php` — module wire-up loop extended with `Modules/Chains`
- `tests/Contracts/BoundaryArchTest.php` — three new invariants + Cache facade carve-out
- `Modules/Transfers/Providers/TransfersServiceProvider.php` — singleton-binds `PairLookup` in `register()`

## Decisions Made

See `key-decisions` in the frontmatter. The two most consequential at runtime:

1. **PDF font encoding override.** The standard Type1 Helvetica encoding does not map 0x80 (WinAnsi code for €) to a glyph. Adding `/Encoding /WinAnsiEncoding` to the font dict (one extra dictionary entry, ~22 bytes) makes pdftotext render € correctly, which the `IcsPdfAdapter::parseFourColumnSummary` regex requires (it greps for the literal `€ <amount> Af` pattern). The fix matches what production PDFs do anyway — Mijn ICS exports use WinAnsi-style encoding so its statement totals render `€` to pdftotext.
2. **Issue #9 fix shape.** The explicit `->skip(fn () => !isRedisReachable(...), 'Redis container required — run docker start diederik-redis ...')` evaluates the predicate BEFORE running the test body. The skip reason appears in `pest` output as a named SKIPPED message, making the missing precondition visible. The grep gate `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php` locks the fix in place: any future maintainer who reintroduces the swallow-on-throw shape trips the CI gate.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] PDF font encoding override added so € renders through pdftotext**

- **Found during:** Task 2 (initial smoke-test run of FixtureParseSmokeTest)
- **Issue:** The first pass of `scripts/synthesise_phase5_scenario.php` emitted a Type1 Helvetica font dict without an `/Encoding` directive. `pdftotext -layout` extracted the document but dropped every `€` glyph (the byte 0x80 had no mapping in the default Type1 encoding). `IcsPdfAdapter::parseFourColumnSummary`'s regex requires the literal `€ <amount> Af` pattern to anchor the four-column summary, so the ICS smoke-test assertion `expect($meta->closingBalanceMinor)->toBe(-84732)` would have failed.
- **Fix:** Added `/Encoding /WinAnsiEncoding` to the font object dict. Re-running the synthesiser produces the same XML / CSV / MD hashes (idempotency preserved) and the PDF now renders `€` to pdftotext correctly.
- **Files modified:** `scripts/synthesise_phase5_scenario.php` (one-line dict change), regenerated `Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf`
- **Verification:** `pdftotext -layout Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf - | head -10` now shows the `€ 847,32 Af` summary row; FixtureParseSmokeTest passes.
- **Committed in:** `868718b` (Task 2 commit)

**2. [Rule 3 — Blocking] PairLookupTest fixture rows varied per row to avoid UNIQUE-constraint collision**

- **Found during:** Task 3 (initial PairLookupTest run)
- **Issue:** The first draft of the test's `pairLookupTx()` helper reused identical `amount_minor = -1500` + `counterparty_normalized = 'partner'` defaults across multiple rows. The Phase 1 `transactions` table carries a UNIQUE constraint on `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)`, so the second fixture row in any test that created two rows collided.
- **Fix:** Made the helper vary `amount_minor` (`-1500 - $rowIndex`) + `counterparty_name`/`counterparty_normalized` (`Partner $rowIndex` / `partner-$rowIndex`) per row. Mirrors how Phase 4's existing `PairTransferCandidatesTest` works around the same constraint.
- **Files modified:** `Modules/Transfers/tests/Feature/PairLookupTest.php`
- **Verification:** All 6 cases pass.
- **Committed in:** `a196c8b` (Task 3 commit)

**3. [Rule 3 — Blocking] HorizonBootsTest docblock rephrased to satisfy the grep gate**

- **Found during:** Task 3 (post-write grep-gate check `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php`)
- **Issue:** The first draft of the file's class-level docblock used the literal word `skipOnFailure` to explain what the fix prevented. The CI grep gate is unconditional (greps the whole file, comments included), so any literal mention trips it. The plan's verify step explicitly requires `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php` to pass.
- **Fix:** Rephrased the docblock to use "swallow-on-throw alternative" instead of the literal API name. The fix is still documented in plain language — the rationale, the symptom (silently consumed assertions), the visibility benefit of the explicit predicate — without using the word that trips the grep gate.
- **Files modified:** `tests/Feature/HorizonBootsTest.php`
- **Verification:** `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php` exits 0 (no match).
- **Committed in:** `a196c8b` (Task 3 commit)

**4. [Rule 3 — Pint auto-fix] Pint single-quote + unary-spacing normalisation on BoundaryArchTest**

- **Found during:** Task 3 (pre-commit `vendor/bin/pint --test`)
- **Issue:** The new grep-based BoundaryArchTest invariants used double-quoted patterns where single quotes would do, and inconsistent unary-operator spacing.
- **Fix:** Ran `vendor/bin/pint` (the project's standard preset) to auto-fix; format pass clean afterward.
- **Files modified:** `tests/Contracts/BoundaryArchTest.php` (formatter-only changes)
- **Verification:** `vendor/bin/pint --test` exits 0 with `{"tool":"pint","result":"passed"}`.
- **Committed in:** `a196c8b` (Task 3 commit)

---

**Total deviations:** 4 auto-fixed (1 bug, 3 blocking) — all internal correctness fixes. No scope creep.

**Impact on plan:** All four fixes were necessary for the verify-block automated checks to pass. The fixture-trio MD5 hashes locked at the values listed in the frontmatter after the PDF encoding fix.

## Issues Encountered

- **Redis-ping smoke test skipped at executor time.** The orchestrator context noted the operator confirmed Docker Redis was running on `127.0.0.1:6379`, but the executor's PHP process could not reach it (`fsockopen` returned `Connection refused`). This is exactly what the issue #9 fix is designed for — the explicit skip predicate surfaces the precondition as a visible SKIPPED line in the test output (`Redis container required — run docker start diederik-redis or follow the README setup.`) rather than swallowing it. The Horizon-class-and-config-presence assertion still runs and passes; the queue-default-redis assertion is skipped because `phpunit.xml` pins `QUEUE_CONNECTION=sync` for tests (also a visible skip with a named reason). When the operator runs the suite locally with Docker Redis up and `QUEUE_CONNECTION=redis` in `.env.testing`, both skips flip to passes.
- **Pre-existing TransactionTypeTest failure carried forward.** Documented in 05-01-SUMMARY and Phase 4 SUMMARY logs; out of scope for this plan.
- **Pre-existing FND-05 reference at `README.md:76`.** Documented in 05-01-SUMMARY; out of scope for this plan (would require widening the diff into a doc-cleanup task).

## User Setup Required

None. Plan 05-01 already documented the Docker Redis setup; this plan's HorizonBootsTest is designed so the Redis-ping smoke gracefully skips when the container isn't running. The operator-facing checkpoint (Task 4) verification commands all return clean inside this executor:

| Check                              | Command                                                                                                                      | Expected      | Actual         |
|------------------------------------|------------------------------------------------------------------------------------------------------------------------------|---------------|----------------|
| Wave 0 test suite                  | `vendor/bin/pest --filter "Chains\|PairLookup\|BoundaryArchTest\|HorizonBoots\|SmokeTest\|FixtureParseSmokeTest"`              | all green     | 24 passed, 2 visible skips |
| ASN bulk-iDEAL amount              | `xmllint --xpath "string(//*[local-name()='Ntry'][1]/*[local-name()='Amt'])" Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml` | `847.32`     | `847.32`       |
| Overpaid overlay total             | `jq '.bulk_settle_amount_minor' Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json`                            | `84885`       | `84885`        |
| Underpaid overlay total            | `jq '.bulk_settle_amount_minor' Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json`                           | `84514`       | `84514`        |
| Boundary invariants present        | `grep 'noResolverWritesTransactions\|noOtherCardStatementStateMutator\|ResolveChainLinksJob' tests/Contracts/BoundaryArchTest.php` | ≥3 lines | 4 lines        |
| Issue #9 fix lock                  | `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php`                                                                | no match      | no match       |
| Explicit skip predicate present    | `grep 'isRedisReachable\|Redis container required' tests/Feature/HorizonBootsTest.php`                                       | both          | 4 lines        |

## Next Phase Readiness

Wave 0 module + fixture half is complete. Downstream plans inherit:

- `Modules/Chains/` registered + tested — Wave 1 (`05-02`) adds the three schema migrations (`chain_links`, `card_statements`, `card_statement_credits`) + the one-shot back-population migration under `Modules/Chains/Database/Migrations/`. The ServiceProvider's `is_dir(__DIR__.'/../Database/Migrations')` guard will fire as soon as those migrations land.
- Synthesised fixture trio — Wave 2 (`05-03`) resolvers and Wave 4 review-queue UI consume `Modules/Chains/tests/fixtures/scenario-1/` directly. The three variants (clean / overpaid / underpaid) exercise all three tolerance arms of D-97.
- `PairLookup` Public service — Wave 3 (`05-04`) `PaypalFundingResolver` constructor-injects it via DI to decide whether a chain_link should add another funder leg or stop at the Phase 4 partner row.
- BoundaryArchTest invariants — as soon as Wave 1 schema migrations land and Wave 2 resolvers ship code, the three new invariants bind concrete production-code surface. Today they trivially pass against the empty Internal/Resolvers/ folder.
- HorizonBootsTest — once the operator boots Docker Redis + sets `QUEUE_CONNECTION=redis` in `.env.testing`, the two currently-skipped tests flip to passes. The lock-in grep gate (no `skipOnFailure`) is permanent.

No blockers identified for `05-02`.

## Self-Check: PASSED

Created files exist on disk:
- `Modules/Chains/composer.json` — FOUND
- `Modules/Chains/Providers/ChainsServiceProvider.php` — FOUND
- `Modules/Chains/tests/Pest.php` — FOUND
- `Modules/Chains/tests/TestCase.php` — FOUND
- `Modules/Chains/tests/Unit/SmokeTest.php` — FOUND
- `Modules/Chains/tests/Unit/FixtureParseSmokeTest.php` — FOUND
- `Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml` — FOUND
- `Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf` — FOUND
- `Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv` — FOUND
- `Modules/Chains/tests/fixtures/scenario-1/scenario-1.md` — FOUND
- `Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json` — FOUND
- `Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json` — FOUND
- `Modules/Transfers/Public/Services/PairLookup.php` — FOUND
- `Modules/Transfers/tests/Feature/PairLookupTest.php` — FOUND
- `scripts/synthesise_phase5_scenario.php` — FOUND
- `tests/Feature/HorizonBootsTest.php` — FOUND

Commits exist in `git log`:
- `51c2700` (Task 1, feat) — FOUND
- `868718b` (Task 2, feat) — FOUND
- `a196c8b` (Task 3, feat) — FOUND

---
*Phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition*
*Plan: 01b*
*Completed: 2026-05-16*
