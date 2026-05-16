---
phase: 5
slug: chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
status: ready
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-16
updated: 2026-05-16
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `05-RESEARCH.md` § "Validation Architecture" + the concrete plans `05-{NN}-PLAN.md`.
>
> `wave_0_complete` stays `false` until 05-01 + 05-01b actually execute. The matrix is `nyquist_compliant: true` once
> populated.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.0 (PHPUnit 11 engine), `pest-plugin-laravel ^4.0`, `pest-plugin-arch ^4.0`, `pest-plugin-snapshots ^2.0` |
| **Config file** | `phpunit.xml` (project root); module-local `Modules/Chains/tests/Pest.php` (new in Phase 5, matches Phase 1/2/3/4 convention) |
| **Quick run command** | `vendor/bin/pest --filter "Chains\|PairLookup\|ResolveChainLinksJob\|CardStatementStateMachine\|IcsSettlementResolver\|PaypalFundingResolver\|ChainReviewQueue\|ChainDrawer\|NextIcsSettlement\|HorizonBoots\|ChainResolutionRuns\|WizardChainResolutionStatus"` |
| **Full suite command** | `composer test` (alias for `pest --parallel`) |
| **Static-analysis gate** | `composer analyse` (Larastan level 10 strict — zero new errors) |
| **Style gate** | `composer format:check` (Laravel Pint — clean) |
| **Estimated runtime** | quick filter ~12s · full suite ~65s (Phase 4 baseline +~17s for Phase 5 surface) |

---

## Sampling Rate

- **After every task commit:** Run `{quick run command}` filtered to the touched module/test class
- **After every plan wave:** Run `{full suite command}` (full Pest + analyse + format:check)
- **Before `/gsd-verify-work`:** Full suite must be green; BoundaryArchTest extensions (D-84 / D-95 / no-`DB::*`-in-Chains) must pass
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

