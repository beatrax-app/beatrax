---
phase: 16-developer-mode-ui
plan: 06
subsystem: dev-mode-queue-inspector-horizon-iframe
tags: [livewire, eloquent, queue, failed_jobs, job_batches, triple-gate, horizon-iframe, dashboard-toast, dev-sidebar-items, d-32, d-33, d-34, d-35, d-36, d-37, d-38]

# Dependency graph
requires:
  - phase: 16-developer-mode-ui
    plan: 04b
    provides: "TripleGateModal globally mounted in dev-shell.blade.php (Livewire.dispatch('triple-gate:open') with command + args). SpatieAuditWriter bound to AuditWriter contract. AuditEvent enum (the I-5 fix taxonomy) at Modules/DevMode/Internal/Enums/AuditEvent.php — extended here with eight new queue.* cases. Route::has() per-item gate in dev-shell.blade.php (the W-3 mechanism the new DevSidebarItems service builds on)."
  - phase: 16-developer-mode-ui
    plan: 05
    provides: "RedactSecretsProcessor (Bearer + JWT + OAuth scrub-set) at Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php — consumed by the inline JSON payload viewer (D-36) so every payload string passes through scrub() before render. The Wave 5 sibling-plan parallelism (16-05 + 16-06 + 16-07) is the load-bearing reason this plan introduces the DevSidebarItems registry as a single source of truth for the dev-shell layout."
provides:
  - "Modules/DevMode/Public/Models/{Job,FailedJob,JobBatch}.php — read-only Eloquent models over the framework-managed `jobs` / `failed_jobs` / `job_batches` tables. Public so other modules can import via the `Public` surface (none do today; the Livewire page reads via the raw query builder per the larastan-strict pattern 16-04b established, but the models stay for future code paths)."
  - "Modules/DevMode/Internal/Queue/QueueActions.php — `final readonly` programmatic seam for every queue action (D-33). Constructor DI on FailedJobProviderInterface + BatchRepository + QueueFactory + DatabaseManager + AuditWriter + CurrentUser (W-4 fix — Bus facade banned). Each method writes one `dev_mode_audit` row via the AuditEvent enum (I-5 fix). Methods: forgetFailed / retryFailed / deletePending / cancelBatch / deleteBatch / retryBatchFailures / bulkRetry / bulkDelete (kind=pending|failed|batches)."
  - "Modules/DevMode/Internal/Enums/AuditEvent.php — EXTENDED in place with eight new cases (queue.pending.delete, queue.failed.{forget,retry}, queue.batch.{cancel,delete,retry-failures}, queue.bulk.{delete,retry}). Every write through SpatieAuditWriter passes one of these values via `recordDestructiveQueueAction($event->value, ...)`. The enum is the canonical taxonomy boundary — no free-form action strings ever land at the writer."
  - "Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php — single Livewire component backing `/dev/queue/pending|failed|batches` (D-32). Count tiles header with wire:poll(5s) (D-35); per-row actions wired to QueueActions; bulk select with bulk-retry single-confirm modal AND bulk-delete triple-gate dispatch (D-34); inline JSON payload viewer that runs RedactSecretsProcessor::scrub() before render (D-36). Method-DI on every action + render per PATTERN B."
  - "Modules/DevMode/Internal/Http/Livewire/HorizonFramePage.php — thin Livewire wrapper rendering a single `<iframe src=\"/horizon\">` inside the dev-shell layout. Used only when the D-38 two-signal gate registers the `dev.horizon` route."
  - "Modules/DevMode/Internal/Navigation/DevSidebarItems.php — canonical sidebar-items registry (foundation for the Wave 5 / Wave 7 plan-parallelism contract per the W-3 fix). The dev-shell layout `@injects` this service to source the labels/slugs/icons; the `enabled` field is informational (each owning plan flips its slug as the route lands). The runtime truth that drives the `nav-disabled` class is still `Route::has('dev.{slug}')` so config drift naturally surfaces. The Horizon entry uses the sentinel value `enabled => 'conditional'` so the layout DROPS the item entirely (DOM-absent, NOT nav-disabled) when D-38's two signals fail."
  - "Three new dev routes: GET `/dev/queue` (redirect to `/dev/queue/pending`), GET `/dev/queue/{tab}` (canonical name `dev.queue.tab`, where-constraint `pending|failed|batches`), GET `/dev/horizon` (conditional — registered ONLY when `config('app.dev_mode')===true` AND `class_exists(Laravel\\Horizon\\HorizonServiceProvider::class)` per D-38)."
  - "Modules/Core/Internal/Http/Livewire/Dashboard.php + Modules/Core/Resources/views/livewire/dashboard.blade.php — failed-chain-resolution toast retargeted (D-37). New `$isDeveloper` boolean from render() gates the toast; non-developers see no Horizon-style queue messaging on the dashboard (their channel is the existing SystemAlertsBanner). Toast link href flips from a hardcoded `/horizon/failed` literal to `route('dev.queue.tab', ['tab' => 'failed'])` (B-3 fix — never a separate `dev.queue.failed` route alias). Copy locked per UI-SPEC: body 'Chain resolution failed.', action 'Open Queue Inspector'."
  - "DevSidebarItems is bound as a container singleton in DevModeServiceProvider::register(); the dev-shell layout pulls it via @inject."
