---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 04
subsystem: chains
tags: [resolver, paypal-funding, fuzzy-match, levenshtein, signature-hash, auto-promotion, learning-loop, public-action, public-service, chain-tree, review-queue, d-106, d-87, d-88, d-89, d-91]

# Dependency graph
requires:
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 03
    provides: IcsSettlementResolver wired + ChainLinkInsertHelper shared INSERT site + ResolveChainLinksJob queued + PaypalFundingResolver Wave-2 stub
  - phase: 04-paypal-ingestion-transfer-detection
    provides: PayPal CSV import writes raw_payload.events[] event tape; PairLookup public read-side API; pair_transaction_id self-FK on transactions; D-106 General Withdrawal hand-off context
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 02
    provides: chain_links + card_statements schema; ChainLink/CardStatement Eloquent models; 5 Public DTOs (ChainTree, ChainTreeNode, ChainLinkRow, CardStatementForecastTile, StatementSettlement)
provides:
  - PaypalFundingResolver — real two-arm algorithm (deterministic D-106 close-out + fuzzy CHN-02), replaces the Wave-2 stub at the same class FQN so the singleton binding stays untouched
  - ChainLinkQuery — Public read API (forTransaction tree assembly + openCandidateCount badge + candidatesForReview cursor-paginated rows with confirmsRemaining computed)
  - CardStatementQuery — Public read API (openForAccount returning ?CardStatement)
  - ConfirmChainLink — Public action wiring the D-87/D-88 auto-promotion learning loop (3 same-signature confirms → remaining same-signature candidates auto-promoted to state=confirmed resolver=rule)
  - RejectChainLink — Public action (per-pair only per D-89; signature counter neutral)
  - 5 new test files (PaypalFundingResolverTest + ChainLinkQueryTest + CardStatementQueryTest + ConfirmChainLinkTest + RejectChainLinkTest — 34 cases, 99 assertions)
affects: [05-05, 05-05b]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Resolver-internal reads via raw DatabaseManager query-builder (never `Transaction::query()` / `Transaction::where`) — keeps `BoundaryArchTest::noResolverWritesTransactions` clean without per-call exemptions while still allowing read access to transactions.raw_payload. Mirrors the IcsSettlementResolver shape from Wave 2."
    - "Fuzzy match weighted score = 0.5·levenshtein_similarity + 0.3·amount_band_similarity + 0.2·date_window_similarity — single-pass linear scan over <=20 candidate transfer_in rows (limit() guard against pathological signatures matching every IBAN)."
    - "signature_hash = sha256(normalized_merchant + '|' + funding_account_iban) computed at the resolver level for both arms — D-88. The Wave-3 ConfirmChainLink learning loop counts confirmed rows sharing this hash via `whereJsonContains('evidence->signature_hash', $hash)`, verified live in SQLite (ChainLinksJsonContainsSmokeTest passes; the JSON1 fallback path remains documented but unused)."
    - "Public action 404 surface: explicit `throw new NotFoundHttpException(...)` instead of Eloquent `firstOrFail()` — the framework converts ModelNotFoundException to 404 at the router boundary, but explicit throwing is testable outside HTTP (Pest action tests cannot assert the implicit conversion)."
    - "Chain-tree walker uses BFS frontier + visited-set + depth counter — bounded at MAX_DEPTH=5 (D-92) with `to_transaction_id IS NULL` legs explicitly skipped (issue #10 — exceeded-tolerance ICS bulk-settle candidates surface in the review queue, not the drawer walker)."

key-files:
  created:
    - Modules/Chains/Public/Services/ChainLinkQuery.php
    - Modules/Chains/Public/Services/CardStatementQuery.php
    - Modules/Chains/Public/Actions/ConfirmChainLink.php
    - Modules/Chains/Public/Actions/RejectChainLink.php
    - Modules/Chains/tests/Unit/Resolvers/PaypalFundingResolverTest.php
    - Modules/Chains/tests/Feature/ChainLinkQueryTest.php
    - Modules/Chains/tests/Feature/CardStatementQueryTest.php
    - Modules/Chains/tests/Feature/ConfirmChainLinkTest.php
    - Modules/Chains/tests/Feature/RejectChainLinkTest.php
  modified:
    - Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php
    - Modules/Chains/Providers/ChainsServiceProvider.php