> Per-task `<automated>` blocks contribute one row each. Coverage targets (from `05-RESEARCH.md` § Validation Architecture — 35-test matrix).
> Threat refs link to each plan's `<threat_model>` STRIDE register (`T-05-{NN}-{NN}`).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists (Wave-0 Marker) | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-----------------------------|--------|
| 05-01-01 | 05-01 | 0 | (infra) | T-05-01-03 / T-05-01-07 | Composer dependencies + Horizon installer + failed_jobs migration provisioned (issue #2) | infra-smoke | `composer show laravel/horizon` `composer show predis/predis` `php artisan tinker --execute='echo Schema::hasTable("failed_jobs") ? "YES" : "NO";' \| grep -q YES` | ⬜ Wave 0 | ⬜ pending |
| 05-01-02 | 05-01 | 0 | (infra) | T-05-01-01 / T-05-01-05 | PROJECT.md + README amendments (Phase 5 stack additions + Operator recovery section per issue #14) | doc-grep | `grep -qE '^### Stack additions \(Phase 5\)' .planning/PROJECT.md` `grep -qE '^## Operator recovery' README.md` `grep -q 'unique-lock:resolve-chain-links' README.md` | ⬜ Wave 0 | ⬜ pending |
| 05-01-03 | 05-01 | 0 | (infra) | T-05-01-01 / T-05-01-02 | Docker Redis container running loopback-bound + Horizon boots cleanly | manual-checkpoint | (operator runs `docker ps --filter "name=diederik-redis"` + `php artisan horizon`) | ⬜ Wave 0 | ⬜ pending |
| 05-01b-01 | 05-01b | 0 | CHN-07 | T-05-01b-01 | Modules/Chains skeleton + ServiceProvider registered + autoload-dev extended; SmokeTest green | unit | `composer dump-autoload && vendor/bin/pest --filter "SmokeTest" --testdox` | ⬜ Wave 0 | ⬜ pending |
| 05-01b-02 | 05-01b | 0 | (Cross-cut) | T-05-01b-01 | Synthesised cross-source fixture trio + FixtureParseSmokeTest verifies parsing | fixture-anchored | `php scripts/synthesise_phase5_scenario.php && vendor/bin/pest --filter "FixtureParseSmokeTest"` | ⬜ Wave 0 | ⬜ pending |
| 05-01b-03 | 05-01b | 0 | (Cross-cut) | T-05-01b-02 / T-05-01b-03 / T-05-01b-04 | PairLookup promotion + BoundaryArchTest extensions (D-84/D-95/Cache facade carve-out) + HorizonBootsTest with explicit skip predicate (issue #9 fix) | arch + feature | `vendor/bin/pest --filter "PairLookupTest\|BoundaryArchTest\|HorizonBootsTest"` `! grep -q 'skipOnFailure' tests/Feature/HorizonBootsTest.php` | ⬜ Wave 0 | ⬜ pending |
| 05-01b-04 | 05-01b | 0 | (infra) | T-05-01b-04 | Operator confirms Wave 0 module-half infrastructure operational | manual-checkpoint | (operator runs `vendor/bin/pest --filter "Chains\|PairLookup\|BoundaryArchTest\|HorizonBoots\|SmokeTest\|FixtureParseSmokeTest"`) | ⬜ Wave 0 | ⬜ pending |
| 05-02-01 | 05-02 | 1 | CHN-07 | T-05-02-01 / T-05-02-04 | chain_links + card_statements + card_statement_credits + chain_resolution_runs schema + nullable to_transaction_id trigger (issue #10) + whereJsonContains smoke (issue #11) | feature | `php artisan migrate:fresh` `vendor/bin/pest --filter "ChainLinksSchemaTest\|ChainResolutionRunsSchemaTest\|ChainLinksJsonContainsSmokeTest"` | ⬜ Wave 1 | ⬜ pending |
| 05-02-02 | 05-02 | 1 | CHN-05 / CHN-06 / CHN-07 | T-05-02-02 / T-05-02-03 / T-05-02-06 | Eloquent models (ChainLink + CardStatement + CardStatementCredit + ChainResolutionRun) + DTOs + CardStatementStateMachine + back-population migration + idempotency | feature + unit | `vendor/bin/pest --filter "CardStatementBackPopulationTest\|CardStatementStateMachineTest\|noOtherCardStatementStateMutator\|BoundaryArchTest"` | ⬜ Wave 1 | ⬜ pending |
| 05-03-01 | 05-03 | 2 | CHN-05 / CHN-06 / CHN-07 | T-05-03-03 / T-05-03-04 | IcsSettlementResolver (clean / overpaid / underpaid / exceeded-tolerance / refund-after-close / idempotency / cross-user / D-84) + ChainLinkInsertHelper (issue #4 json_encode consistency) + nullable to_transaction_id for exceeded-tolerance candidates (issue #10) | unit + fixture-anchored | `vendor/bin/pest --filter "IcsSettlementResolverTest\|noResolverWritesTransactions"` `! grep -RIn "->table('chain_links')->insert" Modules/Chains/Internal/Resolvers` | ⬜ Wave 2 | ⬜ pending |
| 05-03-02 | 05-03 | 2 | CHN-05 / CHN-06 / CHN-07 | T-05-03-01 / T-05-03-02 / T-05-03-05 / T-05-03-06 | ResolveChainLinksJob (ShouldQueue + ShouldBeUniqueUntilProcessing + tries=3 + backoff) + ConfirmImport post-commit dispatch + chain_resolution_runs lifecycle (issue #1 + #8) + Queue::failing listener + Cache facade carve-out | feature + contract | `vendor/bin/pest --filter "ResolveChainLinksJobTest\|ChainResolutionIdempotencyTest\|ChainResolutionRunsLifecycleTest\|BoundaryArchTest"` `! grep -A 30 'transaction(function' Modules/Import/Public/Actions/ConfirmImport.php \| grep -E '\$this->bus->dispatch'` | ⬜ Wave 2 | ⬜ pending |
| 05-04-01 | 05-04 | 3 | CHN-01 / CHN-02 / D-106 | T-05-04-03 / T-05-04-07 / T-05-04-08 | PaypalFundingResolver (deterministic D-106 + fuzzy CHN-02 + signature_hash + rejected-pair guard + cross-user + idempotency) + uses ChainLinkInsertHelper (issue #4 lock) | unit + fixture-anchored | `vendor/bin/pest --filter "PaypalFundingResolverTest\|noResolverWritesTransactions"` `! grep -RIn "->table('chain_links')->insert" Modules/Chains/Internal/Resolvers` | ⬜ Wave 3 | ⬜ pending |
| 05-04-02 | 05-04 | 3 | CHN-03 / CHN-04 / CHN-06 / CHN-07 | T-05-04-01 / T-05-04-02 / T-05-04-04 / T-05-04-05 / T-05-04-06 | ChainLinkQuery (forTransaction walks chain bounded at depth 5 + handles nullable to_transaction_id per issue #10 + confidence-tier mapping D-91 + cursor pagination) + CardStatementQuery + ConfirmChainLink (auto-promotion D-87) + RejectChainLink (per-pair D-89) + whereJsonContains works on SQLite | feature | `vendor/bin/pest --filter "ChainLinkQueryTest\|CardStatementQueryTest\|ConfirmChainLinkTest\|RejectChainLinkTest"` | ⬜ Wave 3 | ⬜ pending |
| 05-05-01 | 05-05 | 4 | CHN-04 / UI-02 | T-05-05-01 / T-05-05-02 / T-05-05-04 / T-05-05-05 | ChainDrawer Flux flyout + chain-node partial with explicit @props (issue #13 fix) + TransactionDetail "View chain" button + ICS bulk-settle fan-out pagination + empty states + Confirm/Reject inline chips | feature | `vendor/bin/pest --filter "ChainDrawerTest"` `grep -q "@props" Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php` `grep -q "'fanoutPage' => \$fanoutPage" Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` | ⬜ Wave 4 | ⬜ pending |
| 05-05b-01 | 05-05b | 4 | CHN-03 / CHN-06 / UI-02 | T-05-05b-01 / T-05-05b-03 / T-05-05b-05 / T-05-05b-06 | /chains/review (ChainReviewQueue + Confirm/Reject + auto-promotion hint + cross-user 404) + ThisPeriodAtAGlanceQuery::nextIcsSettlement (D-99) + dashboard tile + top-nav badge via View Factory contract (issue #12 fix — never `view()` global helper) + failed-job toast backed by chain_resolution_runs (issue #1 + #8) | feature | `vendor/bin/pest --filter "ChainReviewQueueTest\|NextIcsSettlementTileTest\|CrossUserChainLinkIsolationTest"` `! grep -RIn 'view()' Modules/Chains/ \| grep -v Test.php` | ⬜ Wave 4 | ⬜ pending |
| 05-05b-02 | 05-05b | 4 | CHN-03 / UI-02 | T-05-05b-02 / T-05-05b-04 | Wizard polling backed by chain_resolution_runs audit table (issue #1 + #8 fix — exact user_id match, never failed_jobs.payload LIKE substring); auto-navigates on complete; surfaces last_error on failed | feature | `vendor/bin/pest --filter "WizardChainResolutionStatusTest"` `! grep -E 'payload.+like.+userId' Modules/Import/Internal/Http/Livewire/PreviewWizard.php` | ⬜ Wave 4 | ⬜ pending |
| 05-05b-03 | 05-05b | 4 | (CHN cross-cut) | T-05-05b-01 / T-05-05b-02 / T-05-05b-03 | Operator end-to-end verification against the synthesised Wave 0 fixture (drawer + review queue + dashboard tile + wizard polling + failed-job toast + top-nav badge) | manual-checkpoint | (operator runs Step 1-10 in Task 3 of 05-05b) | ⬜ Wave 4 | ⬜ pending |

> Total automated rows: 14 (skipping the three manual-checkpoint rows for sign-off counting).
> The matrix maps every Phase 5 RESEARCH § "Phase Requirements → Test Map" row (35 rows) onto a concrete task + Pest filter.
> Cross-cut rows (BoundaryArchTest, idempotency, cross-user isolation, signature_hash format, fixture-anchored variants) are consolidated under the producing task to avoid double-counting.

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

> Split per issue #5: 05-01 (infrastructure half) + 05-01b (module + fixture half).

### 05-01 — Infrastructure half

- [ ] `laravel/horizon ^5.x` + `predis/predis ^3.x` composer require + `composer.lock` updated
- [ ] `php artisan horizon:install` produces `config/horizon.php` + `app/Providers/HorizonServiceProvider.php` (Fortify-auth gate)
- [ ] `php artisan queue:failed-table` + `php artisan migrate` provisions `failed_jobs` table (issue #2 fix)
- [ ] `bootstrap/providers.php` registers `App\Providers\HorizonServiceProvider::class`
- [ ] `.env.example` carries `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`, `REDIS_PASSWORD=null`
- [ ] `config/database.php` `redis.default.host` is `127.0.0.1` (NOT `0.0.0.0`)
- [ ] Redis Docker container reachable on `127.0.0.1:6379` (loopback-bound per RESEARCH Pitfall 8)
- [ ] PROJECT.md amendment (Horizon + Redis flip from "What NOT to Use" → recommended; Docker-for-Redis carve-out)
- [ ] README setup section for Docker Redis + manual `php artisan horizon` second terminal
- [ ] README `## Operator recovery` section for stuck Redis unique-lock keys (issue #14 fix)

### 05-01b — Module + fixture half

- [ ] `Modules/Chains/composer.json` + `ChainsServiceProvider` registered in `bootstrap/providers.php`
- [ ] `Modules/Chains/tests/Pest.php` — module-local Pest bootstrap (mirrors Phase 1/2/3/4)
- [ ] `Modules/Chains/tests/fixtures/scenario-1/` — synthesised ASN CAMT.053 + ICS PDF + PayPal CSV trio per D-107
- [ ] `scripts/synthesise_phase5_scenario.php` — fixture synthesis script per D-108
- [ ] `Modules/Transfers/Public/Services/PairLookup.php` promotion + smoke contract test
- [ ] `tests/Contracts/BoundaryArchTest.php` extended with D-84 / D-95 / no-`DB::*`-in-Chains rules + Cache facade carve-out
- [ ] `tests/Feature/HorizonBootsTest.php` uses EXPLICIT skip predicate (issue #9 fix — `->skip(fn () => !isRedisReachable(...), 'Redis container required ...')`); does NOT contain `skipOnFailure`
- [ ] `phpunit.xml` + `tests/Pest.php` extended with the Chains test discovery row

*Synthesised fixtures cover three reconciliation arms (clean / overpaid / underpaid) so all `IcsSettlementResolver` tolerance branches exercise against committed data.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Chain drawer Flux flyout visual polish | UI-02 | First Flux drawer in project — visual baseline locked by snapshot tests, but keyboard ergonomics (Esc, click-outside, focus trap) require browser verification | Open `/transactions/{id}` → "View chain" → verify Esc closes, click-outside closes, Tab traps focus inside drawer; verify scroll behavior matches D-92 (full-height no outer scroll) |
| `/horizon` dashboard accessibility | (operational) | Loopback-bound; live Horizon dashboard requires running `php artisan horizon` in a second terminal | Start Redis container, run `php artisan horizon`, visit `http://diederik.test/horizon`, verify supervisor is online and `ResolveChainLinksJob` shows in the queue |
| End-to-end chain resolution against user's real data | CHN-01..CHN-07 | Synthesised fixtures cover algorithmic correctness, but real ASN+ICS+PayPal export alignment is a follow-up smoke test once real exports line up | Import user's most recent ASN CAMT.053 + ICS PDF + PayPal CSV in chronological order; verify dashboard "Next ICS settlement" tile populates; drill into a Netflix-via-PayPal row and verify chain tree resolves to ASN/ICS account |
| Operator-recovery snippet usability | (operational) | Issue #14 — README must describe how to clear stuck Redis unique-lock keys; manually crash a worker mid-job + verify the README snippet successfully clears the key | Force a worker crash (Ctrl+C on `php artisan horizon` while a job is mid-run); run the README's `docker exec diederik-redis redis-cli KEYS '*unique-lock:resolve-chain-links:*'` to list; then `docker exec diederik-redis redis-cli DEL <key>` to clear; verify the next dispatch succeeds |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (per-task matrix above)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (composer/Docker/Horizon/fixtures/PROJECT.md/README/operator recovery/failed_jobs migration)
- [x] No watch-mode flags
- [x] Feedback latency < 60s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** ready (per-task matrix filled by planner per issue #3; `wave_0_complete: false` until 05-01 + 05-01b actually execute)