affects:
  - 16-07-doctor-sql-system
  - 16-08-command-palette

# Tech tracking
tech-stack:
  added:
    - "No new packages — every piece reuses 16-04b's audit/triple-gate + 16-05's RedactSecretsProcessor + Laravel's bundled FailedJobProviderInterface + BatchRepository + QueueFactory + DatabaseManager."
  patterns:
    - "Triple-gate composition via dispatcher discriminator — QueueInspectorPage::bulkDeleteRequest() dispatches `triple-gate:open` with command='queue.bulk.delete'; the modal's `triple-gate:confirmed` listener in the page discriminates on the command string so unrelated destructive confirms (artisan-tier) cannot accidentally delete queue rows. The pattern future destructive surfaces (SQL panel deletes in 16-07, palette actions in 16-08) should mirror — keep a unique `command` discriminator per consumer."
    - "Raw query builder over Eloquent for Livewire list reads — the same larastan-strict pattern 16-04b established for ArtisanRunnerPage + AuditLogPage. The Public/Models/* Eloquent models exist for future code that prefers Eloquent, but the QueueInspectorPage::render() pulls rows via `$db->connection()->table(...)->get()` to sidestep the Eloquent\\Builder __call → Query\\Builder forwarding that triggers staticMethod.dynamicCall flags."
    - "DevSidebarItems registry as a single source of truth for the dev-shell sidebar — Wave 5 / Wave 7 plan-parallelism foundation. Each owning plan edits exactly one `enabled` field; the dual-representation (constant + Route::has) surfaces config drift rather than masking it. Future plans extending the dev sidebar should add new entries here AND register their routes; the layout picks up the rendering automatically."
    - "Horizon iframe two-signal gate — both `config('app.dev_mode')===true` AND `class_exists(Laravel\\Horizon\\HorizonServiceProvider::class)` must be true for the route to register. Mirrors `bootstrap/providers.php` Phase 14 D-04 pattern. The Routes/web.php file uses an inline FQCN class_exists() call inside a name-conflict shim (a local `use App\\Providers\\HorizonServiceProvider` is also imported + referenced so Pint's `fully_qualified_strict_types` fixer cannot hoist the `Laravel\\Horizon\\HorizonServiceProvider` reference into a top-of-file use — which the `noHorizonImportsInShippedBuildCode` arch invariant would catch). Future conditional imports of dev-only packages should mirror this shim."
    - "DOM-absent vs nav-disabled discrimination in the sidebar — the dev-shell layout reads each entry's `enabled` field; `'conditional'` entries are SKIPPED entirely when their `Route::has()` resolves false, while every other entry renders with `nav-disabled` when its route is absent. The conditional sentinel value is specific to D-38; future conditional dev surfaces should reuse the same sentinel."

key-files:
  created:
    - "Modules/DevMode/Public/Models/Job.php"
    - "Modules/DevMode/Public/Models/FailedJob.php"
    - "Modules/DevMode/Public/Models/JobBatch.php"
    - "Modules/DevMode/Internal/Queue/QueueActions.php"
    - "Modules/DevMode/Internal/Navigation/DevSidebarItems.php"
    - "Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php"
    - "Modules/DevMode/Internal/Http/Livewire/HorizonFramePage.php"
    - "Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/horizon-frame-page.blade.php"
    - "Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php"
    - "Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php"
    - "Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php"
    - "Modules/DevMode/tests/Feature/DashboardFailedToastGatingTest.php"
  modified:
    - "Modules/DevMode/Internal/Enums/AuditEvent.php — extended with 8 new queue.* cases (I-5 fix taxonomy)"
    - "Modules/DevMode/Providers/DevModeServiceProvider.php — registers QueueInspectorPage + HorizonFramePage as Livewire components; binds DevSidebarItems as a container singleton"
    - "Modules/DevMode/Routes/web.php — adds dev.queue redirect + dev.queue.tab routes (always-on); adds dev.horizon route inside the D-38 two-signal class_exists guard"
    - "Modules/DevMode/Resources/views/layouts/dev-shell.blade.php — @injects DevSidebarItems; sidebar item rendering loop now honours the `enabled => 'conditional'` sentinel by skipping DOM-absent entries when Route::has() resolves false"
    - "Modules/Core/Internal/Http/Livewire/Dashboard.php — render() passes the new `isDeveloper` boolean to the view (D-37 retarget plumbing)"
    - "Modules/Core/Resources/views/livewire/dashboard.blade.php — failed-chain toast gated on `$isDeveloper`; href + label retargeted to route('dev.queue.tab', ['tab' => 'failed']) + 'Open Queue Inspector' (B-3 fix — uses route() helper, never hardcoded /dev/queue/failed)"
    - "Modules/DevMode/tests/Feature/DevOverviewPageTest.php — nav-disabled count expectation updated 5 → 3 (Queue enabled this plan; Horizon DOM-absent by default); explicit assertion that the Horizon entry text is DOM-absent without dev_mode=true"
    - "Modules/Auth/tests/Feature/CrossUserIsolationTest.php — ISOLATION_ROUTE_ALLOW_LIST extended with dev.queue + dev.queue.tab + dev.horizon"
    - "Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php — existing toast-presence test flipped from assertSeeText('Open Horizon') to assertSeeText('Open Queue Inspector') + fcrtUser fixture now opts the seeded user into is_developer=true so the now-gated toast actually renders"

