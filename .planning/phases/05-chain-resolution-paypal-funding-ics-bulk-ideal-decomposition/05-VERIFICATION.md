---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
verified: 2026-05-16T00:00:00Z
status: human_needed
score: 4/4 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Chain drawer Flux flyout keyboard ergonomics + visual polish"
    expected: "Esc closes the drawer; click-outside closes; Tab traps focus inside the modal; sticky header behaviour matches D-92 (no outer scroll); drawer renders at md:w-2xl; transitions feel calm."
    why_human: "First Flux flyout in the project (UI-02). Snapshot tests cover markup but not interactive keyboard/focus behavior or animation feel. Plan 05-05 frontmatter declared autonomous: false, deferring this verification to phase verification."
  - test: "/horizon dashboard accessibility under Loopback + Fortify auth"
    expected: "Start docker container `diederik-redis`; run `php artisan horizon` in a second terminal; visit http://diederik.test/horizon → supervisor reports online; `ResolveChainLinksJob` appears in the queue listing after triggering an import; Fortify session auth gate is enforced (anonymous visit redirects to /login)."
    why_human: "Requires a running Redis + Horizon process — HorizonBootsTest::it_connects_to_Redis_on_127_0_0_1_6379 and queue_config_defaults_to_redis_driver SKIP automatically when no live Redis is reachable (issue #9 explicit skip predicate). Validates D-101 + D-102 + the Loopback/Fortify carve-out documented in UI-SPEC § Registry Safety."
  - test: "End-to-end chain resolution against the user's real ASN + ICS + PayPal exports"
    expected: "Import latest ASN CAMT.053 + ICS PDF + PayPal CSV in chronological order; the wizard \"Resolving chains…\" surface transitions pending → running → complete; dashboard \"Next ICS settlement\" tile populates with EUR amount; drill into a Netflix-via-PayPal expense → drawer renders the deterministic chain back to ASN/ICS; ICS bulk-settle drawer shows the per-charge fan-out."
    why_human: "Synthesised fixture trio (D-107) covers algorithmic correctness end-to-end (verified by FixtureParseSmokeTest + IcsSettlementResolverTest + PaypalFundingResolverTest); the user's real data is the final smoke against unanticipated edge cases. VALIDATION.md § Manual-Only Verifications lists this as the explicit follow-up."
  - test: "Operator-recovery README snippet usability — stuck Redis unique-lock keys"
    expected: "Force a worker crash (Ctrl+C on `php artisan horizon` mid-run); the README's `docker exec diederik-redis redis-cli KEYS '*unique-lock:resolve-chain-links:*'` lists the stale key; `redis-cli DEL <key>` clears it; the next ConfirmImport dispatch succeeds and the wizard polling resumes."
    why_human: "README operator-recovery section is documentation; correctness requires a real Redis container + a real worker crash. VALIDATION.md § Manual-Only Verifications explicitly lists this as the issue #14 follow-up."
---

# Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) Verification Report

**Phase Goal:** User can see exactly where every charge came from — PayPal payments traced back to the funding card or account, and the monthly ASN → ICS lump-sum iDEAL settlement decomposed into the individual ICS card transactions it covers.

**Verified:** 2026-05-16
**Status:** human_needed (4 / 4 success criteria delivered in code; 4 explicit manual checkpoints remain for keyboard/UI feel + live Redis + real-data smoke + operator recovery)
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | User opens a Netflix-via-PayPal transaction and sees the full chain tree back to the ASN/ICS account that ultimately funded it | VERIFIED | TransactionDetail "View chain" button → ChainDrawer mounts via `#[On('chain-drawer:open')]` → `ChainLinkQuery::forTransaction()` walks `chain_links` BFS bounded at MAX_DEPTH=5 with visited-set cycle guard → drawer renders three-tier confidence chip per leg per D-91. 11 ChainLinkQueryTest cases + 13 ChainDrawerTest cases all GREEN. |
| 2 | Bulk iDEAL settlement decomposed within ±€5 / ±2% / ±10-day tolerances | VERIFIED | `IcsSettlementResolver` defines `AMOUNT_TOLERANCE_MINOR=500`, `AMOUNT_TOLERANCE_PERCENT=2`, `PERIOD_WINDOW_DAYS=10`. `resolveOne()` computes delta as `-expenseSum - priorCredits - settled`, applies `max(amount, percent)` tolerance arm, writes N confirmed `chain_links` on success or 1 candidate row with `tolerance_used='exceeded'` on failure. Refund-after-close pass (D-98) emits `card_statement_credits` rows. 9 IcsSettlementResolverTest cases cover all 4 tolerance arms + idempotency + cross-user + refund. |
| 3 | Review queue + auto-promote after 3 confirms; reject is per-pair | VERIFIED | `/chains/review` Livewire SFC sorts by `confidence DESC, id DESC` cursor-paginated. `ConfirmChainLink` transactionally promotes target row, then COUNTs same-signature confirmed rows via `whereJsonContains('evidence->signature_hash', $hash)`, and updates remaining same-signature candidates to `state=confirmed, resolver=rule` when count ≥ 3. `RejectChainLink` flips state only (D-89). 13 ChainReviewQueueTest + 5 ConfirmChainLinkTest (incl. auto-promotion + below-threshold cases) + 4 RejectChainLinkTest + 8 CrossUserChainLinkIsolationTest cases all GREEN. |
| 4 | Next forecasted ICS settlement visible on dashboard before paying | VERIFIED | `ThisPeriodAtAGlanceQuery::nextIcsSettlement(User)` joins `card_statements` to `accounts` on `kind='ics_card'`, filters `state IN (open, partially_settled)`, returns most-recent `period_end` as `CardStatementForecastTile{amount, dueDate=period_end+5d, statementId, state}`. Dashboard.blade.php renders the tile when non-null; hides entirely when null (no `—` placeholder). 9 NextIcsSettlementTileTest cases (incl. multi-statement + cross-user + null-state tile-hiding) all GREEN. |

