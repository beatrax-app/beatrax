---
phase: 17-ci-cd-pipeline-code-signing
plan: 05b
type: execute
wave: 2
depends_on:
  - 17-05a
files_modified:
  - Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php
  - Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php
  - Modules/Counterparties/Routes/web.php
  - Modules/Counterparties/tests/Feature/ResolveCounterpartyStageTest.php
  - Modules/Counterparties/tests/Arch/CounterpartiesBoundaryTest.php
  - Modules/Import/Internal/Pipeline/ImportPipeline.php
  - tests/Contracts/BoundaryArchTest.php
autonomous: true
requirements:
  - gap-counterparty-module-backend-wiring
requirements_addressed:
  - gap-counterparty-module-backend-wiring
must_haves:
  truths:
    - "ResolveCounterpartyStage runs inside ImportPipeline between ApplyAutoCategoryStage and the post-commit boundary; idempotent"
    - "CounterpartyGarbageCollectorJob prunes orphans (zero transactions in 365d AND zero alias entries); ShouldBeUniqueUntilProcessing on database queue; scheduled daily at 04:00 Europe/Amsterdam"
    - "Module boundary arch invariant enforces Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties"
    - "End-to-end feature test imports a fixture CSV and produces Counterparty rows of every active type plus a self_account result that creates no row"
    - "transactions.counterparty_id is populated for every resolved transaction (except self_account legs which stay null)"
  artifacts:
    - path: "Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php"
      provides: "ImportPipeline stage between ApplyAutoCategory and post-commit"
    - path: "Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php"
      provides: "Daily-scheduled orphan pruning via ShouldBeUniqueUntilProcessing"
    - path: "tests/Contracts/BoundaryArchTest.php"
      provides: "New `Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties` invariant added"
    - path: "Modules/Counterparties/tests/Arch/CounterpartiesBoundaryTest.php"
      provides: "Satellite module-local boundary arch check for fast feedback"
  key_links:
    - from: "ImportPipeline"
      to: "ResolveCounterpartyStage"
      via: "Constructor DI; ::run inside the per-row loop between ApplyAutoCategory and the post-commit boundary"
    - from: "ResolveCounterpartyStage"
      to: "CounterpartyResolver Public contract (from 17-05a)"
      via: "Constructor DI"
---

<objective>
Wire the resolver from 17-05a into ImportPipeline, ship the daily garbage collector, lock the module boundary with an arch invariant, and prove end-to-end correctness via a Feature test.

Purpose: 17-05a delivered the resolver as a library. This plan turns it into a load-bearing pipeline stage — every import now populates `transactions.counterparty_id`. The GC job is the long-term hygiene story; the arch invariant protects the module boundary from drive-by leaks; the Feature test exercises the full ImportPipeline path.

Output: A green ImportPipeline that resolves counterparties on every row, a scheduled GC job, a module-boundary arch invariant in `tests/Contracts/BoundaryArchTest.php`, and a fixture-import Feature test that produces Counterparty rows of every active type.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@Modules/Counterparties/Public/Contracts/CounterpartyResolver.php
@Modules/Counterparties/Public/Dto/CounterpartyResolutionDto.php
@Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php
@Modules/Import/Internal/Pipeline/ImportPipeline.php
@Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php
@Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php
@tests/Contracts/BoundaryArchTest.php

<interfaces>
<!-- ResolveCounterpartyStage contract -->
namespace Modules\Counterparties\Internal\Pipeline;
final class ResolveCounterpartyStage {
    public function __construct(
        private readonly CounterpartyResolver $resolver,
        private readonly DatabaseManager $db,
    ) {}
    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction;
}

<!-- ImportPipeline integration point -->
Modules/Import/Internal/Pipeline/ImportPipeline.php — add ResolveCounterpartyStage as a constructor-injected dependency; call $this->resolveCounterpartyStage->run($tx, $user) BETWEEN $applyAutoCategory->apply(...) and $fingerprint->run(...) (whichever marks the post-commit boundary in the existing pipeline). The exact insertion-point line is discovered during read_first by tracing the per-row loop in ImportPipeline.