key-decisions:
  - "DevSidebarItems retains the runtime Route::has() guard rather than replacing it with a strict enabled-bit check. The W-3 fix language in PLAN.md considered both shapes; the dual-representation (constant + runtime check) was chosen because the alternative would mask config drift — a slug whose plan never landed but whose `enabled` field was flipped to true would silently render with an enabled-styled link pointing at nothing. The runtime check guarantees the link is nav-disabled even in that drift scenario, and the constant documents the design contract for the Wave-5/7 plan-parallelism story."
  - "QueueInspectorPage uses the raw query builder, NOT Eloquent, for list reads — direct mirror of the 16-04b pattern. The Public/Models/* models stay because the plan's `<artifacts>` block names them as Public surfaces (other modules may want them), but the page itself does not consume them at render time. The trade-off: each row is mapped via `get_object_vars($row)` + per-field is_*-narrowed reads into a normalised array shape, which the Blade view consumes. This is more verbose than `$row->queue` but satisfies the larastan-strict-rules `staticMethod.dynamicCall` + `property.dynamicName` bans."
  - "Triple-gate composition uses a `command` discriminator on the global gate event — the `triple-gate:confirmed` listener inside QueueInspectorPage::executeBulkDelete() ignores confirms whose command !== 'queue.bulk.delete'. This is necessary because the TripleGateModal is mounted globally in dev-shell.blade.php; without the discriminator, a developer confirming a destructive artisan command (e.g. `db:restore`) while the queue inspector is open would silently delete queued rows. Three of QueueBulkDeleteTripleGateTest's six cases cover the dispatch shape + the discriminator guard."
  - "Bus facade is BANNED inside QueueActions (W-4 fix). The plan's <interfaces> block called this out explicitly; the implementation uses Laravel's BatchRepository contract via constructor DI for `cancel(batchId)` + `delete(batchId)` + `find(batchId)`. For `retryBatchFailures`, the framework's Batch class does NOT expose a retry-failures method (only Queue\\Console\\RetryBatchCommand does, which is CLI-only); the implementation reads `Batch::$failedJobIds` and loops `retryFailed($uuid)` instead. Single audit row written at the bulk level via `recordDestructiveQueueAction(AuditEvent::QueueBatchRetryFailures->value, ...)`; individual retries also write their own `queue.failed.retry` rows beneath it."
  - "Horizon iframe route registration sits at the TAIL of the /dev group in Routes/web.php behind a name-conflict shim that prevents Pint's `fully_qualified_strict_types` fixer from hoisting the `Laravel\\Horizon\\HorizonServiceProvider::class` reference into a `use` line (which would fail the `noHorizonImportsInShippedBuildCode` arch invariant — only `app/Providers/HorizonServiceProvider.php` may import that namespace). The shim imports `App\\Providers\\HorizonServiceProvider` (legal — it's the local subclass) and uses it as a second-signal check inside the route body. The pattern mirrors `bootstrap/providers.php` Phase 14 D-04. The plan's `<output>` section section 2 (Horizon two-signal gating composition) reflects this."
  - "Dashboard.php passes `$isDeveloper` as a new view variable (D-37). The Blade then guards with `@if ($failedChainResolutionExists && $isDeveloper)`. The alternative — reading `auth()->user()->is_developer` inline in the Blade — was rejected per CLAUDE.md's DI-only rule (no facades / global helpers inside module code). The Livewire prop is the seam."
  - "DashboardFailedToastGatingTest seeds an ImportRun + Account + Transaction for every fixture user to bypass the Phase 15 EnsureDatabaseReady first-launch redirect. The existing Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php already uses this pattern (beforeEach()); the DashboardFailedToastGatingTest's user-factory function inlines the seed instead of using beforeEach() because each test case needs a freshly-seeded user with a different is_developer flag."
  - "DevOverviewPageTest's nav-disabled count expectation was already updated from 6 → 5 by 16-05; 16-06 takes it down further to 3 (only Doctor / SQL / System remain nav-disabled by the time 16-06 lands). The same test asserts the Horizon entry is DOM-absent when its route is not registered (i.e., when config('app.dev_mode')!==true) — this assertion captures the DOM-absent vs nav-disabled discrimination D-38 introduced."

