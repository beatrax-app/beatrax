# Lessons Learned

Process and engineering observations captured at milestone close. Each entry
is phrased as something the next milestone can act on, not as narrative
hindsight. Patterns that proved out are listed here for orientation; the
load-bearing ones are also enforced in the corresponding
[Architecture Decision Records](../adr/), [Architecture](../architecture/) topics,
or [Feature](../features/) deep dives.

## v1.0 lessons

### What worked

- **Vertical MVP slicing held through eleven phases without breaking.**
  Every phase produced an end-to-end demoable slice, not a horizontal
  "schema-only" or "UI-only" deliverable. The phase-1 "see my ASN month"
  experience worked before the phase-2 CAMT.053 slice landed; the pattern
  repeated cleanly to phase 11.
- **State machines as the sole `state`-column mutator** — pattern introduced
  with `CardStatementStateMachine`, propagated to
  `RecurringSeriesStateMachine`, `DriftAlertStateMachine`, and
  `ForecastRunStateMachine`. SQLite triggers paired with arch tests caught
  drift at the database layer in three separate cases during execution. No
  escape hatches were added across four phases.
- **Arch tests as the load-bearing safety net** — 34+ `BoundaryArchTest`
  invariants accumulated across phases. The DI-only invariant
  (`noFacadeCallsFromCoreConsoleCommands` and siblings) caught regressions
  before runtime. Pattern: every new bounded surface ships with at least one
  arch invariant.
- **The `enriched_from` append-only provenance trail** paid off when the
  chain resolver needed "rows touched by format X" queries. Append-only
  saved a destructive overwrite when CAMT enriched an earlier CSV row.
- **Synthesised fixture corpora as load-bearing contract tests** — the
  recurring / drift / forecasting waves each built a multi-scenario corpus
  (clean / overpaid / underpaid / edge cases) that the wave's contract test
  iterated over. Deviations were cheap to spot and verify.
- **Hand-rolled MT940 toolchain.** Chose hand-rolled lexer + per-tag parser
  over `kingsquare/php-mt940` (last release November 2020). Stayed under
  control; single-purpose classes tested independently; no library
  compatibility surprises through later phases.
- **DI-only as a non-negotiable** — proved sustainable across 1644 tests.
  Constructor injection made cross-module substitution trivial (e.g.,
  `FakeGmailApiClient` and `FakeGraphApiClient` substituted cleanly during
  the receipt phases).
- **Single-integer phase numbering held by default.** No decimal phases
  were inserted during v1.0 even though the workflow allowed them — phase
  scope was negotiated upfront rather than mid-phase.

### What was inefficient

- **Traceability drifted at milestone close.** 49 of 68 v1 requirements
  were still marked Pending in REQUIREMENTS at close because per-phase
  summaries did not update the table. Should be a per-phase verification
  step, not a milestone-close batch update.
- **Auto-generated milestone summaries produced noisy entries.** The
  one-liner extractor assumed a specific format in phase summaries; many
  summaries did not follow it. Required a manual rewrite of the
  accomplishments list at close.
- **Verification artefacts left in a `human_needed` state** — three phases
  marked verification `human_needed` with implicit deferral. Either walk
  them at phase close or write an explicit per-phase deferral entry;
  "leaving it for later" propagated through eight phases of work.
- **UAT scenarios deferred phase-by-phase without revisit** — 25 pending
  scenarios accumulated across five phases. Each phase deferred its own
  UAT walk; the deferrals never got revisited until milestone close.
- **Stack flips mid-stream.** The chain-resolver job needed
  `ShouldBeUniqueUntilProcessing`, which forced flipping the "no Horizon,
  no Redis, no Docker" stack posture. It worked out, but rewriting the
  stack-rationale notes should have been a decision-gathering step, not
  a planning step.

### Patterns that became invariants

- **State machines + SQLite triggers + arch tests** for every column
  carrying a discrete state. Locks in: schema invariant, sole-mutator
  invariant, and module-boundary invariant. Documented across
  `CardStatementStateMachine`, `RecurringSeriesStateMachine`,
  `DriftAlertStateMachine`, `ForecastRunStateMachine`,
  `InboxScanStateMachine`.
- **Public / Internal split per module** — `Modules/<Name>/Public/` for
  cross-module surfaces (DTOs, Actions, Events, Queries);
  `Modules/<Name>/Internal/` for everything else. Cross-module imports
  only allowed from `Public/`. Enforced via arch tests. See
  [module boundaries](../architecture/module-boundaries.md).