**Score:** 4 / 4 success criteria verified

### Required Artifacts (Phase 5 surfaces)

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Chains/composer.json` + `ChainsServiceProvider` | Module skeleton registered, autoload-dev extended | VERIFIED | Module loads; SmokeTest passes; routes registered. |
| `Modules/Chains/Database/Migrations/*_create_chain_links_table.php` | `chain_links` schema per D-82 (id, user_id, from_tx, to_tx, kind, state, confidence(4,3), resolver, evidence(json)) with kind+state CHECK triggers and conditional-NULL trigger | VERIFIED | `paypal_funding` / `ics_bulk_settle` enum enforced; NULL `to_transaction_id` legal only for exceeded-tolerance ics_bulk_settle candidates (D-83 + issue #10). ChainLinksSchemaTest GREEN. |
| `Modules/Chains/Database/Migrations/*_create_card_statements_table.php` | First-class statement lifecycle ledger (D-94) | VERIFIED | period_start, period_end, total_amount_minor (signed), open_balance_minor, state (open/partially_settled/settled/overpaid), UNIQUE (user_id, account_id, period_start, period_end), state CHECK triggers. |
| `Modules/Chains/Database/Migrations/*_create_card_statement_credits_table.php` | Carry-forward credits per D-96 / D-98 | VERIFIED | from_statement_id, to_statement_id (nullable), amount_minor, reason ('overpayment' / 'refund_after_close'). |
| `Modules/Chains/Database/Migrations/*_backpopulate_card_statements_from_statement_summaries.php` | One-shot back-population per D-94 | VERIFIED | Idempotent via insertOrIgnore against the UNIQUE constraint. CardStatementBackPopulationTest GREEN. |
| `Modules/Chains/Database/Migrations/*_create_chain_resolution_runs_table.php` | Audit table for wizard polling + failed-job toast (issue #1 + #8) | VERIFIED | status CHECK trigger pair ('pending' / 'running' / 'complete' / 'failed'); job_uuid; linked_count; last_error; started_at/completed_at timestamps. ChainResolutionRunsSchemaTest + ChainResolutionRunsLifecycleTest GREEN. |
| `Modules/Chains/Models/{ChainLink,CardStatement,CardStatementCredit,ChainResolutionRun}.php` | Eloquent models | VERIFIED | All present; ChainLink casts `evidence` as array. |
| `Modules/Chains/Public/Dto/*` | ChainTree, ChainTreeNode, ChainLinkRow, CardStatementForecastTile, StatementSettlement | VERIFIED | spatie/laravel-data Data classes; immutable + typed. |
| `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` | Bulk-settle decomposition + refund-after-close per D-97 / D-98 | VERIFIED | Clean / over / under / exceeded-tolerance / refund arms all present; idempotent; cross-user safe; signature_hash for D-88 auto-promotion. |
| `Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php` | Deterministic (D-106) + fuzzy (CHN-02) | VERIFIED | Deterministic via IBAN extraction from raw_payload events[]; fuzzy weighted 0.5/0.3/0.2 merchant/amount/date via levenshtein, clamped to [0.6, 0.99] so 1.0 stays deterministic-only. 11 PaypalFundingResolverTest cases GREEN. |
| `Modules/Chains/Internal/CardStatementStateMachine.php` | D-95 sole mutator of card_statements.state | VERIFIED | `applySettlement()` wraps the SELECT-then-UPDATE in a single connection transaction with PRAGMA busy_timeout=5000 for SQLite cross-writer wait. BoundaryArchTest::noOtherCardStatementStateMutator GREEN. |
| `Modules/Chains/Internal/ChainLinkInsertHelper.php` | Single INSERT path (issue #4 lock) | VERIFIED | Pre-insert (from, to, kind, user) uniqueness guard; consistent JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES encoding; NULL-endpoint handling via whereNull(). `grep -RIn "->table('chain_links')->insert" Modules/Chains/Internal/Resolvers` returns no results. |
| `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | Queued + ShouldBeUniqueUntilProcessing + tries=3 + backoff [60,300,900] | VERIFIED | uniqueId=userId; uniqueFor=600; uniqueVia=Cache::driver('redis') (single facade carve-out, allow-listed in BoundaryArchTest); inserts running row, transitions to complete with linked_count = afterCount - beforeCount. 9 ResolveChainLinksJobTest cases GREEN. |
| `Modules/Chains/Public/Services/ChainLinkQuery.php` | forTransaction walks chain bounded at depth 5; openCandidateCount; candidatesForReview cursor-paginated | VERIFIED | 11 ChainLinkQueryTest cases incl. depth-5 bound + NULL-to skip + D-91 mapping + confirmsRemaining via whereJsonContains. |
| `Modules/Chains/Public/Services/CardStatementQuery.php` | openForAccount read API | VERIFIED | 3 CardStatementQueryTest cases. |
| `Modules/Chains/Public/Actions/ConfirmChainLink.php` | D-87 auto-promotion threshold=3 + transactional safety | VERIFIED | Wrap targeted-row save + auto-promote sweep in one transaction; whereJsonContains live; auto-promotion fires when confirmed count ≥ 3, marks remaining same-signature candidates `state=confirmed, resolver=rule`. |
| `Modules/Chains/Public/Actions/RejectChainLink.php` | D-89 per-pair only; signature counter neutral | VERIFIED | Single Eloquent save(); no auto-promotion trigger from rejects. |
| `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` | UI-02 + CHN-04 Flux flyout, fan-out children attach, Confirm/Reject inline | VERIFIED | `#[On('chain-drawer:open')]` listener; attachFanoutChildren rebuilds ICS bulk-settle fan-out tree with ≥2-children-required filter (D-93). 13 ChainDrawerTest cases GREEN. |
| `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` | /chains/review page (CHN-03 / D-86 / D-87) | VERIFIED | Cursor pagination via (confidence, id) lexicographic order. ChainReviewQueueTest GREEN. |
| `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` + `partials/chain-node.blade.php` | First flux:modal flyout in project, @props declared (issue #13) | VERIFIED | `<flux:modal flyout position="right" class="md:w-2xl">` mounted; sticky header carries `sticky top-0 bg-white z-10`; chain-node partial declares `@props(['node', 'fanoutPage'])` explicitly. |
| `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php` | /chains/review page Blade | VERIFIED | Page heading "Review chains" + subheading + Confirm/Reject buttons + auto-promotion hint copy. |
| `Modules/Chains/Routes/web.php` | /chains/review route, auth-gated | VERIFIED | `Route::middleware(['web', 'auth'])` group with named `chains.review`. |
| `Modules/Chains/Public/Contracts/DispatchesChainResolution.php` + `Internal/Services/BusChainResolutionDispatcher.php` | Cross-module dispatch contract | VERIFIED | `dispatchForUser(int $userId)` interface; concrete BusChainResolutionDispatcher binds in ServiceProvider as singleton. |
| `Modules/Transfers/Public/Services/PairLookup.php` | D-110 promotion: isPaired + partnerId | VERIFIED | Cross-user safe; PairLookupTest covers all 6 cases. |
| `tests/Contracts/BoundaryArchTest.php` extensions | D-84 + D-95 + Cache facade carve-out | VERIFIED | `noResolverWritesTransactions` + `noOtherCardStatementStateMutator` + facade-allow-list for `ResolveChainLinksJob` all present and GREEN. |
| `tests/Feature/HorizonBootsTest.php` | Explicit skip predicate (issue #9) | VERIFIED | Skips with `->skip(fn() => !isRedisReachable(...), 'Redis container required...')`; HorizonBoots service-provider test runs without skip. |
| `Modules/Chains/tests/fixtures/scenario-1/` | Synthesised cross-source fixture trio (D-107 / D-108) | VERIFIED | Clean + overpaid + underpaid scenarios committed; `scripts/synthesise_phase5_scenario.php` generator present. FixtureParseSmokeTest exercises both adapters against fixtures. |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php` "Next ICS settlement" tile + failed-job toast | D-99 / D-100 + D-103 | VERIFIED | Tile renders amount + due date; hides entirely on null. Failed-job toast persistent, polls `chain_resolution_runs` (issue #1 + #8). |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php` "Review chains" link + count badge | UI-SPEC top-nav | VERIFIED | Link inserts between "Uncategorized" and "Settings"; badge via View Factory composer in ChainsServiceProvider (issue #12 fix — no view() global helper). |
| `Modules/Import/Public/Actions/ConfirmImport.php` post-commit dispatch | D-103 / RESEARCH Pitfall 3 | VERIFIED | Dispatch happens AFTER the closure returns; gated on `inserted > 0 || enriched > 0`; pending audit row inserted before dispatch so wire:poll has a row on first tick. |
| `Modules/Import/Internal/Http/Livewire/PreviewWizard.php` polling | wire:poll.2s against chain_resolution_runs | VERIFIED | Exact user_id match — no failed_jobs.payload LIKE substring (issue #1 + #8 lock verified by WizardChainResolutionStatusTest grep gate). Auto-redirects to imports.results on `complete`. |
| `.planning/PROJECT.md` Phase 5 amendment | D-101 + D-102 atomic edit (Horizon + Redis flip; Docker-for-Redis carve-out) | VERIFIED | "Stack additions (Phase 5)" section present; `diederik-redis` named container documented; loopback-bind invariant + named-volume persistence explicit. |
| `README.md` setup section + `## Operator recovery` | issue #14 + D-102 | VERIFIED | Docker run snippet with `127.0.0.1:6379` loopback bind + named volume; manual `php artisan horizon` second-terminal note; Operator recovery section documents stuck Redis unique-lock-key clearing. |
| `composer.json` deps | laravel/horizon + predis/predis | VERIFIED | `laravel/horizon ^5.46`, `predis/predis ^3.4`. |
| `.env.example` + `config/database.php` | Loopback-bound (D-102) | VERIFIED | `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`; database.php default host = 127.0.0.1 (NOT 0.0.0.0). |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| TransactionDetail page | ChainDrawer Livewire SFC | `wire:click="$dispatch('chain-drawer:open', { transactionId })"` | WIRED | `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php:132-133` dispatches both Alpine `modal-show` and Livewire `chain-drawer:open` events; `ChainDrawer::open()` declared `#[On('chain-drawer:open')]`. |
| ChainDrawer | ChainLinkQuery::forTransaction | DI on render() | WIRED | Constructor-free Livewire SFC; service injected on render(); query returns ChainTree with BFS walk + visited-set cycle guard. |
| ChainDrawer Confirm/Reject buttons | ConfirmChainLink / RejectChainLink Public actions | DI on action methods (D-86 dual-surface) | WIRED | Both action methods invoke `($action)(int, User)` against the Public action class — identical call shape as /chains/review page. |
| /chains/review page | ChainLinkQuery::candidatesForReview | DI on render() | WIRED | Cursor-paginated reads; loadMore action advances `(cursorId, cursorConfidence)`. |
| ConfirmImport | DispatchesChainResolution → ResolveChainLinksJob | Post-commit dispatch via Public contract | WIRED | DI on ConfirmImport constructor; dispatch fires AFTER the outer DB transaction returns (RESEARCH Pitfall 3); gated on inserted>0 OR enriched>0; pending audit row written first so wire:poll has a row on tick 1. |
| ResolveChainLinksJob.handle() | IcsSettlementResolver + PaypalFundingResolver | DI on handle() | WIRED | Both resolvers invoked sequentially; chain_resolution_runs row transitions running → complete with linked_count delta. |
| IcsSettlementResolver | CardStatementStateMachine (sole D-95 mutator) | DI | WIRED | `$this->stateMachine->applySettlement($statementId, $settled, $user)` is the only path that mutates card_statements.state; arch test forbids any other path. |
| ConfirmChainLink auto-promotion | chain_links updates via whereJsonContains | Transactional sweep | WIRED | ChainLinksJsonContainsSmokeTest verifies JSON1 path live on SQLite; ConfirmChainLinkTest exercises the 3-confirm threshold + below-threshold no-op. |
| Dashboard | ThisPeriodAtAGlanceQuery::nextIcsSettlement | DI on render() + wire:poll.5s | WIRED | Tile renders when non-null; hides entirely on null (Dashboard renders the Next ICS settlement tile when nextIcsSettlement returns non-null + Dashboard hides the Next ICS settlement tile when nextIcsSettlement returns null tests both GREEN). |
| Dashboard | chain_resolution_runs (failed-job toast) | wire:poll.5s `refreshFailedChainResolution` | WIRED | Exact user_id match (issue #8 substring-attack guard verified). |
| Top-nav `<a>` "Review chains" | ChainLinkQuery::openCandidateCount | View Factory composer (issue #12) | WIRED | `$this->app->make(ViewFactoryContract::class)->composer('core::livewire.top-nav', ...)` injects `chainOpenCandidateCount`. No `view()` global helper used. |
| PreviewWizard polling | chain_resolution_runs | wire:poll.2s `refreshChainResolutionStatus` | WIRED | Exact user_id match; auto-redirects to imports.results on `complete`. |
| JobFailed event | chain_resolution_runs status='failed' | Dispatcher::listen in ServiceProvider | WIRED | ChainResolutionRunsLifecycleTest::transitions_chain_resolution_runs_to_failed_when_the_JobFailed_event_fires GREEN. |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|---------------------|--------|
| ChainDrawer (`chain-drawer.blade.php`) | `$tree` (ChainTree) | `ChainLinkQuery::forTransaction()` against `chain_links` joined to `transactions` + `accounts` (BFS walk to depth 5) | Yes — real DB read filtered by user_id; ChainDrawerTest exercises 0/1/3/N-leg + fan-out + candidate paths against live SQLite | FLOWING |
| ChainReviewQueue (`chain-review-queue.blade.php`) | `$candidates` (list<ChainLinkRow>) | `ChainLinkQuery::candidatesForReview()` cursor-paginated read | Yes — sorted by confidence DESC then id DESC; ChainReviewQueueTest verifies the sort + cursor pagination | FLOWING |
| Dashboard "Next ICS settlement" tile | `$nextSettlement` (?CardStatementForecastTile) | `ThisPeriodAtAGlanceQuery::nextIcsSettlement()` joining `card_statements` to `accounts` on kind=`ics_card` | Yes — real DB read; NextIcsSettlementTileTest covers populated + null states; tile hides entirely on null (verified) | FLOWING |
| Dashboard failed-job toast | `$failedChainResolutionExists` (bool) | `chain_resolution_runs` exact user_id match | Yes — populated on mount + refreshed via wire:poll.5s; cross-user attack-substring guarded | FLOWING |
| PreviewWizard "Resolving chains…" surface | `$chainResolutionStatus`, `$chainResolutionLinkedCount`, `$chainResolutionError` | `chain_resolution_runs` row by user_id | Yes — exact user_id match; auto-navigates on status=complete | FLOWING |
| Top-nav "Review chains" badge | `$chainOpenCandidateCount` (int) | `ChainLinkQuery::openCandidateCount()` count of state=candidate rows | Yes — View Factory composer injection runs per top-nav render | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 5 quick filter passes | `vendor/bin/pest --filter "Chains\|PairLookup\|...\|CardStatementQuery"` | 159 passed, 2 skipped, 0 failed (8.48s) | PASS |
| BoundaryArchTest passes (incl. D-84 + D-95 + facade carve-out) | `vendor/bin/pest --filter "BoundaryArchTest\|noResolverWritesTransactions\|noOtherCardStatementStateMutator"` | 13 passed, 35 assertions (2.46s) | PASS |
| IcsSettlementResolverTest all tolerance arms | `vendor/bin/pest --filter "IcsSettlementResolverTest"` | 9 passed, 87 assertions; covers clean/over/under/exceeded + refund + idempotency + D-84 invariant | PASS |
| PaypalFundingResolverTest deterministic + fuzzy | `vendor/bin/pest --filter "PaypalFundingResolverTest"` | 11 passed, 38 assertions | PASS |
| Full project suite | `vendor/bin/pest` | 733 passed, 5 skipped, 1 failed (TransactionTypeTest — deferred / pre-existing Phase 1 regression, out-of-scope per deferred-items.md) | PASS (Phase 5 scope) |
| Larastan level 10 strict | `composer analyse` | 199 / 199 files, 0 errors | PASS |
| Pint format check | `composer format:check` | `{"tool":"pint","result":"passed"}` | PASS |
| Issue #4 lock (no raw chain_links insert in Resolvers) | `grep -RIn "->table('chain_links')->insert" Modules/Chains/Internal/Resolvers` | (empty output — no hits) | PASS |
| Issue #8 lock (no payload LIKE userId in production code) | `grep -rE "payload.+LIKE.+userId" Modules/` | Hits only in docblocks / test assertions / regression-test grep patterns | PASS |
| Issue #12 lock (no view() helper in Chains production) | `grep -RIn "view()->" Modules/Chains/ \| grep -v Test.php` | Hits only in docblock explaining the fix | PASS |
| Issue #14 README operator recovery snippet present | `grep -q '^## Operator recovery' README.md && grep -q 'unique-lock:resolve-chain-links' README.md` | Match | PASS |
| D-101 PROJECT.md amendment present | `grep -q '^### Stack additions (Phase 5)' .planning/PROJECT.md` | Match | PASS |
| D-102 loopback bind (NOT 0.0.0.0) | `grep -E "REDIS_HOST=" .env.example` + `grep "127.0.0.1" config/database.php` | `REDIS_HOST=127.0.0.1`; database.php redis.default.host defaults to `127.0.0.1` | PASS |
| Horizon + Predis installed | `grep -E "horizon\|predis" composer.json` | `laravel/horizon: ^5.46`, `predis/predis: ^3.4` | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| CHN-01 | 05-04 | When a PayPal charge has a matching reference ID present in an ASN/ICS line, deterministically link | SATISFIED | `PaypalFundingResolver::deterministicMatch()` — IBAN-from-memo extraction + equal-and-opposite transfer_in match in ±3-day window; confidence=1.0, resolver=auto. PaypalFundingResolverTest::deterministic_match_PayPal_Bankstorting_→_ASN_by_IBAN-in-memo GREEN. |
| CHN-02 | 05-04 | When no reference ID match exists, propose candidates by merchant + amount + date window with confidence | SATISFIED | `PaypalFundingResolver::fuzzyMatch()` — Levenshtein merchant similarity (0.5) + amount-band (0.3) + date-window (0.2) weighted sum; clamped to [0.6, 0.99]. Sub-0.6 candidates dropped. |
| CHN-03 | 05-04 / 05-05b | Review queue + per-merchant memory training future auto-matches | SATISFIED | `/chains/review` page + ConfirmChainLink auto-promotion threshold=3 same-signature confirms. signature_hash = sha256(normalized_merchant + '\|' + funding_account_iban). ConfirmChainLinkTest::auto-promotes_other_same-signature_candidates_at_the_3rd_confirm GREEN. |
| CHN-04 | 05-05 | Open any transaction → see full chain tree (Netflix → PayPal → ICS → ASN-bulk → ASN balance) | SATISFIED | TransactionDetail "View chain" button → ChainDrawer Flux flyout → top-down waterfall with three-tier confidence chip per leg; bulk-settle fan-out renders nested list. |
| CHN-05 | 05-02 / 05-03 | Bulk iDEAL decomposed with ±€5 / ±2% / ±10-day tolerance, partial / over / carry-forward | SATISFIED | IcsSettlementResolver constants + algorithm + CardStatementStateMachine lifecycle (open → partially_settled → settled / overpaid) + card_statement_credits overpayment + refund-after-close. |
| CHN-06 | 05-02 / 05-05b | Next forecasted ICS settlement amount before paying | SATISFIED | `ThisPeriodAtAGlanceQuery::nextIcsSettlement()` + dashboard tile. Forecast = open_balance_minor of most-recent open / partially_settled statement (D-100); lag = period_end + 5 days. |
| CHN-07 | 05-02 | chain_links table with state + confidence + evidence | SATISFIED | chain_links schema + Eloquent model + DTOs. Confidence stored as decimal(4,3); state CHECK trigger pair enforced. |
| UI-02 | 05-05 | From any transaction, drill into full funding chain | SATISFIED | Same as CHN-04 — chain drawer is the project's first Flux flyout; UI-SPEC pass locked drawer ergonomics. |

All 8 requirement IDs declared for Phase 5 are satisfied. No orphaned requirements detected — REQUIREMENTS.md status table already marks all 8 as "Complete".

### Anti-Patterns Found

None blocking.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | — | INFO | Anti-pattern scan of `Modules/Chains/**/*.php` returned no TODO / FIXME / placeholder / stub returns. Resolver paths, action classes, queries, Livewire SFCs, and Blade views all return real data flowing through the wiring. |

The one pre-existing failure noted in `deferred-items.md` (`Modules/Ledger/tests/Unit/TransactionTypeTest.php:74`) is a Phase 1 trigger-pair regression unrelated to Phase 5 scope — it failed identically on commit `75ebef4` (3 commits before any 05-05b changes). Out of scope for Phase 5 verification; recommended owner is a separate Ledger plan auditing the `transactions.type` BEFORE-INSERT trigger.

### Locked Decisions Traceability (D-82 .. D-110)

| Decision | Trace | Status |
|----------|-------|--------|
| D-82 | chain_links table schema | VERIFIED — migration `2026_05_16_010001` |
| D-83 | kind enum {paypal_funding, ics_bulk_settle} | VERIFIED — trigger pair enforces, no other kinds allowed |
| D-84 | Resolver writes chain_links only | VERIFIED — BoundaryArchTest::noResolverWritesTransactions GREEN |
| D-85 | Confidence tiers 1.0 / 0.6-0.99 / <0.6 dropped | VERIFIED — PaypalFundingResolver FUZZY_MIN_CONFIDENCE=0.6, FUZZY_MAX_CONFIDENCE=0.99; ChainLinkQuery::confidenceTier() maps to chips |
| D-86 | Dual review surface (page + inline) sharing action classes | VERIFIED — ChainDrawer + ChainReviewQueue both invoke same Public actions |
| D-87 | Auto-promotion threshold = 3 | VERIFIED — ConfirmChainLink::AUTO_PROMOTE_THRESHOLD constant + ConfirmChainLinkTest 3-confirm case |
| D-88 | Signature = sha256(normalized_merchant \| funding_iban) | VERIFIED — both resolvers compute identically; ConfirmChainLink reads via whereJsonContains |
| D-89 | Reject per-pair only; signature neutral | VERIFIED — RejectChainLinkTest::leaves_signature_counter_neutral GREEN |
| D-90 | Vertical waterfall side-drawer modal | VERIFIED — chain-drawer.blade.php Flux flyout, vertical card stack |
| D-91 | Three-tier confidence chip (no hue) | VERIFIED — chain-node.blade.php tier classes use no hue ramps |
| D-92 | Drawer renders fully-expanded by default | VERIFIED — ChainDrawer::$collapsedLegs starts empty; reset on open |
| D-93 | Fan-out paginates inside container (no outer drawer scroll) | VERIFIED — partials/chain-node.blade.php `pageSize=10` + "Show N more · X of N" affordance |
| D-94 | card_statements first-class entity back-populated | VERIFIED — back-population migration + CardStatementBackPopulationTest |
| D-95 | CardStatementStateMachine sole mutator | VERIFIED — BoundaryArchTest::noOtherCardStatementStateMutator GREEN |
| D-96 | Overpayment surplus = virtual credit_carry to next statement | VERIFIED — IcsSettlementResolver writes card_statement_credits row with reason='overpayment' |
| D-97 | ±€5 OR ±2% across ±10-day tolerance | VERIFIED — constants in IcsSettlementResolver match exactly |
| D-98 | Refund-after-statement-close → chain back + carry-forward credit | VERIFIED — IcsSettlementResolver::resolveRefundsAfterClose + IcsSettlementResolverTest::handles_refund-after-close GREEN |
| D-99 | "Next ICS settlement" tile on dashboard | VERIFIED — Dashboard.blade.php section conditional on $nextSettlement |
| D-100 | Forecast amount = open_balance_minor; lag = +5 days | VERIFIED — ThisPeriodAtAGlanceQuery::nextIcsSettlement returns CardStatementForecastTile with dueDate=period_end->addDays(5) |
| D-101 | Async via Horizon + Redis (PROJECT.md override) | VERIFIED — composer.json deps + PROJECT.md amendment + ResolveChainLinksJob.uniqueVia()=Cache::driver('redis') |
| D-102 | Redis as Docker container (network-only, loopback-bound) | VERIFIED — README + .env.example + config/database.php all bind 127.0.0.1; named-volume persistence; no bind mounts |
| D-103 | ResolveChainLinksJob with ShouldBeUniqueUntilProcessing keyed on user_id | VERIFIED — uniqueId() returns userId; tries=3; backoff=[60,300,900]; ResolveChainLinksJobTest covers per-user uniqueness |
| D-104 | Full-user re-scan over all open/partially_settled statements + unresolved transactions | VERIFIED — IcsSettlementResolver candidate-transfers query (left-join on chain_links) + PaypalFundingResolver same pattern |
| D-105 | wire:poll.2s on wizard "Resolving chains…" surface | VERIFIED — preview-wizard.blade.php `wire:poll.2s="refreshChainResolutionStatus"` |
| D-106 | PayPal NL "General Withdrawal" close-out | VERIFIED — PaypalFundingResolver::FUNDING_EVENT_TYPES = ['General Withdrawal', 'Bankstorting', 'Transfer to bank']; PaypalFundingResolverTest::deterministic_match_PayPal_Bankstorting_→_ASN_by_IBAN-in-memo GREEN |
| D-107 | Synthesised cross-source fixture trio | VERIFIED — Modules/Chains/tests/fixtures/scenario-1/ with clean + overpaid + underpaid scenarios |
| D-108 | scripts/synthesise_phase5_scenario.php in-repo generator | VERIFIED — script present, composer-dep-free |
| D-109 | Modules/Chains/ bounded module with Public/Internal split | VERIFIED — module composer.json + ServiceProvider + Public/Internal directory contract |
| D-110 | Modules/Transfers/Public/Services/PairLookup promotion | VERIFIED — PairLookupTest GREEN; isPaired + partnerId both delivered |

All 29 locked decisions are traceable to implemented code or rejected/deferred per CONTEXT.md.

### Cross-Cutting Invariants

| Invariant | Status | Evidence |
|-----------|--------|----------|
| D-84 BoundaryArchTest noResolverWritesTransactions | GREEN | Regex enforces no `Transaction::query` / `Transaction::where` / raw `->table('transactions')->update/insert/delete` in Resolvers folder |
| D-95 BoundaryArchTest noOtherCardStatementStateMutator | GREEN | Regex enforces only CardStatementStateMachine.php may UPDATE card_statements.state |
| Cache facade carve-out (single permitted facade) | GREEN | BoundaryArchTest::no_Laravel_facade_usage_in_module_code allow-lists `Modules\Chains\Internal\Jobs\ResolveChainLinksJob` exactly |
| D-101 Horizon + Predis installed + PROJECT.md amended | GREEN | composer.json deps + PROJECT.md "Stack additions (Phase 5)" + README setup snippet |
| D-102 Redis loopback-bound (127.0.0.1, NOT 0.0.0.0) | GREEN | .env.example, config/database.php redis.default.host default = 127.0.0.1; README docker run binds 127.0.0.1:6379 |
| Issue #4 lock: no raw chain_links insert in Modules/Chains/Internal/Resolvers | GREEN | grep returns empty; both resolvers route through ChainLinkInsertHelper |
| Issue #8 lock: no `payload LIKE '%userId%'` substring patterns in production code | GREEN | All hits are docblock / test-regression references; PreviewWizard + Dashboard query by exact user_id |
| Issue #12 lock: no `view()->composer(...)` global helper | GREEN | ChainsServiceProvider uses `$this->app->make(ViewFactoryContract::class)` |
| Issue #13 lock: chain-node partial declares explicit @props | GREEN | `@props(['node', 'fanoutPage'])` at top of partial |
| Cross-user isolation (FND-03) | GREEN | CrossUserChainLinkIsolationTest (8 cases) + every Public query/action filters `where('user_id', $user->id)` first |
| Idempotency (re-running resolver produces zero new chain_links) | GREEN | ChainResolutionIdempotencyTest (2 cases) + ChainLinkInsertHelper pre-insert pair-uniqueness guard |

### Deferred Items (Step 9b filter)

No Phase 5 gap was deferred to a later milestone phase. The only deferral surfaced during execution is the pre-existing `TransactionTypeTest` failure (Phase 1 trigger regression unrelated to Phase 5 scope), already documented in `deferred-items.md` and outside the Phase 5 verification scope.

The 4 manual checkpoints in `human_verification` are NOT deferrals — they are explicit `autonomous: false` carve-outs declared in plans 05-05 and 05-05b and reaffirmed in VALIDATION.md § Manual-Only Verifications. Goal-backward verification still confirms all 4 success criteria are delivered in code; the manual checks validate keyboard/visual feel + live external services + real-data alignment.

---

## Human Verification Required

### 1. Chain drawer Flux flyout keyboard ergonomics + visual polish

**Test:** Open `/transactions/{id}` for a transaction with a resolved chain → click "View chain" → confirm the drawer opens at `md:w-2xl` on the right; sticky header stays visible during waterfall scroll; press `Escape` → drawer closes; reopen and click outside the drawer → drawer closes; reopen and Tab cycles within the modal (focus trap engaged); fan-out paginates via "Show 10 more · X of N" without scrolling the outer drawer.

**Expected:** All five behaviours match D-90 / D-92 / D-93 — calm, no jitter, no focus escapes the modal, the body never gains an outer scroll.

**Why human:** First Flux flyout in the project; ChainDrawerTest covers markup but not keyboard or focus behaviour. UI-SPEC § Registry Safety + plans 05-05 frontmatter (`autonomous: false`) explicitly route this to phase verification.

### 2. /horizon dashboard accessibility under Loopback + Fortify auth

**Test:** Start the Redis container with `docker run --name diederik-redis -p 127.0.0.1:6379:6379 -v diederik-redis-data:/data -d redis:7-alpine redis-server --save 60 1`; run `php artisan horizon` in a second terminal; visit `http://diederik.test/horizon` as an authenticated user; confirm the dashboard shows a single supervisor online and the `ResolveChainLinksJob` queue. Log out → visit `/horizon` anonymously → confirm Fortify redirects to `/login`.