key-decisions:
  - "PaypalFundingResolver reads `raw_payload` via raw DatabaseManager query-builder (`->table('transactions')->select(['raw_payload', ...])->get()`) and json_decodes the SELECT result inline. Avoids any Transaction::query() call so BoundaryArchTest::noResolverWritesTransactions stays clean without an exemption — the alternative (Eloquent read for raw_payload AsArrayObject cast) would require adding a regex carve-out to the arch test or risk a false positive. The trade-off is one json_decode call per PayPal row; the resolver iterates <100 rows per pass even on a full back-fill so the cost is invisible."
  - "Fuzzy arm uses PHP's native `levenshtein()` (not iterative Damerau-Levenshtein, not a Jaro-Winkler variant). Reason: the synthetic NL PayPal fixture sees merchant differences in the 1–3 char edit-distance range — single letter swaps in 'spotify ab' vs 'spotyfi ab' resolve correctly with plain Levenshtein. A more elaborate metric is justified only when the live signal proves the simpler one misses; deferring per RESEARCH Don't-Hand-Roll table."
  - "FUZZY_MAX_CONFIDENCE = 0.99 — fuzzy score is `min(0.99, $weightedScore)` so 1.0 stays exclusive to the deterministic arm. Without the clamp a fuzzy match against an identical merchant + identical amount + same date would score 1.0 (the weighted blend can hit 1.0 cleanly), which would render in the UI's Deterministic chip and confuse the user about the source of the confidence."
  - "ConfirmChainLink wraps both the targeted-row save AND the auto-promotion update inside a single `db->connection()->transaction(...)` closure. Atomicity matters because the targeted row's promotion is itself one of the rows the threshold count sees — if the closure rolled back midway, the user would see a half-applied learning loop (one row confirmed, no auto-promotion sweep) on the next page refresh."
  - "ConfirmChainLink leaves the row's `resolver` value untouched when promoting a user-driven confirm — the row's resolver stays 'auto' (the resolver wrote it; the user merely confirmed). Auto-promoted siblings DO get `resolver='rule'` so the UI's D-91 chip can distinguish 'Confirmed by user' (auto + confirmed) from 'Confirmed via learning loop' (rule + confirmed)."
  - "RejectChainLink dropped its DatabaseManager DI — the action's only write path is Eloquent `save()` on the loaded ChainLink, and the Wave-2 RejectChainLink plan's literal constructor signature included a DatabaseManager that was never consumed. A future batch-reject extension that needs raw query-builder writes can DI it then; until that arrives the property would be dead under PHPStan strict-rules' `property.onlyWritten` lint."
  - "ChainLinkQuery::makeChainLinkRow uses explicit null-check + local variable scaffolding instead of nullsafe `?->` followed by `?? defaultValue`. Reason: Larastan's `nullsafe.neverNull` lint trips on the `?->` pattern because the inferred type of `first()` collapses to non-null after PHPStan's narrowing. The explicit-check shape stays both strict-rules-clean and easier to step through in a debugger."
  - "CardStatementQuery::openForAccount does a two-step read: raw query-builder SELECT id (cross-user scope binds here), then Eloquent `where('id', $id)->first()` to hydrate the model. The Eloquent variant could have been `find($row->id)` but Larastan types `find($scalar)` as `Collection<int, Model>|Model|null` — the union widens the return type past `?CardStatement`. `where('id', $id)->first()` narrows cleanly to `?CardStatement`."
  - "ChainLinkQuery's walker queries chain_links in BFS layers (one query per depth) rather than the alternative recursive single-CTE shape. SQLite supports recursive CTEs but the BFS shape is portable, easy to bound at MAX_DEPTH=5, and the per-depth round-trip is bounded at depth=5 → at most 5 chain_links queries + N+1 transactions queries (where N <= visited.size). For the project's payment topology (<=5 levels, <=10 funders per chain), this is <100 queries per drawer render."
  - "Pre-existing TransactionTypeTest::it-rejects-an-invalid-transaction-type test remains failing in this environment (Phase 4 deferred-items.md row carried forward — Pest parallel-mode SQLite trigger handling oddity, reproducible on b57c0dd, unaffected by this wave). Out of scope per Wave 3's deviation rules."

