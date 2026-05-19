# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v1.0 — MVP Cross-Account Personal Finance Dashboard

**Shipped:** 2026-05-19
**Phases:** 11 | **Plans:** 66 | **Tasks:** 154

### What Was Built

- **Idempotent multi-format ingestion** — ASN CSV, ASN CAMT.053 (via `genkgo/camt`), ASN MT940 (hand-rolled lexer + Tag61/Tag86 parsers), ICS Cards PDF (bespoke extractor + statement-summary parser), PayPal NL-locale CSV with parent/child-fee/child-fx event rollup, `.eml`/`.mbox` drop-in. Cross-format rank-based enrichment via `enriched_from` JSON column. v3 fingerprint composer keyed on `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)`.
- **Multi-currency from day one** — original + settled amounts + FX rate preserved on every transaction; per-page `EUR-only` vs `original` toggle with `#[Url]` binding; per-currency dashboard tiles; locale-aware `Money::format()`.
- **Chain resolution** — the differentiator. Deterministic PayPal-funder via `raw_payload` references; fuzzy fallback (merchant 0.5 / amount 0.3 / window 0.2, capped at 0.99); ASN → ICS bulk-iDEAL settlement decomposition with ±€5 / ±2% / ±10-day tolerance; candidate review queue with per-merchant learning; BFS chain walker with `MAX_DEPTH=5`.
- **Email-receipt ingestion** — Gmail + Microsoft Graph OAuth2 connect wizards; per-inbox UID-resume scanning; per-sender matchers (PayPal, ICS, Google Play) bound to the same fingerprint pipeline; user-defined rules with specificity scoring + per-merchant memory; watched-folder secondary path under `/processed/{YYYY-MM}/`.
- **Recurring + drift + forecasting** — daily recurring detection with 4 cadence bands + configurable variance tolerance; signed-delta drift evaluator with annualized impact + snooze/acknowledge/cancel-what-if actions; 30/60/90-day per-account projection with R-7 percentile range tier + chain-aware routing + shortfall windows + non-persisted what-if scenarios with side-by-side comparison.
- **Operational hardening** — `php artisan db:backup` via `VACUUM INTO` (chmod 600 + sidecar + retention + smart-skip on `PRAGMA data_version`); `db:restore --confirm --force-maintenance` triple-rail destructive command; `diederik:doctor` probes (WAL mode, synchronous mode, backup freshness); `HealthCheckServiceProvider` writing `system_alerts` on PRAGMA drift; user-visible `SystemAlertsBanner`; `diederik:failed-jobs prune`; `diederik:install --launchd` for macOS plists.

### What Worked

- **Vertical MVP slicing held through 11 phases without breaking** — every phase produced an end-to-end demoable slice, not a horizontal "schema-only" or "UI-only" deliverable. Phase 1's "see my ASN month" experience worked before Phase 2's CAMT.053 vertical landed; the pattern repeated cleanly to Phase 11.
- **State machines as sole `state`-column mutators (with SQLite triggers + arch tests)** — pattern introduced in Phase 5 (`CardStatementStateMachine`), propagated through Phase 8 (`RecurringSeriesStateMachine`), Phase 9 (`DriftAlertStateMachine`), Phase 10 (`ForecastRunStateMachine`). Caught drift at the DB layer in three separate cases during execution. No escape hatches added across four phases.
- **Arch tests as the load-bearing safety net** — 34+ `BoundaryArchTest` invariants accumulated across phases. DI-only invariant (`noFacadeCallsFromCoreConsoleCommands` etc.) caught Phase 11 regressions before runtime. The pattern of "every new bounded surface comes with at least one arch invariant" held.
- **`enriched_from` append-only provenance trail (Phase 2)** — paid off in Phase 5 when chain resolver needed "rows touched by format X" queries. Append-only saved a destructive overwrite when CAMT enriched an earlier CSV row.
- **Synthesised fixture corpora as load-bearing contract tests** — Phases 5 / 8 / 9 / 10 each built a multi-scenario fixture corpus (clean / overpaid / underpaid / edge cases) that the wave's contract test iterated over. Made deviations cheap to spot and verify.
- **Hand-rolled MT940 toolchain (Phase 2)** — chose hand-rolled lexer + per-tag parser over `kingsquare/php-mt940` (last release Nov 2020). Stayed under control; single-purpose classes tested independently; no library compatibility surprises through later phases.
- **DI-only as a non-negotiable** — proved sustainable across 1644 tests. Constructor injection made cross-module substitution trivial (e.g., `FakeGmailApiClient` / `FakeGraphApiClient` in Phase 6 / 7).
- **Single integer phase numbering** — no decimal insertions used despite the option being available. Phase scope was negotiated upfront rather than mid-phase.