**Expected:** Supervisor reports online; queue depth visible; failed-jobs surface accessible; Fortify session gate enforced.

**Why human:** Requires running Redis + Horizon worker. HorizonBootsTest skips both Redis-reachability cases automatically (issue #9 explicit skip predicate verified — no skipOnFailure). VALIDATION.md § Manual-Only Verifications lists this as the operational gate.

### 3. End-to-end chain resolution against the user's real ASN + ICS + PayPal exports

**Test:** Import the most recent ASN CAMT.053 + ICS PDF + PayPal CSV (in chronological order) via the wizard. Observe: wizard "Resolving chains…" surface transitions pending → running → complete; dashboard "Next ICS settlement" tile populates; drill into a Netflix-via-PayPal expense → drawer renders the deterministic chain back to ASN or ICS; drill into a recent ASN→ICS settlement transaction → drawer shows the per-charge fan-out for that statement.

**Expected:** All four flows succeed against real data; chain confidence chips render Deterministic for IBAN-matched legs; bulk-settle fan-out shows the correct N charges and total.

**Why human:** Synthesised fixture trio (D-107) covers algorithmic correctness; real-data alignment is the final smoke against unanticipated edge cases (unusual PayPal payload event shapes, ICS settlement at the exact ±€5 / ±2% boundary, refund-after-close timing).

### 4. Operator-recovery snippet usability — stuck Redis unique-lock keys

**Test:** Trigger a `ResolveChainLinksJob` via a real ConfirmImport; while the worker is mid-handle(), force a worker crash (Ctrl+C on `php artisan horizon`). In a third terminal run the README's `docker exec diederik-redis redis-cli KEYS '*unique-lock:resolve-chain-links:*'` — confirm the stale unique-lock key shows; run `docker exec diederik-redis redis-cli DEL <key>` — confirm deletion; trigger a fresh ConfirmImport for the same user — confirm the next dispatch succeeds (the wizard's `wire:poll.2s` reaches `running` then `complete`).

