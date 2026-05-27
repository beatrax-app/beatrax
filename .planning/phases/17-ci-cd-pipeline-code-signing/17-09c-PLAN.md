---
phase: 17-ci-cd-pipeline-code-signing
plan: 09c
type: execute
wave: 3
depends_on:
  - 17-09b
files_modified:
  - .docs/adr/00-index.md
  - .docs/adr/0001-modular-architecture.md
  - .docs/adr/0002-di-only-rule.md
  - .docs/adr/0003-hippocratic-3-0-license.md
  - .docs/adr/0004-local-only-hosting.md
  - .docs/adr/0005-sqlite-wal.md
  - .docs/adr/0006-nativephp-desktop-shell.md
  - .docs/adr/0007-database-queue-driver.md
  - .docs/adr/0008-multi-user-belongstouser.md
  - .docs/adr/0009-brick-money-multi-currency.md
  - .docs/adr/0010-recovery-codes-no-smtp.md
  - .docs/architecture/00-index.md
  - .docs/architecture/module-boundaries.md
  - .docs/architecture/ingestion-pipeline.md
  - .docs/architecture/chain-resolution.md
  - .docs/architecture/categorization.md
  - .docs/architecture/data-model.md
  - .docs/features/_template/architecture.md
  - .docs/features/_template/code.md
  - .docs/features/_template/specs.md
  - .docs/features/_template/how-to-test.md
autonomous: false
requirements:
  - gap-docs-folder-content
requirements_addressed:
  - gap-docs-folder-content
must_haves:
  truths:
    - "10 ADRs graduated from PROJECT.md Key Decisions + accumulated v0.x decisions (modular architecture, DI-only, Hippocratic 3.0, local-only, SQLite+WAL, NativePHP, database queue, BelongsToUser, brick/money, recovery codes)"
    - "Each ADR follows Status / Context / Decision / Consequences template (mirroring happklaar's shape)"
    - ".docs/architecture/ contains 5 topic files (module-boundaries, ingestion-pipeline, chain-resolution, categorization, data-model) each substantive prose (1-2 pages)"
    - ".docs/features/_template/ ships 4 files (architecture, code, specs, how-to-test) ready for Plan 17-10 to consume per-module"
    - "All content passes noGsdLeakage arch invariant (Plan 17-08's .docs/ narrower scan)"
    - "Architecture topics + ADRs cross-link consistently (e.g., 0001-modular-architecture references architecture/module-boundaries.md)"
  artifacts:
    - path: ".docs/adr/0001-...-0010-...md"
      provides: "10 graduated ADRs forming the v1.0.0 architectural baseline"
    - path: ".docs/architecture/{module-boundaries,ingestion-pipeline,chain-resolution,categorization,data-model}.md"
      provides: "5 architecture topic deep-dives"
    - path: ".docs/features/_template/{architecture,code,specs,how-to-test}.md"
      provides: "Per-module documentation template Plan 17-10 fills"
  key_links:
    - from: ".docs/adr/0001-modular-architecture.md"
      to: ".docs/architecture/module-boundaries.md"
      via: "ADR Consequences section cross-link"
    - from: ".docs/features/_template/"
      to: "Plan 17-10 per-module fills"
      via: "Template consumed by 17 modules × 4 files in 17-10"
---

<objective>
Ship the substantive narrative content for `.docs/`: 10 ADRs + 5 architecture topics + the per-module features template. Builds on the skeleton from 17-09b.

Purpose: D-32..D-34 — the architectural baseline that the v1.0.0 release commits to in writing. Splitting from 17-09b (skeleton + ops docs) and 17-09a (the /help page) keeps each plan focused: pure narrative + design-decision writing here.

Output: Every key decision from PROJECT.md is graduated to an ADR; the 5 architecture topics give future contributors the high-level shape; the features template is ready for 17-10 to fill per-module.