### What Was Inefficient

- **Stale traceability table at milestone close** — 49 of 68 v1 requirements were still marked `Pending` in `REQUIREMENTS.md` despite their phases having shipped, because per-phase summaries didn't update the table. Should be a per-phase verification step, not a milestone-close batch update.
- **MILESTONES.md auto-generation produced noisy "One-liner:" entries** — the `gsd-sdk milestone.complete` summary-extract assumed a specific one-liner format in `*-SUMMARY.md` files; many summaries didn't follow it. Required manual rewrite of the accomplishments list at close.
- **Verification artifacts left in `human_needed` state** — three phases (03, 08, 11) marked verification `human_needed` with implicit deferral. Should either be in-person walked through at phase close or explicitly written into a per-phase deferral entry; "leaving it for later" propagated through 8 phases of work.
- **HUMAN-UAT scenarios deferred phase-by-phase without revisit** — 25 pending scenarios accumulated across 5 phases. Each phase deferred its own UAT walk; the deferrals never got revisited until milestone close.
- **Phase 5 stack flip (Horizon + Redis + Docker) happened mid-stream** — the chain-resolver job needed `ShouldBeUniqueUntilProcessing`, which forced flipping the "no Horizon, no Redis, no Docker" stack posture. Worked out fine but the rewrite of `research/STACK.md` should have been caught at Phase 5 discuss-phase, not at plan-phase.
- **STATE.md drift in metrics tables** — recent-trend velocity tables in `STATE.md` retained data from Phase 2–5 only; Phases 6–11 never appended their runs. Read-only documentation drift.

### Patterns Established

- **State machines + SQLite triggers + arch tests** for every column carrying a discrete state. Locks in: schema invariant + sole-mutator invariant + module-boundary invariant. Pattern documented across `CardStatementStateMachine`, `RecurringSeriesStateMachine`, `DriftAlertStateMachine`, `ForecastRunStateMachine`, `InboxScanStateMachine`.
- **Public/Internal split per module** — `Modules/<Name>/Public/` for cross-module surfaces (DTOs, Actions, Events, Queries); `Modules/<Name>/Internal/` for everything else. Cross-module imports only allowed from `Public/`. Enforced via arch tests.
- **`raw_payload` JSON column on `transactions`** as the archive-only audit lane. Phase 5 chain-resolver reads it via raw `DatabaseManager::table()` query-builder; never via Eloquent. Pattern: domain queries hit typed columns; archival reads hit `raw_payload`.
- **Two-step issuer → format cascading picker** in the upload wizard. Source select (issuer) is UX-only; `HeaderSniffer` / `SourceAdapterRegistry` / pipelines dispatch on the leaf `sourceFormat`. Adding a new ingestion format extends `availableFormats()` in PHP; no Blade changes.
- **Synthetic-IBAN per-issuer** for non-bank accounts (`ICS-CARD`, `PAYPAL`). Per-user uniqueness handled by `AccountResolver`'s user scope.
- **PreviewWizard method-triad per non-IBAN issuer** — `save{X}AccountName` + `needs{X}AccountName` + `{X}_OWN_IBAN` constant + Blade `@elseif` branch. Three branches at v1.0 (IBAN, ICS-card, PayPal); future issuers extend the triad.
- **In-tx synchronous event listeners for cross-module fan-out** — `PairTransferCandidates` (Phase 4), `ChainHintDetected` bridge (Phase 7). Same-import-batch atomicity preserved via the outer transaction; no `ShouldQueue` / `ShouldHandleEventsAfterCommit` for fan-outs that must see just-inserted rows.
- **Locale-aware `Money::format(?string $locale = null)`** — EUR → `nl_NL`, else `en_US`. Default is null with internal routing.
- **`Modules/<Name>/Public/Services/<Name>Lookup` singleton-bound in `<Name>ServiceProvider::register()`** — used in `PairLookup`, `ChainLinkQuery`, `ForecastHighlightsQuery`. Cross-module read surface lives behind a stable contract.
- **Per-phase fixture corpus committed in-repo with anonymisation script** — Phase 3 onwards. Future re-runs are auditable; throwaway anonymisation was dropped from the Phase 1 era.