**Expected:** Stuck-lock cleanup recipe in README works as-written.

**Why human:** Requires a real crashed worker + live Redis. issue #14 fix + VALIDATION.md § Manual-Only Verifications explicit follow-up.

---

## Gaps Summary

**No code gaps.** All 4 ROADMAP success criteria are delivered with full data-flow tracing, all 8 REQ IDs satisfied with passing tests, all 29 D-XX decisions traceable, all cross-cutting invariants (D-84, D-95, Cache carve-out, issues #4 / #8 / #12 / #13 / #14) green.

The phase reaches `human_needed` status (not `passed`) because plans 05-05 and 05-05b explicitly declared `autonomous: false` for UI-feel + operational verification — those four checkpoints are owned by the human verifier, not the autonomous executor. The codebase substantiates every claim made in the SUMMARY.md narratives, including the 159 / 159 chain-suite pass + 13 / 13 BoundaryArchTest invariants + Larastan level 10 strict 199/199 / 0 errors + Pint clean.

**One pre-existing, out-of-scope test failure** (`Modules/Ledger/tests/Unit/TransactionTypeTest.php:74`) remains in the full suite; verified pre-existing at commit `75ebef4` (three commits before any 05-05b work), documented in `deferred-items.md`, recommended owner is a separate Ledger trigger-pair audit plan. Does not affect Phase 5 verification.

---

_Verified: 2026-05-16_
_Verifier: Claude (gsd-verifier)_