patterns-established:
  - "Triple-gate consumer pattern with `command` discriminator — the global TripleGateModal in dev-shell.blade.php is shared across destructive surfaces; each consumer's listener for `triple-gate:confirmed` MUST check the `command` field before performing its action. The naming convention is verb-noun: 'queue.bulk.delete' / 'db.restore' / 'sql.destructive'. Future destructive surfaces should pick a unique command string and discriminate on it in their listener."
  - "Eloquent models in Public/ that the consuming page never actually uses — the Job / FailedJob / JobBatch models are exposed as Public surfaces so cross-module code may consume them, but the page itself reads via the raw query builder. The pattern is acceptable when (a) the table is framework-managed (jobs / failed_jobs / job_batches are owned by Laravel, not by a module), and (b) the larastan-strict-rules profile bans Eloquent\\Builder dynamic-call narrowing in the consuming page. Future similar surfaces (e.g. read-only access to laravel_sessions in 16-07's system page) should mirror this shape."
  - "DevSidebarItems registry — sidebar nav-item list lives in one place (Modules/DevMode/Internal/Navigation/DevSidebarItems.php); the dev-shell layout @injects the service via Blade's @inject; each owning plan flips its slug's `enabled` field to `true` when its route lands. The runtime `Route::has()` guard at the Blade layer is the ultimate source of truth — drift between the constant and Route::has resolves naturally at render time (a slug marked enabled here but whose route is absent still renders nav-disabled). 16-07's plans should mirror this — each one flips Doctor / SQL / System to enabled in the constant AND registers the matching route. The Horizon entry's `enabled => 'conditional'` sentinel value should not be reused for non-conditional items."
  - "Horizon-import-safe class_exists shim — when a dev-only package's class must be referenced inside a `config('app.dev_mode')` guard outside the package's own provider, use a name-conflict shim: import a LOCAL same-short-name class (App\\Providers\\HorizonServiceProvider) and reference it in the body, then write the gated `class_exists(Laravel\\Horizon\\HorizonServiceProvider::class)` inline (no `use` statement). Pint's `fully_qualified_strict_types` fixer refuses to hoist the FQCN because the short-name slot is already taken. The arch test's regex strip handles the inline `class_exists(Laravel\\Horizon\\...)` form."
  - "DOM-absent vs nav-disabled discrimination — the dev-shell sidebar's render loop checks each item's `enabled` field; `'conditional'` entries skip rendering when their `Route::has()` resolves false (the route is absent → the link target doesn't exist → the link should not appear at all). Every other entry with a missing route renders WITH the nav-disabled class (the route's plan hasn't landed yet → the link target SHOULD exist but doesn't → render the placeholder). The discrimination matters for D-38: a partner account observing the dev-shell layout (which they cannot reach behind the EnsureDeveloperMode middleware, but the discrimination is defence-in-depth) sees no Horizon affordance at all on a shipped build."

requirements-completed: [DEVUI-05, DEVUI-08]

# Metrics
duration: 90min
completed: 2026-05-24
---

# Phase 16 Plan 06: Queue Inspector + Horizon Iframe (D-32/D-33/D-34/D-35/D-36/D-37/D-38) Summary

**`/dev/queue/{pending|failed|batches}` three-tab inspector with per-row + bulk actions + count-tile polling + redacted inline JSON payload viewer, embedded Horizon iframe behind the D-38 two-signal gate, and the dashboard failed-chain-resolution toast retargeted + developer-gated end-to-end. DEVUI-05 + DEVUI-08 fully satisfied.**

## Performance

- **Duration:** ~90 min (env bootstrap + 2 atomic task commits + verification)
- **Tasks:** 2 (both autonomous; combined RED+GREEN per `<done>` criteria)
- **Commits:** 2 atomic task commits + 1 final docs commit (this SUMMARY)
- **Files created:** 13
- **Files modified:** 9
- **Test growth:** 2320 baseline (Wave 4 + 16-05 + new 16-06) - 32 pre-16-06 = +32 new tests (11 QueueInspectorActions + 6 QueueBulkDeleteTripleGate + 8 HorizonIframeGating + 4 DashboardFailedToastGating + 1 DevOverviewPageTest update + 1 FailedChainResolutionToastTest update + 1 ArtisanRunnerSafeTier sidebar nav-disabled count check)
- **Larastan L10 strict:** clean (0 errors across 615 analyzed files)
- **Pint:** clean
- **CrossUserIsolationTest:** 9 passed (allow-list extended with dev.queue + dev.queue.tab + dev.horizon)
- **BoundaryArchTest:** 45 passed (everyDevModeRouteAppliesEnsureDeveloperModeMiddleware naturally covers the 3 new /dev/queue + /dev/horizon routes)

## Accomplishments