<!-- CounterpartyGarbageCollectorJob — analog: DetectDriftAlertsJob -->
namespace Modules\Counterparties\Internal\Jobs;
final class CounterpartyGarbageCollectorJob implements ShouldBeUniqueUntilProcessing, ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public function __construct(public readonly int $userId) {}
    public function uniqueId(): string { return (string) $this->userId; }
    public function uniqueFor(): int { return 3600; }
    public function uniqueVia(): LockStore { return LockStore::forUniqueJobs(); }
    public function handle(DatabaseManager $db): void;
}
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: ResolveCounterpartyStage + ImportPipeline wiring + module-boundary arch invariant + end-to-end Feature test</name>
  <files>Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php, Modules/Counterparties/Routes/web.php, Modules/Counterparties/tests/Feature/ResolveCounterpartyStageTest.php, Modules/Counterparties/tests/Arch/CounterpartiesBoundaryTest.php, Modules/Import/Internal/Pipeline/ImportPipeline.php, tests/Contracts/BoundaryArchTest.php</files>
  <read_first>
    - Modules/Import/Internal/Pipeline/ImportPipeline.php (CURRENT — locate the per-row loop AND the exact insertion point between `$applyAutoCategory->apply(...)` and the post-commit boundary; understand constructor signature so the new dependency drops in cleanly)
    - Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php (analog — pipeline stage shape per PATTERNS.md)
    - tests/Contracts/BoundaryArchTest.php (locate the existing module-boundary enumeration — lines around 9-63 per PATTERNS.md — and the spot where the new Counterparties invariant is appended)
  </read_first>
  <behavior>
    `ResolveCounterpartyStageTest.php` (Feature):
    - Test 1 (end-to-end via ImportPipeline): a fixture import containing one ASN row with a Netflix description + one ASN row with a Belastingdienst description + one ASN→PayPal transfer_out leg (counterparty_iban=LU89...) + one ASN→own-account transfer produces FOUR results: a `merchant` Counterparty named Netflix, a `government` Counterparty named Belastingdienst, a `bank` Counterparty named PayPal (via alias bridge), and a self_account resolution that creates NO Counterparty row
    - Test 2 (counterparty_id populated): every transaction whose resolution returned a non-null counterpartyId has its `counterparty_id` FK set on the transactions row
    - Test 3 (self_account leaves counterparty_id null): the self-account leg leaves transactions.counterparty_id NULL
    - Test 4 (idempotency on re-import): re-running the same fixture import does NOT create duplicate Counterparty rows
    - Test 5 (CounterpartyResolved event fired): each upsert fires CounterpartyResolved with the correct (counterpartyId, userId, type)

    `CounterpartiesBoundaryTest.php` (Arch):
    - Test 6: `Modules\Counterparties\Internal` is only used inside `Modules\Counterparties` (analog Pest arch shape)
  </behavior>
  <action>Step A — Pipeline stage: create `Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php` as `final class ResolveCounterpartyStage` with constructor DI: `private readonly CounterpartyResolver $resolver`, `private readonly DatabaseManager $db`. Method `public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction` calls `$this->resolver->resolve($tx, $user)`, and if the DTO has a non-null counterpartyId, attaches it to the outgoing CanonicalTransaction via `$tx->withCounterpartyId($dto->counterpartyId)` (extend the DTO with a withCounterpartyId mutator if not present; discover during read_first). On null DTO or self_account result, returns the unmodified $tx. The resolver service (built in 17-05a) already fires CounterpartyResolved — this stage is the pipeline glue, not the event source.

    Step B — ImportPipeline integration: modify `Modules/Import/Internal/Pipeline/ImportPipeline.php`. Add `ResolveCounterpartyStage` as a constructor-injected dependency (append to the existing constructor parameter list — the existing constructor already takes `MerchantNameResolver` per PATTERNS.md so this is mechanically additive). In the per-row loop (the `preview()` or equivalent method that orchestrates stages), insert `$tx = $this->resolveCounterpartyStage->run($tx, $user);` AFTER `$applyAutoCategory->apply(...)` and BEFORE the post-commit boundary marker (most likely `$fingerprint->run(...)` per PATTERNS.md — verify during read_first). The transactions-row write that consumes the final $tx must persist the counterparty_id (verify the existing INSERT/UPDATE statement includes `counterparty_id` as a column; if not, add it).

    Step C — Routes placeholder: ensure `Modules/Counterparties/Routes/web.php` exists with the `<?php declare(strict_types=1);` shell (Plan 17-06 family fills with three Route::get entries).

    Step D — Arch invariant: append to `tests/Contracts/BoundaryArchTest.php` the one-liner per PATTERNS.md:
    `arch('Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties')->expect('Modules\\Counterparties\\Internal')->toOnlyBeUsedIn('Modules\\Counterparties');`
    Also create `Modules/Counterparties/tests/Arch/CounterpartiesBoundaryTest.php` as a parallel/satellite check for module-local CI runs.

    Step E — Feature test: write `ResolveCounterpartyStageTest.php` covering tests 1-5 via real ImportPipeline invocation against fixture CSV data (mirror the patterns in existing ingestion Feature tests). Tests use `RefreshDatabase` + beforeEach setup that seeds: one user, one ASN account with a known IBAN, the Phase 16.1.2.1 known-counterparty-IBAN seed data, and a few merchant_aliases. The fixture data is composed inline (no external fixture files needed for v1.0 — keep tests self-contained).</action>
  <verify>
    <automated>vendor/bin/pest Modules/Counterparties/tests/Feature/ Modules/Counterparties/tests/Arch/ tests/Contracts/BoundaryArchTest.php --stop-on-failure</automated>
  </verify>
  <done>All 6 test behaviors pass; ImportPipeline still passes ALL EXISTING tests (no regression — run `vendor/bin/pest Modules/Import/tests/`); new BoundaryArchTest invariant included in `composer test`; Larastan + Pint green on all new + modified files.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: CounterpartyGarbageCollectorJob + scheduler entry + GC tests</name>
  <files>Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php, Modules/Counterparties/tests/Feature/CounterpartyGarbageCollectorJobTest.php (added to files_modified during execution)</files>
  <read_first>
    - Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php (analog — ShouldBeUniqueUntilProcessing + LockStore::forUniqueJobs() + uniqueId/uniqueFor/handle DI)
    - Modules/DriftAlerts/Internal/Jobs/* + their tests (the unique-job + scheduler pattern; how the daily schedule is registered)
    - app/Console/Kernel.php OR routes/console.php (discover the project's scheduler-registration convention — Laravel 11+ uses routes/console.php by default; if both exist, pick whichever DriftAlerts uses)
  </read_first>
  <behavior>
    - Test 1: given a Counterparty with zero transactions in the last 365 days AND zero merchant_aliases entries, GC prunes it
    - Test 2: given a Counterparty with at least one transaction in the last 365 days, GC does NOT prune it
    - Test 3: given a Counterparty with zero recent transactions BUT one merchant_alias entry, GC does NOT prune it (alias preserves it)
    - Test 4: the job declares `ShouldBeUniqueUntilProcessing` with uniqueId keyed by userId, uniqueFor 3600s, uniqueVia LockStore::forUniqueJobs() (verify via reflection or direct method calls)
    - Test 5: the scheduler registers a daily-at-04:00 Europe/Amsterdam invocation (verify by inspecting `Schedule` events or asserting the registration via the project's scheduler test helper if any)
  </behavior>
  <action>Step A — Job: create `Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php` per the DetectDriftAlertsJob analog (interface contract in `<interfaces>` above). `handle(DatabaseManager $db): void` deletes Counterparties where `user_id = $this->userId` AND `(SELECT COUNT(*) FROM transactions WHERE counterparty_id = counterparties.id AND created_at >= NOW() - INTERVAL 365 DAY) = 0` AND `(SELECT COUNT(*) FROM merchant_aliases WHERE counterparty_id = counterparties.id) = 0`. SQLite-aware date comparison: use `date('now', '-365 days')` rather than `INTERVAL 365 DAY` (verify against the existing DriftAlerts/migration SQL conventions).

    Step B — Scheduler entry: register the job to dispatch daily at 04:00 Europe/Amsterdam for each user. Follow whichever scheduler convention the DriftAlerts job uses (likely `routes/console.php` with `Schedule::call(fn(DatabaseManager $db) => User::query()->cursor()->each(fn($u) => CounterpartyGarbageCollectorJob::dispatch($u->id)))->dailyAt('04:00')->timezone('Europe/Amsterdam')`).

    Step C — Pest tests: write `Modules/Counterparties/tests/Feature/CounterpartyGarbageCollectorJobTest.php` covering tests 1-5 above. Tests use `RefreshDatabase`, seed counterparties + transactions + merchant_aliases via the query builder, dispatch the job synchronously (`Queue::fake()` + `Bus::dispatchSync()`), and assert post-state. The unique-job assertions can be made via `ReflectionMethod` against the `uniqueId/uniqueFor/uniqueVia` methods.

    DI-only throughout. No facade calls in `handle()` — the DatabaseManager is injected. PHPDocs describe present-tense steady-state behavior.</action>
  <verify>
    <automated>vendor/bin/pest Modules/Counterparties/tests/Feature/CounterpartyGarbageCollectorJobTest.php --stop-on-failure</automated>
  </verify>
  <done>All 5 behavior tests pass; the job dispatches + prunes per the orphan definition; scheduler entry registered; unique-job semantics verified; Larastan + Pint green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| ImportPipeline → CounterpartyResolverService | Resolver runs per-row during import; a buggy resolver returning bad data could mis-link transactions to counterparties cross-user |
| GC job → counterparties table | Destructive operation; over-pruning during an in-flight import could orphan transactions' counterparty_id |
| consumer code → Modules\Counterparties\Internal | Module boundary arch invariant enforces no direct internal access from outside the module |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-05b-01 | Denial of service | GC job over-pruning during an in-flight import | mitigate | Job is ShouldBeUniqueUntilProcessing keyed by userId — only one GC run per user at a time; runs at 04:00 when import frequency is lowest |
| T-17-05b-02 | Repudiation | counterparty assignments overwritten silently on re-import | accept | Idempotency test (Test 4) confirms re-imports do NOT create duplicates; same counterparty_id is re-attached to the same transactions; assignment-change history is not tracked in v1.0 (deferred to v1.1 if needed) |
| T-17-05b-03 | Tampering | a future consumer reaching into Modules\Counterparties\Internal directly | mitigate | New module boundary arch invariant in tests/Contracts/BoundaryArchTest.php blocks at CI gate; CODEOWNERS (Plan 17-03) gates changes to tests/Contracts/ |
</threat_model>

<verification>
After both tasks:

1. `vendor/bin/pest Modules/Counterparties/` all green (14 unit from 17-05a + 5 feature + 5 GC + 1 arch = 25 tests across both plans)
2. `vendor/bin/pest Modules/Import/tests/` STILL all green (no regression in ImportPipeline)
3. `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` green with the new Counterparties invariant
4. `composer test` (full quality gate) green
</verification>

<success_criteria>
- All 5 must_haves true
- ImportPipeline integration is mechanically minimal (one DI addition + one method call insertion)
- CounterpartyGarbageCollectorJob schedules daily and prunes per the orphan definition
- Module boundary arch invariant green
- Zero regressions in existing ImportPipeline tests
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-05b-SUMMARY.md` capturing: the exact ImportPipeline insertion-point line numbers (before/after), the SQLite date-comparison form used in the GC job, the scheduler-registration file + line, and any deviations from PATTERNS.md analogs.
</output>