### Key Lessons

1. **Traceability and verification rigor should be per-phase, not per-milestone.** Update `REQUIREMENTS.md` traceability + close UAT + close verification at phase close, not as a milestone-close batch. Carrying these forward through 11 phases compounded into 25 UAT items + 3 verification gaps + 49 stale traceability rows at close.
2. **Architectural decisions that imply stack changes (e.g., "we need `ShouldBeUniqueUntilProcessing`") must surface in discuss-phase, not plan-phase.** Phase 5's Horizon + Redis flip worked out, but discovering it during planning rather than at the decision-gathering stage cost a doc rewrite.
3. **Multi-currency must land in the schema before any non-EUR row.** Validated by Phase 3 — adding ICS PDF FX rows after the columns existed was a one-pipeline-stage change. The alternative (retro-fitting currency to existing rows) would have been irreversible.
4. **Hand-rolled wins over stagnant libraries.** Phase 2's hand-rolled MT940 toolchain (lexer + per-tag parsers + counterparty cleaner) shipped clean; `kingsquare/php-mt940` was last touched in 2020. Same call would likely apply for any other 2020-era domain library.
5. **State machines + triggers + arch tests is the pattern for `state` columns.** Drift caught at the DB layer in three separate cases. The cost is one migration + one model + one arch test per state-carrying surface; the savings is irrecoverable-bad-state at runtime.
6. **Filament was correctly avoided.** The "calm content-first" aesthetic the user wanted would have required reskinning Filament's admin-panel defaults. Livewire 4 + Volt + Flux delivered the look with less custom code, not more.
7. **Synthesised fixture corpora pay for themselves multiple times.** Each phase that built a corpus (Phases 5, 8, 9, 10) used it to (a) prove the wave's contract test, (b) drive deviations, (c) make later debugging cheap. Build one early.
8. **DI-only stays sustainable past 1000 tests.** Initial worry about ergonomics was unfounded. Constructor injection made cross-module substitution trivial and kept Larastan level 10 strict honest.

### Cost Observations

- Model mix: not tracked formally; primarily Opus + Sonnet through GSD workflows
- Phase durations recorded incrementally in `STATE.md` (Phase 02–05 only — later phases not appended)
- Notable: hand-rolled MT940 toolchain shipped in ~15 min (Phase 2 Plan 4); CAMT.053 vertical slice ~28 min (Phase 2 Plan 3) — most of that was the fixture IBAN check-digit refresh forced by `genkgo/camt`'s eager validation, not the adapter itself

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Phases | Plans | Key Change |
|-----------|--------|-------|------------|
| v1.0 | 11 | 66 | First milestone — established vertical-MVP slicing, DI-only, state-machine + arch-test pattern, per-module Public/Internal split |

### Cumulative Quality

| Milestone | Tests | Arch Invariants | Modules |
|-----------|-------|-----------------|---------|
| v1.0 | 1644 | 34+ | 11 |

### Top Lessons (Verified Across Milestones)

*Cross-milestone verification requires a second milestone. Lessons above are v1.0-only.*