metrics:
  duration: ~35min
  completed: 2026-05-16

threat_register:
  T-05-04-01:
    category: Elevation of Privilege
    component: User confirms / rejects a chain_link belonging to another user
    disposition: mitigate
    mitigation: "Explicit `if ($link === null) throw new NotFoundHttpException(...)` after a (id, user_id)-scoped `where()->first()` in both ConfirmChainLink and RejectChainLink. Cross-user tests in ConfirmChainLinkTest + RejectChainLinkTest cover both actions."
  T-05-04-02:
    category: Information Disclosure
    component: ChainTree walks cross-user transactions and leaks counterparty/amount data
    disposition: mitigate
    mitigation: "Every `forTransaction` query (root read + frontier walk + per-node transaction hydration + account-name lookup) filters on `user_id = $user->id`. Cross-user 404 on the ROOT transaction prevents any downstream walk; the ChainLinkQueryTest 'cross-user' case exercises the path."
  T-05-04-03:
    category: Tampering
    component: Crafted evidence.signature_hash poisons the auto-promotion learning loop
    disposition: accept
    mitigation: "Evidence is resolver-written only. No UI surface accepts user-input evidence in this wave. A future surface that did would need explicit schema validation; documented in PaypalFundingResolver class docblock."
  T-05-04-04:
    category: Denial of Service
    component: ConfirmChainLink whereJsonContains scans every chain_link of the user
    disposition: accept
    mitigation: "Single-user app; 30k rows is the upper-bound size. Index `(user_id, state)` from Wave 1 schema covers the WHERE filter. JSON1 path lookup is O(N) but bounded. If perf becomes a UI issue, materialise an `evidence_signature_hash` column."
  T-05-04-05:
    category: Tampering
    component: Walk depth = 5 trips a cycle, missing a leg
    disposition: accept
    mitigation: "Project's payment topology is <=5 levels per D-92. The visited[] set defends against accidental cycles. If a future chain_link kind produces cycles, the visited[] guard short-circuits."
  T-05-04-06:
    category: Repudiation
    component: Auto-promoted (resolver='rule') chain_links carry no audit trail of which confirms triggered them
    disposition: accept
    mitigation: "The 3 confirmed rows with the same signature_hash ARE the audit trail. `chain_link.updated_at` records the promotion time."
  T-05-04-07:
    category: Information Disclosure
    component: Levenshtein on raw counterparty_normalized leaks sub-string matches across users via cache timing
    disposition: accept
    mitigation: "Single-user app; not a multi-tenant concern."
  T-05-04-08:
    category: Tampering
    component: Resolver's pre-insert guard race between two job invocations
    disposition: mitigate
    mitigation: "Per-user job uniqueness via ShouldBeUniqueUntilProcessing (Wave 2) eliminates the race for normal dispatch. Production has no manual job-invoke surface."
---

# Phase 5 Plan 04: Wave 3 — PayPal funding resolver + Public review surface Summary

Replaced the Wave-2 `PaypalFundingResolver` stub with the real two-arm algorithm (deterministic D-106 close-out via `raw_payload.events[]` IBAN inspection; fuzzy CHN-02 via Levenshtein + amount band + date window) and shipped the four Public seams the Wave-4 UI consumes — `ChainLinkQuery`, `CardStatementQuery`, `ConfirmChainLink` (with auto-promotion learning loop), `RejectChainLink` (per-pair only).

## Decisions Made