1. **`/dev/queue/{tab}` deep-linkable three-tab inspector (D-32).** Single QueueInspectorPage Livewire component backs `/dev/queue/pending`, `/dev/queue/failed`, `/dev/queue/batches`. The `/dev/queue` bare URL redirects to the default `pending` tab. The canonical route name is `dev.queue.tab`; every deep-link consumer (the dashboard toast in Task 2; any future palette in 16-08) builds URLs via `route('dev.queue.tab', ['tab' => 'failed'])` per the B-3 fix.
2. **Per-row + bulk actions wired through QueueActions (D-33 / D-34).** Pending: delete | Failed: retry / forget | Batches: retry-failures / cancel / delete. Bulk retry uses a single-confirm Flux modal (`bulk-retry-confirm`); bulk delete dispatches `triple-gate:open` against the global TripleGateModal from 16-04b. The triple-gate's `triple-gate:confirmed` listener on QueueInspectorPage discriminates on the command string (`'queue.bulk.delete'`) so unrelated destructive confirms (artisan-tier) cannot accidentally delete queue rows.
3. **Count tiles with wire:poll(5s) (D-35).** Three header tiles (Pending / Failed / Batches) refresh every 5 seconds. The Failed tile turns rose when count > 0; the Batches tile counts only active batches (cancelled_at IS NULL AND finished_at IS NULL).
4. **Inline JSON payload viewer with three-layer redaction (D-36).** Click any row → expand panel → pretty-printed JSON payload passed through RedactSecretsProcessor::scrub(). The viewer reads the `payload` column (jobs / failed_jobs) or the `options` column (job_batches). One expanded row at a time.
5. **Horizon iframe behind the D-38 two-signal gate.** `/dev/horizon` route registers only when both `config('app.dev_mode')===true` AND `class_exists(Laravel\\Horizon\\HorizonServiceProvider::class)` are true. Sidebar entry is DOM-absent (not nav-disabled) when either signal is false. The Routes/web.php inline FQCN passes the `noHorizonImportsInShippedBuildCode` arch invariant via the same `class_exists()` strip the bootstrap/providers.php pattern uses, plus a name-conflict shim that keeps Pint's `fully_qualified_strict_types` fixer from hoisting the FQCN.
6. **Dashboard failed-chain-resolution toast retarget (D-37).** Toast body and action label flip from "Open Horizon" + `/horizon/failed` to "Open Queue Inspector" + `route('dev.queue.tab', ['tab' => 'failed'])`. Toast is gated on `$isDeveloper` — non-developers see no Horizon-style queue messaging on the dashboard. Their channel remains the existing SystemAlertsBanner.
7. **DevSidebarItems registry (the W-3 foundation).** New singleton service holds the canonical sidebar nav-item list; the dev-shell layout `@injects` it instead of inlining the array. Each owning plan flips its slug's `enabled` field. The runtime `Route::has()` guard at the Blade layer is still the source of truth for nav-disabled. The Horizon entry uses the sentinel `'conditional'` value so the layout DROPS the item entirely (DOM-absent) when D-38's signals fail.

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 | `32d584e` | feat(16-06): queue inspector + QueueActions + DevSidebarItems registry (Task 1) |
| 2 | `e2d535d` | feat(16-06): D-38 Horizon iframe gating tests + D-37 dashboard failed-chain toast retarget (Task 2) |

Plan metadata commit (this SUMMARY): pending — orchestrator owns the final commit per worktree workflow.

## Plan Output Section Answers

The plan's `<output>` block asks for explicit answers to four open documentation items:

1. **DevSidebarItems service pattern + how 16-05 / 16-07 flip their slug.** The registry lives at `Modules/DevMode/Internal/Navigation/DevSidebarItems.php`. Each item is `['slug' => …, 'label' => …, 'icon' => …, 'route' => 'dev.{slug}', 'enabled' => true|false|'conditional']`. Each owning plan edits exactly one `enabled` entry when its route lands. 16-05's Logs entry is already `enabled => true` (THIS plan's constant captures it); 16-07 will flip Doctor / SQL / System. The Horizon entry stays `'conditional'` forever.
2. **Horizon two-signal gating composition with app/Providers/HorizonServiceProvider.php.** The local `App\\Providers\\HorizonServiceProvider` (Phase 14 D-04) already early-exits when `config('app.dev_mode')!==true` — that's the package-level guard. THIS plan's `Modules/DevMode/Routes/web.php` adds a SECOND guard at the iframe-route layer: `config('app.dev_mode')===true && class_exists(Laravel\\Horizon\\HorizonServiceProvider::class)`. The composition is multiplicative: even if the env var leaked into a shipped build (it shouldn't), the `class_exists` signal would still drop the iframe route because the package is absent in a `--no-dev` tree. The two signals enforce the same invariant at two layers — defence in depth per the D-38 design.
3. **`/dev/queue` redirect to `/dev/queue/pending`.** The bare `/dev/queue` URL is a 302 redirect to `/dev/queue/pending` purely as a UX convenience — bookmarking `/dev/queue` always returns to the default tab. The canonical route name is `dev.queue.tab` (with the `tab` route parameter); the redirect's route name is `dev.queue`. The `Route::middleware(['web', 'auth', 'ensureDeveloperMode'])` group wraps both routes so the EnsureDeveloperMode gate applies to the redirect target as well as the canonical URL.
4. **`php artisan queue:flush` as a SAFE-tier registry entry.** NO — not added. T-16-27 (DoS via bulk delete on millions of rows) was disposition='accept' per the plan's threat register; the SAFE-tier registry remains the 16-04 set. A developer who needs to flush every failed job uses the CLI (`php artisan queue:flush`) — explicitly out of the Dev Console UI to keep the surface narrow.

## Decisions Made

See `key-decisions` in the frontmatter for the full list. Quick recap of the most consequential:

- **DevSidebarItems is informational + Route::has-driven.** The dual representation surfaces config drift; a strict allow-list-only approach would mask it.
- **Raw query builder over Eloquent for list reads.** Mirrors 16-04b's larastan-strict pattern; the Public/Models/* models stay available for future code.
- **Triple-gate command discriminator (`'queue.bulk.delete'`).** Prevents cross-consumer accidents when the global modal is shared between artisan-tier destructive and queue bulk delete.
- **Bus facade is BANNED (W-4).** BatchRepository contract injected directly; retry-failures iterates `Batch::$failedJobIds` and dispatches per-uuid retries.
- **Horizon class_exists name-conflict shim.** Local `App\\Providers\\HorizonServiceProvider` import + reference keeps Pint from hoisting the `Laravel\\Horizon\\HorizonServiceProvider` FQCN.
- **`$isDeveloper` passes to the Dashboard view as a Livewire prop** — no `auth()` global / Auth facade inside Blade.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Larastan L10 — dynamic Eloquent\\Builder calls on Job/FailedJob/JobBatch models**
- **Found during:** Task 1 Larastan run
- **Issue:** The first cut of QueueInspectorPage::render() used `Job::query()->count()` / `FailedJob::query()->orderByDesc('id')->limit(100)->get()` etc. Every method except `query()` itself is forwarded by Eloquent\\Builder via `__call` to Query\\Builder, which triggers larastan-strict-rules' `staticMethod.dynamicCall` flag for each. Same shape 16-04b hit on ArtisanRunnerPage.
- **Fix:** Switched the list-read path to `$db->connection()->table('jobs|failed_jobs|job_batches')->...->get()` (the raw query builder declares every method directly). The page renders rows via a `mapPendingRows() / mapFailedRows() / mapBatchRows()` helper that normalises each stdClass row into a typed array shape; the Blade consumes the array. The Public/Models/* Eloquent classes still exist; they're just not used at render time.
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php`, `Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php`.
- **Verification:** Larastan L10 strict clean across the full codebase.
- **Committed in:** `32d584e` (Task 1).

**2. [Rule 1 — Bug] Larastan L10 — `Access to an undefined property object::$xxx` on raw query builder rows + FailedJobProviderInterface::find() return**
- **Found during:** Task 1 Larastan run
- **Issue:** `$db->connection()->table('jobs')->get()` returns `Collection<int, stdClass>`; reading `$row->queue` is flagged as undefined-property access (Larastan only knows stdClass has no declared properties). FailedJobProviderInterface::find() returns `object|null` (same story).
- **Fix:** Switched to `get_object_vars($row)` + per-key is_*-narrowed reads. Added a `readObjectStringProp()` helper inside QueueActions that uses `get_object_vars()` + array access (avoids the `property.dynamicName` flag a `$row->{$name}` variable property read would trigger).
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php`, `Modules/DevMode/Internal/Queue/QueueActions.php`.
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `32d584e` (Task 1).

**3. [Rule 1 — Bug] Larastan L10 — `is_string` always-true narrowing in QueueActions::bulkDelete**
- **Found during:** Task 1 Larastan run
- **Issue:** `if (is_int($id) || (is_string($id) && ctype_digit($id))) {...}` — after `is_int($id)` short-circuits false, Larastan narrows `$id` to `string` for the right-hand side, so the second `is_string($id)` is always true.
- **Fix:** Split into a true if/elseif chain: `if (is_int($id)) {...} elseif (ctype_digit($id)) {...}`. The second `ctype_digit($id)` already implies a string narrowing.
- **Files modified:** `Modules/DevMode/Internal/Queue/QueueActions.php`.
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `32d584e` (Task 1).

**4. [Rule 1 — Bug] Larastan L10 — `Collection<int, object>` covariance flag on mapper helpers**
- **Found during:** Task 1 Larastan run
- **Issue:** Mapper helpers like `mapPendingRows(\\Illuminate\\Support\\Collection $raw)` typed `$raw` as `Collection<int, object>` but received `Collection<int, stdClass>`. Larastan's `Collection` template parameter is invariant — passing a more-specific subtype is flagged as `argument.type`.
- **Fix:** Narrowed the parameter type to `Collection<int, \\stdClass>` to match what the raw query builder actually returns.
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php`.
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `32d584e` (Task 1).

**5. [Rule 1 — Bug] Pint `fully_qualified_strict_types` fixer collides with `noHorizonImportsInShippedBuildCode` arch invariant**
- **Found during:** Task 1 Pint + arch runs
- **Issue:** The first cut of `Modules/DevMode/Routes/web.php` used `class_exists(\\Laravel\\Horizon\\HorizonServiceProvider::class)` inline. Pint's `fully_qualified_strict_types` fixer hoisted the FQCN into a top-of-file `use Laravel\\Horizon\\HorizonServiceProvider;` import, which `noHorizonImportsInShippedBuildCode` flags (the arch test's class_exists() strip handles the inline form but not the use statement).
- **Fix:** Applied the same name-conflict shim `bootstrap/providers.php` uses (Phase 14 D-04): import `App\\Providers\\HorizonServiceProvider` (legal — local class) AND reference it in the route body as a second-signal `class_exists($horizonProviderClass)` check. Pint refuses to hoist the `Laravel\\Horizon\\HorizonServiceProvider::class` FQCN into a use statement because the short-name slot `HorizonServiceProvider` is already taken — the inline FQCN form persists, which the arch test's strip allows. Documented verbatim in the route comment.
- **Files modified:** `Modules/DevMode/Routes/web.php`.
- **Verification:** Pint passes (no_unused_imports satisfied; the local provider IS referenced in the route body). `noHorizonImportsInShippedBuildCode` passes. `no Laravel facade usage in module code` passes.
- **Committed in:** `32d584e` (Task 1).

**6. [Rule 1 — Bug] DevSidebarItems had an unused `use Illuminate\\Support\\Facades\\Route` import that Pint auto-added**
- **Found during:** Task 1 Pint + arch runs
- **Issue:** The first Pint pass on `Modules/DevMode/Internal/Navigation/DevSidebarItems.php` added a `use Illuminate\\Support\\Facades\\Route;` import because the file mentioned `Route` in PHPDoc. The `no Laravel facade usage in module code` arch invariant then flagged the import.
- **Fix:** Removed the unused use statement; Pint's no_unused_imports rule no longer adds it (the PHPDoc reference is plain text, not a code-level use).
- **Files modified:** `Modules/DevMode/Internal/Navigation/DevSidebarItems.php`.
- **Verification:** `no Laravel facade usage in module code` passes.
- **Committed in:** `32d584e` (Task 1).

**7. [Rule 1 — Bug] HorizonIframeGatingTest's route-reload helper did not call `RouteCollection::refreshNameLookups()`**
- **Found during:** Task 2 HorizonIframeGating first run
- **Issue:** The test reset routes via `Route::setRoutes(new RouteCollection)` + `require base_path('Modules/DevMode/Routes/web.php')`, but `Route::has('dev.horizon')` continued to return false. Laravel's RouteCollection keeps a SEPARATE `$nameList` map that's only refreshed when `refreshNameLookups()` is called; `Route::has()` reads that map, NOT the live route list. Verified by inspecting the runtime state with a one-off PHP script.
- **Fix:** Hoisted the route-reload logic into a `horizonGatingReloadRoutes()` helper that resets the collection + re-requires the routes file + calls `app(Router::class)->getRoutes()->refreshNameLookups()`. Documented the refreshNameLookups requirement verbatim in the helper's PHPDoc.
- **Files modified:** `Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php`.
- **Verification:** All 8 Horizon iframe gating tests pass.
- **Committed in:** `e2d535d` (Task 2).

**8. [Rule 2 — Missing critical] DashboardFailedToastGatingTest's seeded user redirected at /</span> because of the Phase 15 EnsureDatabaseReady first-launch gate**
- **Found during:** Task 2 DashboardFailedToastGating first run
- **Issue:** The first cut of dashFailedToastUser() created a User but didn't seed any ImportRun / Account / Transaction. The Phase 15 EnsureDatabaseReady middleware redirects /</span> to /imports/new when the acting user has no transactions, so the test's `$response->assertOk()` failed with 302. Mirror the pattern Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php already uses.
- **Fix:** Extended the fixture to seed one ImportRun + one Account + one Transaction per user. The transaction shape is identical to the FailedChainResolutionToastTest fixture (asn-csv source, fingerprint padded to 64 chars, version 3).
- **Files modified:** `Modules/DevMode/tests/Feature/DashboardFailedToastGatingTest.php`.
- **Verification:** All 4 dashboard toast gating tests pass.
- **Committed in:** `e2d535d` (Task 2).

**9. [Rule 2 — Missing critical] FailedChainResolutionToastTest's existing assertSeeText('Open Horizon') broke under D-37's retarget**
- **Found during:** Task 2 full DevMode + Chains test run
- **Issue:** The Chains module's existing FailedChainResolutionToastTest::it failed-job toast renders... case asserted `assertSeeText('Open Horizon')` against the dashboard render. D-37 retargeted the copy + the href; the existing assertion was now stale.
- **Fix:** Flipped the assertion to `assertSeeText('Open Queue Inspector')`. Also opted the fcrtUser fixture into `is_developer => true` because the toast is now gated on `$isDeveloper`. The cross-user tests (non-developer + the empty-state tests) keep their assertDontSeeText('Chain resolution failed') checks intact.
- **Files modified:** `Modules/Chains/tests/Feature/FailedChainResolutionToastTest.php`.
- **Verification:** All 3 Chains module FailedChainResolutionToastTest cases pass.
- **Committed in:** `e2d535d` (Task 2).

**10. [Rule 2 — Missing critical] DevOverviewPageTest nav-disabled count expectation became stale (5 → 3)**
- **Found during:** Task 1 sidebar update + Task 1 full DevMode test run
- **Issue:** 16-05 had already updated the test to expect 5 nav-disabled entries. 16-06 enables Queue (-1) AND drops Horizon from the DOM when its route is absent (-1 — DOM-absent vs disabled), so the count drops to 3 (Doctor / SQL / System).
- **Fix:** Updated the expected count to 3; added explicit assertion that the Horizon entry text is DOM-absent without `config('app.dev_mode')===true`; renamed the test to reflect the new shape ("Overview + Artisan + Audit + Logs + Queue enabled (16-06 registers dev.queue) and Horizon DOM-absent when its route is not registered (D-38)").
- **Files modified:** `Modules/DevMode/tests/Feature/DevOverviewPageTest.php`.
- **Verification:** Test passes.
- **Committed in:** `32d584e` (Task 1).

**11. [Rule 1 — Bug] Pint applied cosmetic fixes (FQN imports, ordered_imports, unary_operator_spaces, etc.)**
- **Found during:** Pint --test runs after each task
- **Issue:** Pint flagged `fully_qualified_strict_types` + `ordered_imports` + `unary_operator_spaces` + `not_operator_with_successor_space` + `single_line_empty_body` + `braces_position` + `blank_line_after_namespace` on multiple new/modified files.
- **Fix:** Ran `vendor/bin/pint` to apply each fix. Cosmetic-only; no behaviour change. Each task's commit includes the post-Pint state.
- **Files modified:** Various.
- **Verification:** `vendor/bin/pint --test` passes after each pass.
- **Committed in:** `32d584e` + `e2d535d` (each task's commit; bundled).

---

**Total deviations:** 11 auto-fixed (7 Rule 1 bug; 3 Rule 2 missing critical; 0 Rule 3 blocking; 0 Rule 4 architectural). All 11 are necessary follow-throughs of the plan's intent. No scope creep.

## Issues Encountered

- **`Route::setRoutes(new RouteCollection)` + `require routes/web.php` does NOT update `Route::has()` results.** Laravel maintains a separate name-lookup map (`RouteCollection::$nameList`) populated lazily via `refreshNameLookups()`. The reload helper now calls it explicitly. Documented in the test file's helper PHPDoc for future tests that need to flip route conditions at runtime.
- **Pint's `fully_qualified_strict_types` fixer auto-hoists FQCN references.** Two files hit this — the Routes/web.php and the HorizonIframeGatingTest. The Routes file was solved via the name-conflict shim from bootstrap/providers.php; the test file landed inside `/tests/` which the `noHorizonImportsInShippedBuildCode` arch test allow-lists, so Pint's hoist is benign there.

## User Setup Required

None — no external service configuration. The two D-38 signals (`config('app.dev_mode')` and Horizon package install) are platform-level concerns the operator has already set up before reaching this plan. The dashboard toast retarget is automatic for every developer; non-developers' channel is the existing SystemAlertsBanner.

## Next Phase Readiness

- **16-07 (doctor + SQL + system):** Independent of this plan. Register `dev.doctor` + `dev.sql` + `dev.system` routes inside the existing `/dev` group; flip `enabled => true` on those three slugs in `Modules/DevMode/Internal/Navigation/DevSidebarItems.php::ITEMS`. The dev-shell sidebar's `Route::has(...)` guard + the runtime nav-disabled drop will fire automatically. The SQL panel's audit row (recordSelectQuery) already routes through 16-04b's SpatieAuditWriter (which routes through 16-05's RedactionExcerptCap); no further wiring needed there.
- **16-08 (command palette):** Independent. The palette's source registries are 16-08's concern. If the palette wants to add a "Open Queue Inspector" command, it should build the URL via `route('dev.queue.tab', ['tab' => 'failed'])` exactly as the dashboard toast does (the B-3 fix shape).
- **Future destructive surfaces sharing the global TripleGateModal** should pick a unique `command` discriminator (e.g. `'sql.destructive'` / `'palette.destructive'`) and follow the QueueInspectorPage::executeBulkDelete() listener pattern: check the command field, then perform the action.

## Self-Check: PASSED

Files asserted present:

- `Modules/DevMode/Public/Models/Job.php` — FOUND
- `Modules/DevMode/Public/Models/FailedJob.php` — FOUND
- `Modules/DevMode/Public/Models/JobBatch.php` — FOUND
- `Modules/DevMode/Internal/Queue/QueueActions.php` — FOUND
- `Modules/DevMode/Internal/Navigation/DevSidebarItems.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/HorizonFramePage.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/horizon-frame-page.blade.php` — FOUND
- `Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php` — FOUND
- `Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php` — FOUND
- `Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php` — FOUND
- `Modules/DevMode/tests/Feature/DashboardFailedToastGatingTest.php` — FOUND

Commits asserted present:

- `32d584e` (Task 1 — queue inspector + QueueActions + DevSidebarItems registry) — FOUND
- `e2d535d` (Task 2 — Horizon iframe gating tests + Dashboard toast retarget) — FOUND

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