**Note on the human-verify checkpoint:** A single checkpoint at the end validates 10 ADRs + 5 architecture topics in one pass — the executor renders them on GitHub via gh browse for visual review.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@.planning/PROJECT.md
@CLAUDE.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: 10 ADRs + adr/00-index.md update</name>
  <files>.docs/adr/00-index.md, .docs/adr/0001-modular-architecture.md, .docs/adr/0002-di-only-rule.md, .docs/adr/0003-hippocratic-3-0-license.md, .docs/adr/0004-local-only-hosting.md, .docs/adr/0005-sqlite-wal.md, .docs/adr/0006-nativephp-desktop-shell.md, .docs/adr/0007-database-queue-driver.md, .docs/adr/0008-multi-user-belongstouser.md, .docs/adr/0009-brick-money-multi-currency.md, .docs/adr/0010-recovery-codes-no-smtp.md</files>
  <read_first>
    - .planning/PROJECT.md Key Decisions table (the source-material for ADRs 0001-0010)
    - CLAUDE.md tech-stack section (additional rationale source)
    - .docs/adr/00-index.md (the placeholder from 17-09b — this task replaces it with the actual table)
    - External reference: skim happklaar's adr/0001 for the Status/Context/Decision/Consequences template shape
  </read_first>
  <action>Each ADR follows a 4-section template (mirror happklaar's exactly: Status, Context, Decision, Consequences). Length: half-page to one page each.

    - **0001-modular-architecture.md** — Decision: code lives in `Modules/<Name>/` with Public/Internal split; cross-module access only via Public service classes or events. Context: 11+ bounded modules; sustaining the boundary by convention alone breaks at scale. Consequences: arch tests in `tests/Contracts/BoundaryArchTest.php` enforce; new modules follow the template. Cross-link to architecture/module-boundaries.md.
    - **0002-di-only-rule.md** — Decision: constructor injection everywhere; no facade calls (Auth::user(), Cache::get(...)) or global helpers (auth(), config(), request()) in module code. Eloquent models direct OK. Context: testability + Larastan compliance. Consequences: BoundaryArchTest::noLaravelFacadeUsageInModule + noLaravelGlobalHelpersInCoreConsoleCommands enforce.
    - **0003-hippocratic-3-0-license.md** — Decision: source-available under Hippocratic License 3.0. Context: ethical-use clauses reflect maintainer values. Consequences: not redistributable under permissive terms; cross-link to legal/license-rationale.md.
    - **0004-local-only-hosting.md** — Decision: all data stays on the user's machine; no cloud sync, no telemetry, no remote logging. Context: privacy-first product positioning. Consequences: release workflow forbids outbound network calls (Sentry, telemetry); auto-update is the only outbound exception.
    - **0005-sqlite-wal.md** — Decision: SQLite with WAL journal mode as the canonical store. Context: single-machine, single-or-2-user scope. Consequences: queue + cache use the same database file; `database` queue driver replaces Redis in the shipped bundle.
    - **0006-nativephp-desktop-shell.md** — Decision: NativePHP as the desktop shell. Context: cross-platform desktop is the public-release surface. Consequences: `Modules/Desktop/` quarantines every Native\Laravel\* import.
    - **0007-database-queue-driver.md** — Decision: shipped bundle uses Laravel's `database` queue driver; Horizon is dev-only. Context: shipped bundle cannot ship Redis. Consequences: Horizon serviceProvider early-exits on production.
    - **0008-multi-user-belongstouser.md** — Decision: every user-scoped model uses `BelongsToUser` + explicit `->where('user_id', $userId)` on raw queries. Context: 2-user partner-sharing requirement. Consequences: cross-user 404 tests on every user-scoped route.
    - **0009-brick-money-multi-currency.md** — Decision: `brick/money` for multi-currency arithmetic. Context: multi-currency requirement makes floats unsafe. Consequences: every monetary value is a Money instance.
    - **0010-recovery-codes-no-smtp.md** — Decision: password reset uses recovery codes + owner-resets-partner + diederik:reset-password CLI fallback. NO SMTP-based reset in v2.0. Context: desktop context cannot reliably relay mail. Consequences: AUTH-22 (SMTP via Gmail OAuth) deferred to v2.1.

    Rewrite `.docs/adr/00-index.md` with the table:
    | # | Title | Status |
    |---|---|---|
    | [0001](0001-modular-architecture.md) | Modular architecture | Accepted |
    | [0002](0002-di-only-rule.md) | DI-only rule | Accepted |
    | ... etc through 0010 |

    Phase number mentions ARE allowed in .docs/ per RESEARCH Q4 RESOLVED — an ADR may legitimately reference "graduated from Phase 17 (D-23)".</action>
  <verify>
    <automated>find .docs/adr -name "*.md" -not -name "00-index.md" | wc -l | grep -q "^[[:space:]]*10$" && grep -q "0001" .docs/adr/00-index.md && vendor/bin/pest tests/Contracts/GsdLeakageTest.php --stop-on-failure</automated>
  </verify>
  <done>10 ADRs materialize; adr/00-index.md is the canonical entry point with all 10 cross-linked; each ADR has Status / Context / Decision / Consequences; noGsdLeakage green (BOTH runtime + .docs/ scans).</done>
</task>

<task type="auto">
  <name>Task 2: 5 architecture topics + architecture/00-index.md update + features/_template/ 4 files</name>
  <files>.docs/architecture/00-index.md, .docs/architecture/module-boundaries.md, .docs/architecture/ingestion-pipeline.md, .docs/architecture/chain-resolution.md, .docs/architecture/categorization.md, .docs/architecture/data-model.md, .docs/features/_template/architecture.md, .docs/features/_template/code.md, .docs/features/_template/specs.md, .docs/features/_template/how-to-test.md</files>
  <read_first>
    - Modules/Import/Internal/Pipeline/ImportPipeline.php (source for ingestion-pipeline.md)
    - Modules/Chains/ + Modules/Counterparties/ source for chain-resolution.md
    - Modules/Categorization/ source for categorization.md
    - All Modules/*/Database/Migrations/ for data-model.md (high-level ERD prose)
    - tests/Contracts/BoundaryArchTest.php for module-boundaries.md (the actual invariants)
  </read_first>
  <action>**Architecture topics** — each is a 1-2 page prose deep-dive:

    - **module-boundaries.md** — explain Public/Internal split + how cross-module access works via Public service classes + events; reference the 11+ modules; describe arch invariants in tests/Contracts/BoundaryArchTest.php
    - **ingestion-pipeline.md** — describe `Modules/Import/Internal/Pipeline/ImportPipeline.php`; the stages (parsing → fingerprinting → ClassifyTransactionType → ApplyAutoCategory → ResolveCounterparty → post-commit boundary); idempotency contract
    - **chain-resolution.md** — describe PayPal funding chains (ASN debit ↔ PayPal Bankstorting funding leg) + ICS bulk-iDEAL settlement chains; the known-counterparty-IBAN alias bridge; how `pair_transaction_id` works
    - **categorization.md** — describe the rule-based categorizer + per-user merchant memory + the default seed set; explain the ≥40% gate
    - **data-model.md** — high-level ERD prose: users, accounts, transactions, categorization_rules, merchant_aliases, counterparties, oauth_secrets, known_counterparty_ibans, system_alerts, user_preferences

    Rewrite `.docs/architecture/00-index.md` with the table listing all 5 topics.

    **Features template** (`.docs/features/_template/`):
    - `architecture.md` template with section headings: `## What this module is for` / `## Module boundary` / `## Key services + events` / `## Data flow`
    - `code.md` template with: `## Directory layout` / `## Public API` / `## Internal services` / `## Models + migrations`
    - `specs.md` template with: `## Behavioral contracts` / `## Edge cases` / `## Cross-module collaborators`
    - `how-to-test.md` template with: `## Unit tests` / `## Feature tests` / `## Arch invariants` / `## How to run the suite for just this module`

    Each template file ~half-page; section headings clear; "Plan 17-10 fills these per-module" note in a Markdown comment at the top.

    Throughout: NO `.planning/` / `PLAN.md` / `RESEARCH.md` / `CONTEXT.md` / `gsd[-_]` references (per the narrower .docs/ scan). Phase number mentions allowed.</action>
  <verify>
    <automated>test -f .docs/architecture/module-boundaries.md && test -f .docs/architecture/ingestion-pipeline.md && test -f .docs/architecture/chain-resolution.md && test -f .docs/architecture/categorization.md && test -f .docs/architecture/data-model.md && find .docs/features/_template -name "*.md" | wc -l | grep -q "^[[:space:]]*4$" && grep -q "module-boundaries" .docs/architecture/00-index.md && vendor/bin/pest tests/Contracts/GsdLeakageTest.php --stop-on-failure</automated>
  </verify>
  <done>5 architecture topics materialize as substantive prose; architecture/00-index.md is the entry point; 4 features/_template files exist with clear section headings; noGsdLeakage green (BOTH runtime + .docs/ scans).</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>10 ADRs + 5 architecture topics + features template — all narrative .docs/ content (Tasks 1-2)</what-built>
  <how-to-verify>
    1. Open `.docs/adr/00-index.md` → walk through 3-4 random ADRs → confirm prose flows + Status/Context/Decision/Consequences sections present + cross-links resolve
    2. Open each architecture topic file → confirm content is substantive (not just headings + TODO markers)
    3. Open features/_template/ — confirm the 4 files are well-formed templates ready for Plan 17-10 to fill
    4. Run `vendor/bin/pest tests/Contracts/GsdLeakageTest.php` → green (proves the docs don't leak the narrower-scan pattern set)
    5. Push to a draft branch + view via `gh browse` — render the docs on GitHub
    6. Reply with `approved` + screenshots of GitHub-rendered ADR + architecture topic samples
  </how-to-verify>
  <resume-signal>Type `approved` with rendered samples, OR describe corrections needed.</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| .docs/ committed to public repo | Tampered ADRs could mislead future contributors about why decisions were made |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-09c-01 | Repudiation | future contributor rewriting an ADR to misrepresent the original decision | accept | ADRs are append-only by convention; superseding decisions land as new ADRs that reference the prior one's number |
| T-17-09c-02 | Information disclosure | architecture topics revealing security-sensitive implementation detail | accept | Standard architectural prose; nothing revealed that's not visible from the source |
</threat_model>

<verification>
After both tasks + checkpoint:

1. 10 ADRs materialize + adr/00-index.md is the entry point
2. 5 architecture topics + architecture/00-index.md
3. 4 features/_template files
4. noGsdLeakage green (.docs/ scan with narrower pattern set)
5. composer test green
</verification>

<success_criteria>
- All 6 must_haves true
- 10 ADRs cover the v1.0.0 architectural baseline
- Architecture topics are substantive (not just heading skeletons)
- Features template ready for 17-10 to fill per-module
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-09c-SUMMARY.md` capturing: the 10 ADR titles, the 5 architecture topic titles, and any structural deviations from happklaar's shape.
</output>