See `key-decisions` in the frontmatter for the full set. Highlights:

- **`raw_payload` is decoded inline via raw DatabaseManager query-builder reads** so `BoundaryArchTest::noResolverWritesTransactions` stays clean without a regex exemption — the alternative (Eloquent `Transaction::query()` for the AsArrayObject cast) would have required widening the arch test or accepting a false-positive risk on every resolver edit.
- **Fuzzy weights are 0.5 / 0.3 / 0.2 (merchant / amount / date)** and **FUZZY_MAX_CONFIDENCE is 0.99** so deterministic stays the only path to 1.0 confidence — keeps the UI's D-91 Deterministic chip semantically pure.
- **`signature_hash` formula at both resolver arms: `sha256(normalized_merchant + '|' + funding_account_iban)`** per D-88. Computed at write time; consumed by `ConfirmChainLink` and `ChainLinkQuery::candidatesForReview` via `whereJsonContains('evidence->signature_hash', $hash)`.
- **`ConfirmChainLink` wraps the targeted-row save AND the auto-promotion sweep in one `db->connection()->transaction(...)` closure** so a partial promotion can never render in the UI.
- **`RejectChainLink` dropped its DI DatabaseManager** — single-row Eloquent save() is enough; the plan's literal constructor signature included a property that would have tripped PHPStan strict-rules' `property.onlyWritten` lint.
- **`ChainLinkQuery` walker = BFS frontier + visited-set + depth counter (MAX_DEPTH=5)** with `to_transaction_id IS NULL` legs explicitly skipped (issue #10 — exceeded-tolerance ICS bulk-settle candidates surface in `candidatesForReview`, not the drawer walker).
- **D-91 confidence-tier mapping**: `state=confirmed AND resolver=auto AND confidence=1.0 → Deterministic`; `state=confirmed (any other resolver / confidence) → Confirmed`; `state=candidate → Candidate`. Validated by `ChainLinkQueryTest::forTransaction maps confidence tiers per D-91`.

## SQLite whereJsonContains verification outcome (RESEARCH Pattern 5)

The whereJsonContains JSON1 fallback was **not needed**. Live verification:

- `ChainLinksJsonContainsSmokeTest` (Wave 1) passes — both `whereJsonContains('evidence->signature_hash', $hash)` AND the `whereRaw("json_extract(...)")` fallback return the seeded row.
- `ConfirmChainLinkTest::whereJsonContains finds the signature on this SQLite build` re-exercises the same path through the production ConfirmChainLink action body and asserts the auto-promotion sweep actually fires when 3 same-signature confirms exist. PASS.
- `ChainLinkQueryTest::candidatesForReview computes confirmsRemaining via same-signature confirmed count` exercises the read-side counter via whereJsonContains. PASS.

If a future SQLite build regresses, swap the single `->whereJsonContains('evidence->signature_hash', $hash)` call in both `ConfirmChainLink::__invoke` and `ChainLinkQuery::makeChainLinkRow` for `->whereRaw("json_extract(evidence, '\$.signature_hash') = ?", [$hash])` — one-line change documented in the class bodies.

## PaypalFundingResolver shape — constants + signature_hash

```php
public const AMOUNT_BAND_PERCENT = 2;        // ±2% (CHN-02 fuzzy)
public const DATE_WINDOW_DAYS = 3;           // ±3 days (CHN-02 fuzzy)
public const FUZZY_MIN_CONFIDENCE = 0.6;     // D-85 floor
public const FUZZY_MAX_CONFIDENCE = 0.99;    // 1.0 reserved for deterministic
private const FUNDING_EVENT_TYPES = ['General Withdrawal', 'Bankstorting', 'Transfer to bank'];
private const IBAN_MEMO_KEYS = ['Naam', 'Memo', 'Note', 'Description', 'Omschrijving'];
private const FUZZY_WEIGHT_MERCHANT = 0.5;
private const FUZZY_WEIGHT_AMOUNT = 0.3;
private const FUZZY_WEIGHT_DATE = 0.2;
```

`signature_hash = hash('sha256', $normalisedMerchant.'|'.$fundingIban)` — same formula at both arms. The deterministic arm passes the matched IBAN from the event memo; the fuzzy arm passes the IBAN of the best-candidate transfer_in's account.

## ChainLinkQuery walk

```text
MAX_DEPTH = 5   // D-92 — payment topology is <=5 levels deep
AUTO_PROMOTE_THRESHOLD = 3  // D-87 — same-signature confirms before learning loop fires
```

Algorithm: BFS layers from root.id, filter chain_links to `state ∈ {confirmed, candidate}`, skip rows whose `to_transaction_id IS NULL` (issue #10), de-dup via visited-set, bound at depth=5. Each visited transaction is hydrated as a `ChainTreeNode` with the kind from the chain_link (or `'root'` for the seed) and the D-91 confidence tier.

## ConfirmChainLink auto-promotion (D-87/D-88)

```text
Promote target → confirmed (resolver preserved).
Read evidence.signature_hash.
Count confirmed rows of (user, state=confirmed, signature_hash) via whereJsonContains.
If count >= 3:
  Update every (user, state=candidate, signature_hash) row →
    state='confirmed', resolver='rule', updated_at=now.
```

The `resolver='rule'` distinction lets the UI render "Confirmed via learning loop" (rule) separately from "Confirmed by user" (auto → confirmed) — D-91.

## Test coverage

- **PaypalFundingResolverTest** (11 cases): deterministic D-106 + fuzzy CHN-02 in [0.6, 0.99] + sub-floor fuzzy dropped + deterministic preempts fuzzy + signature_hash format + D-84 invariant + cross-user + idempotency + rejected-pair stay-rejected + empty events[] + null raw_payload.
- **ChainLinkQueryTest** (10 cases): tree assembly + D-91 mapping + rejected filtered + NULL-to skipped + cross-user 404 + depth bound 5 + openCandidateCount + sort + confirmsRemaining + cursor pagination + isolation.
- **CardStatementQueryTest** (3 cases): null when none + most-recent open + cross-user.
- **ConfirmChainLinkTest** (5 cases): single confirm + auto-promotion threshold + below-threshold no-op + cross-user 404 + whereJsonContains live.
- **RejectChainLinkTest** (4 cases): per-pair only + signature counter neutral + does not trigger auto-promotion + cross-user 404.

**Totals: 34 test cases, 99 assertions, all green.**

Plus existing suites verified non-regressive:
- `BoundaryArchTest::noResolverWritesTransactions` — GREEN.
- `BoundaryArchTest::noOtherCardStatementStateMutator` — GREEN.
- `IcsSettlementResolverTest` — 9 cases GREEN (no change).
- `ChainLinksJsonContainsSmokeTest` — 2 cases GREEN.
- Full Chains testsuite: **89 passed, 352 assertions**.
- Full project parallel run: 679 passed, 5 skipped, 3 notices, 1 pre-existing failure (TransactionTypeTest — environment-shaped per Phase 4 deferred-items, unaffected by this wave).

## Deviations from Plan

### Action-Layer 404 Surface (Implementation choice — within plan's intent)

The plan's literal action body used Eloquent `firstOrFail()` for cross-user 404. `firstOrFail()` raises `ModelNotFoundException`, which the framework converts to `NotFoundHttpException` at the router boundary but Pest action tests cannot observe (the conversion happens in the HTTP kernel, not at the action layer). Adjusted both actions to:

```php
$link = ChainLink::query()->where('id', $id)->where('user_id', $user->id)->first();
if ($link === null) {
    throw new NotFoundHttpException('Chain link not found.');
}
```

Same end-user behaviour (HTTP 404), additionally testable from unit/feature tests. The cross-user 404 tests in both ConfirmChainLinkTest and RejectChainLinkTest assert `toThrow(NotFoundHttpException::class)` directly.

### `RejectChainLink` dropped DatabaseManager DI (Rule 2 — fix dead constructor property)

The plan's literal constructor signature for RejectChainLink injected `DatabaseManager $db`, but the action's only write path is `$link->save()` (Eloquent). The unused property would trip `phpstan-strict-rules`' `property.onlyWritten` lint, and there is no caller use case for raw query-builder writes in a per-pair reject. Dropped the injection; documented the rationale in the class docblock so a future batch-reject extension can DI it back when needed.

### `(float)` casts replaced with `toFloat()` helper (Rule 1 — PHPStan strict-rules `cast.double`)

`(float) $row->confidence` against a `mixed`-typed query-builder column trips PHPStan strict-rules' `cast.double`. Introduced `private static function toFloat(mixed $value): float` in ChainLinkQuery mirroring the existing `toInt` / `toString` helpers; same numeric-coercion pattern the IcsSettlementResolver already uses.

### `CardStatement::find()` swapped for `where('id', ...)->first()` (Rule 1 — Larastan return-type union)

Larastan types `CardStatement::query()->find($scalar)` as `Collection<int, CardStatement>|CardStatement|null`. The Collection variant fails the `?CardStatement` return type on `CardStatementQuery::openForAccount`. Swapped to `where('id', $id)->first()` which narrows cleanly to `?CardStatement`. Same behavior, satisfies PHPStan.

### Pre-existing failing test (out of scope — deferred to verifier)

`Modules/Ledger/tests/Unit/TransactionTypeTest::it rejects an invalid transaction type at the DB layer` continues to fail in this environment (Pest parallel-mode SQLite trigger handling — reproducible on `b57c0dd` before any Wave 3 change). Already logged under `.planning/phases/04-paypal-ingestion-transfer-detection/deferred-items.md`; out of scope per Wave 3's deviation rules.

## Auth Gates

None — Wave 3 is internal to the chain-resolution backend.

## Known Stubs

None. Wave 2's PaypalFundingResolver stub was the only known stub in the chain-resolution backend; this wave replaces it with the real algorithm. The ChainLinkQuery `ChainTreeNode::children` array is intentionally always `[]` in this wave (ICS bulk-settle fan-out renders via the flat node list — Wave 4 UI decides whether to nest); the empty `children` array is the canonical "no fan-out collapse" shape, not a stub.

## Self-Check: PASSED

- Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php (replaced — real algorithm): FOUND
- Modules/Chains/Public/Services/ChainLinkQuery.php (new): FOUND
- Modules/Chains/Public/Services/CardStatementQuery.php (new): FOUND
- Modules/Chains/Public/Actions/ConfirmChainLink.php (new): FOUND
- Modules/Chains/Public/Actions/RejectChainLink.php (new): FOUND
- Modules/Chains/Providers/ChainsServiceProvider.php (4 new singleton bindings): FOUND
- Modules/Chains/tests/Unit/Resolvers/PaypalFundingResolverTest.php (new — 11 cases): FOUND
- Modules/Chains/tests/Feature/ChainLinkQueryTest.php (new — 10 cases): FOUND
- Modules/Chains/tests/Feature/CardStatementQueryTest.php (new — 3 cases): FOUND
- Modules/Chains/tests/Feature/ConfirmChainLinkTest.php (new — 5 cases): FOUND
- Modules/Chains/tests/Feature/RejectChainLinkTest.php (new — 4 cases): FOUND
- Commits: ee890b7 (RED PaypalFundingResolverTest), a9311b4 (GREEN PaypalFundingResolver impl), 54a18a1 (RED Public-surface tests), d5f36bc (GREEN Public services + actions) — all FOUND in git log
- Acceptance grep `grep -RIn "->table('chain_links')->insert" Modules/Chains/Internal/Resolvers/`: zero matches (verified — issue #4 fix still holding)
- Larastan level 10 strict: NO ERRORS
- Pint: CLEAN
- BoundaryArchTest::noResolverWritesTransactions + ::noOtherCardStatementStateMutator: GREEN