- **`raw_payload` JSON column on `transactions`** as the archive-only
  audit lane. The chain resolver reads it via raw
  `DatabaseManager::table()` query-builder, never via Eloquent. Pattern:
  domain queries hit typed columns; archival reads hit `raw_payload`. See
  [data model](../architecture/data-model.md).
- **Two-step issuer → format cascading picker** in the upload wizard.
  Source select (issuer) is UX-only; `HeaderSniffer` /
  `SourceAdapterRegistry` / pipelines dispatch on the leaf `sourceFormat`.
  Adding a new ingestion format extends `availableFormats()` in PHP — no
  Blade changes. See [ingestion pipeline](../architecture/ingestion-pipeline.md).
- **Synthetic-IBAN per-issuer** for non-bank accounts (`ICS-CARD`,
  `PAYPAL`). Per-user uniqueness handled by `AccountResolver`'s user scope.
- **PreviewWizard method-triad per non-IBAN issuer** —
  `save{X}AccountName` + `needs{X}AccountName` + `{X}_OWN_IBAN` constant +
  Blade `@elseif` branch. Three branches at v1.0 (IBAN, ICS-card, PayPal);
  future issuers extend the triad.
- **In-tx synchronous event listeners for cross-module fan-out** —
  `PairTransferCandidates`, the `ChainHintDetected` bridge. Same-import-batch
  atomicity preserved via the outer transaction; no `ShouldQueue` or
  `ShouldHandleEventsAfterCommit` for fan-outs that must see just-inserted
  rows.
- **Locale-aware `Money::format(?string $locale = null)`** — EUR routes
  through `nl_NL`, else `en_US`. Default is null with internal routing.
- **`Modules/<Name>/Public/Services/<Name>Lookup` singleton-bound in
  `<Name>ServiceProvider::register()`** — used in `PairLookup`,
  `ChainLinkQuery`, `ForecastHighlightsQuery`. The cross-module read
  surface lives behind a stable contract.
- **Per-phase fixture corpus committed in-repo with an anonymisation
  script.** Future re-runs are auditable.

### Top eight engineering lessons

1. **Traceability and verification rigor belong per-phase, not per-milestone.**
   Update requirement traceability, close UAT, and close verification at
   phase close. Carrying these forward compounded into 25 UAT items, three
   verification gaps, and 49 stale traceability rows at v1.0 close.
2. **Architectural decisions that imply stack changes must surface during
   decision-gathering, not planning.** The chain-resolver's need for
   `ShouldBeUniqueUntilProcessing` forced a Horizon + Redis adoption that
   should have been caught earlier.
3. **Multi-currency must land in the schema before any non-EUR row.**
   Validated by the ICS PDF FX rows shipping as a one-pipeline-stage
   change after the columns already existed. The alternative (retro-fitting
   currency to existing rows) would have been irreversible.
4. **Hand-rolled wins over stagnant libraries.** The MT940 toolchain
   (lexer + per-tag parsers + counterparty cleaner) shipped clean;
   `kingsquare/php-mt940` was last touched in 2020. Same call would likely
   apply for any other 2020-era domain library.
5. **State machines + triggers + arch tests is the pattern for `state`
   columns.** Drift caught at the database layer in three separate cases.
   The cost is one migration + one model + one arch test per state-carrying
   surface; the saving is unrecoverable-bad-state at runtime.
6. **Filament was correctly avoided.** The calm content-first aesthetic
   would have required reskinning Filament's admin-panel defaults. Livewire
   4 + Volt + Flux delivered the look with less custom code, not more.
7. **Synthesised fixture corpora pay for themselves multiple times.** Each
   wave that built a corpus used it to prove the wave's contract test,
   drive deviations, and make later debugging cheap. Build one early.
8. **DI-only stays sustainable past one thousand tests.** Initial worry
   about ergonomics was unfounded. Constructor injection made cross-module
   substitution trivial and kept Larastan level 10 strict honest.

## Cross-milestone view

### Process evolution

| Milestone | Phases | Plans | Key change |
| --- | --- | --- | --- |
| v1.0 | 11 | 66 | First milestone — established vertical-MVP slicing, DI-only, the state-machine + arch-test pattern, the per-module Public / Internal split |

### Cumulative quality

| Milestone | Tests | Arch invariants | Modules |
| --- | --- | --- | --- |
| v1.0 | 1644 | 34+ | 11 |
