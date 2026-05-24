---
phase: 16-developer-mode-ui
plan: 08
subsystem: dev-mode-command-palette
tags: [livewire, alpine, fuse-js, keybind, command-palette, recent-cache, app-menu, sidebar-live-data, devui-09, mvp]

# Dependency graph
requires:
  - phase: 16-developer-mode-ui
    plan: 01
    provides: "AppSidebar component with the static 'Queue 0 · Worker —' placeholder + the side-dev-block server-side gate. 16-08 replaces the placeholder with the real cache + jobs.count() reads."
  - phase: 16-developer-mode-ui
    plan: 03
    provides: "NullNavigationRegistry + NullAppActionRegistry default bindings. 16-08 swaps both for the concrete *Impl bindings inside DevModeServiceProvider::register() — Pattern C swap."
  - phase: 16-developer-mode-ui
    plan: 04
    provides: "DevCommandRegistry concrete bound to the SAFE + DESTRUCTIVE roster. 16-08 reads `safe()` at JSON-emit time inside CommandPaletteModal — DESTRUCTIVE intentionally excluded per D-41."
  - phase: 16-developer-mode-ui
    plan: 04b
    provides: "WriteWorkerHeartbeat::CACHE_KEY (`dev_mode.queue_worker_heartbeat`) + the TripleGateModal mounted globally in dev-shell. The sidebar Dev block now reads the same cache key so freshness reflects the running queue:work daemon."
  - phase: 16-developer-mode-ui
    plan: 06
    provides: "Route name conventions for the Dev Console sub-routes (dev.queue redirect + dev.queue.tab + dev.horizon). 16-08's NavigationRegistry roster names match these so the palette deep-links resolve."

provides:
  - "NavigationRegistryImpl (Modules/DevMode/Internal/Navigation/NavigationRegistryImpl.php) — final readonly concrete replacing 16-03's NullNavigationRegistry binding. Constructed by DevModeServiceProvider with the full main-app (10 entries) + Dev Console (9 sub-routes) NavigationEntry roster."
  - "AppActionRegistryImpl (Modules/DevMode/Internal/Navigation/AppActionRegistryImpl.php) — final readonly concrete replacing 16-03's NullAppActionRegistry binding. Phase 15 app-menu intent surface ('Run import', 'Scan email now', 'Open profile', 'Toggle theme')."
  - "CommandPaletteModal (Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php) — global Livewire modal mounted in both base layouts. buildRegistry() merges the three sources at JSON-emit time, filtering dev rows by is_developer (T-16-33 + T-16-34 defense-in-depth). pickEntry() writes per-user Recent cache (5 entries, 30d TTL, deduped). Listens for `palette:open` + `palette:picked` Livewire events via #[On]."
  - "NavigationEntry + AppAction DTOs gain a stable `id` field — used as the Fuse.js key + the Recent cache dedupe key. Cross-module exchange shape; the public surface."
  - "resources/js/palette.js — Alpine factory that wraps Fuse.js with the LOCKED weights (label 0.65, hint 0.20, keywords 0.15) + threshold 0.35 + ignoreLocation true per UI-SPEC § Component inventory. Registered via `alpine:init` in resources/js/app.js. NO inline `<script>` blocks in Blade (W-7)."
  - "resources/views/layouts/app.blade.php — body Alpine x-data onKey() handler dispatches `palette:open` on ⌘K + navigates /dev on ⌘. (D-42). I-7 carve-out skips when focus is INSIDE an INPUT / TEXTAREA / contentEditable. Mounts `@livewire('dev.command-palette-modal')` inside the @auth block."
  - "Modules/DevMode/Resources/views/layouts/dev-shell.blade.php — same x-data + same palette mount so ⌘K + ⌘. work inside /dev/* too."
  - "AppSidebar.render() reads cache(dev_mode.queue_worker_heartbeat) + jobs.count() via method-DI (CacheRepository + DatabaseManager + Clock). Blade view's dev-pulse subtree carries `wire:poll.5s` so the live data refreshes every 5 seconds without re-rendering the whole sidebar."
  - "Modules/Desktop/Internal/Native/AppMenuBuilder.php — constructor-DI on CurrentUser. build() conditionally appends a Developer submenu (Open Dev Console ⌘. / ⌘K Run a command) ONLY when `is_developer=true`. Non-developer + unauthenticated branches return the submenu structurally absent (T-16-36)."
  - "5 new Pest feature tests (CommandPaletteRegistryTest) + 5 (AppSidebarDevBlockLiveDataTest) + 3 (AppMenuDeveloperSubmenuTest) + 4 (PaletteLayoutMountTest) = 17 new test cases. EnsureDeveloperModeTest's contract-resolution test updated to reflect the now-concrete Navigation + AppAction bindings."

affects: []

# Tech tracking
tech-stack:
  added:
    - "Vite-bundled palette.js consumer of fuse.js@^7 (the package was installed in 16-03; THIS plan is its first first-class consumer)"
  patterns:
    - "Pattern C swap pattern completed for NavigationRegistry + AppActionRegistry — the Null* defaults bound by 16-03 are replaced from DevModeServiceProvider::register() with the concrete Impls. No consumer-side null-check needed; the contract resolves to a non-empty list of NavigationEntry / AppAction from day one of this plan landing."
    - "Single x-data on <body> for global keybind handlers — D-42 verbatim. The handler reads metaKey || ctrlKey for cross-OS parity AND short-circuits when focus is inside a text input (I-7) so the standard browser bindings inside INPUT / TEXTAREA remain primary."
    - "Server-side filtering of palette JSON by is_developer — defense-in-depth on top of the EnsureDeveloperMode route gate. Tampering with the client-side JSON does NOT bypass the filter: non-developers literally never receive the dev row labels in their response body (T-16-33 + T-16-34)."
    - "DESTRUCTIVE-tier commands deliberately EXCLUDED from the palette (D-41) — they remain reachable only via /dev/artisan's fallback Flux modal which gates them behind the triple-gate. The palette stays a 'calmer surface' (sketch-findings language)."
    - "Per-user Recent cache at key `dev_mode.palette_recent.{userId}` — list of last 5 deduplicated palette selections, 30-day TTL. The user-scoped key + TTL pattern mirrors UI-SPEC § Palette behaviour."
    - "Fuse.js fuzzy ranking locked weights (label 0.65, hint 0.20, keywords 0.15) + threshold 0.35 + ignoreLocation true. The threshold is loose enough that 'dash' matches 'Dashboard' but tight enough that single-letter typos do not pull in unrelated rows. The ignoreLocation true is essential — without it Fuse weights character position in the haystack which produces surprising rankings on short labels."
    - "NO inline `<script>` blocks in Blade (W-7). The Alpine palette() factory lives in resources/js/palette.js (bundled via Vite) and is registered via the `alpine:init` event in resources/js/app.js."

key-files:
  created:
    - "Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php"
    - "Modules/DevMode/Internal/Navigation/NavigationRegistryImpl.php"
    - "Modules/DevMode/Internal/Navigation/AppActionRegistryImpl.php"
    - "Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php"
    - "resources/js/palette.js"
    - "Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php"
    - "Modules/Core/tests/Feature/AppSidebarDevBlockLiveDataTest.php"
    - "Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest.php"
    - "Modules/DevMode/tests/Feature/PaletteLayoutMountTest.php"
  modified:
    - "Modules/DevMode/Public/Dto/NavigationEntry.php — gained an `id` field (the Fuse.js + Recent-cache key)."
    - "Modules/DevMode/Public/Dto/AppAction.php — gained an `id` field (same reason)."
    - "Modules/DevMode/Providers/DevModeServiceProvider.php — replaced Null* bindings for NavigationRegistry + AppActionRegistry with concrete factories that materialise the entire roster lazily through the Router. Registered the CommandPaletteModal Livewire component."
    - "Modules/Core/Internal/Http/Livewire/AppSidebar.php — render() gained CacheRepository + DatabaseManager + Clock method-DI; computes $queueCount + $workerSecondsAgo for the dev block."
    - "Modules/Core/Resources/views/livewire/app-sidebar.blade.php — the dev-pulse row now renders 'Queue {N} · Worker {N}s ago' (or '—' when no heartbeat) inside a `<div wire:poll.5s>` so the live data refreshes every 5 seconds."
    - "resources/views/layouts/app.blade.php — body x-data with the onKey() handler + `@livewire('dev.command-palette-modal')` mount inside the @auth block."
    - "Modules/DevMode/Resources/views/layouts/dev-shell.blade.php — body x-data + palette mount duplicated so /dev/* responds to ⌘K + ⌘. too."
    - "Modules/Desktop/Internal/Native/AppMenuBuilder.php — constructor-DI on CurrentUser; build() appends Developer submenu conditionally."
    - "resources/js/app.js — imports + registers the palette() Alpine factory via `alpine:init`."
    - "Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php — contract-resolution test updated for the now-concrete Navigation + AppAction bindings (Rule 2 update of an outdated 16-03 assertion)."
    - "Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php — the 'filters /dev/audit?tier=destructive' case slices off the palette JSON before assertion (a pre-existing assertion would falsely pick up the palette's 'Run cache:clear' dev row label otherwise)."
    - "tests/.pest/snapshots/Snapshot/SidebarTest/...snap — regenerated to reflect the new Queue/Worker live-data copy + wire:poll attribute."

key-decisions:
  - "Queue-count choice: `jobs.count()` (pending only) — NOT a composite `jobs + failed_jobs`. Failed jobs already surface in the dashboard toast (D-37) and the dedicated /dev/queue/failed tab; conflating both into a single 'Queue {N}' would mix two distinct operational signals (work-in-progress vs work-that-failed). Documented in AppSidebar's class docblock."
  - "Palette JSON-emit-time filter for is_developer + the SAFE-only filter for dev commands — implemented inside CommandPaletteModal::buildRegistry(). Non-developers receive ZERO `source: dev` rows AND ZERO `dev.*` view rows; the dev command labels never reach their browser. The filter is the single source of truth — tampering with the client-side JSON does not bypass the gate, because the underlying routes are EnsureDeveloperMode-gated regardless."
  - "Recent cache key + dedupe + 30-day TTL design — cache key is `dev_mode.palette_recent.{userId}` (per-user, isolated; a partner account cannot poison another user's Recent). The pickEntry() listener dedupes on the canonical `id` field and prepends the new pick so the most-recent always lands at the head. Cap at 5 (RECENT_LIMIT) — slice-after-prepend evicts the oldest cleanly. TTL is 30 days (RECENT_TTL_SECONDS) — long enough to outlast a holiday break, short enough that a dormant cache key naturally clears."
  - "NavigationEntry + AppAction DTOs gained an `id` field. The Fuse.js list-rendering loop in the Blade view needs a stable key per item (`:key=\"hit.item.id\"`), AND the Recent cache needs a stable dedupe key. Adding `id` to the public DTO surface costs nothing (no migration; both DTOs are Spatie/laravel-data Data classes) and avoids two ad-hoc identifier schemes."
  - "AppMenuBuilder gains constructor DI on CurrentUser (was previously dependency-free). The container resolves AppMenuBuilder as a singleton in DesktopServiceProvider; passing CurrentUser through the constructor honours the project's DI-only rule + lets the menu re-render correctly on a full bundle relaunch (RESEARCH Pitfall 9 — the running Electron process does not re-trigger build() on auth state change; full quit + relaunch is the operator workflow)."
  - "Body keybind handler implemented as a single Alpine x-data on `<body>` (D-42). The same x-data is duplicated verbatim across resources/views/layouts/app.blade.php and Modules/DevMode/Resources/views/layouts/dev-shell.blade.php — the layouts are alternative root templates (not nested), so the directive must appear on both. The I-7 carve-out (`if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable) return`) is essential — without it a developer typing inside a text input would have the palette pop over their keystrokes."
  - "Fuse.js bundled through Vite (already in package.json from 16-03). The locked weights + threshold + ignoreLocation match the UI-SPEC contract. NO `window.Fuse` global needed — the `palette.js` module imports `fuse.js` directly and the bundler ships it as part of the app.js chunk."

patterns-established:
  - "Server-side JSON-emit-time filter for client-side payloads — when a Livewire component emits a JSON registry the client filters/renders, the privilege filter MUST sit at the server-side emit boundary. Filtering only on the client is a defense-in-name-only — anyone with browser dev tools can flip a flag. The server-side filter + the underlying route gate (EnsureDeveloperMode) together make the privilege boundary structurally enforceable."
  - "Per-user cache keys with a userId suffix + a multi-day TTL — the `dev_mode.palette_recent.{userId}` pattern is the canonical shape for per-user UX preferences that don't warrant a dedicated DB column. The userId in the key + the user-scoped read means cross-user poisoning is structurally impossible. The multi-day TTL keeps the cache key bounded (a churned user's cache naturally evicts)."
  - "Body-level x-data for global keybind handlers + I-7 text-input carve-out — the `if (t.tagName === 'INPUT' || ...) return` guard is the project's standard pattern for global key handlers that must not steal keystrokes from focused inputs. Future global keybinds (e.g. ⌘\\ to toggle sidebar) should mirror this shape."
  - "NavigationEntry + AppAction DTOs as the cross-module palette exchange shape — every nav surface contributed by a module hands a NavigationEntry to the registry; every named action contributed by a module hands an AppAction. The DTOs are the public surface; the registries are the cross-module wiring. Future cross-module palette consumers should add entries by injecting the AppActionRegistry and calling a register() variant (registry shapes the addition; this plan ships read-only Impls because the roster is centralised)."

requirements-completed: [DEVUI-09]

# Metrics
duration: 60min
completed: 2026-05-24
---

# Phase 16 Plan 08: Command Palette + Sidebar Dev-Block Live Data + App-Menu Developer Submenu Summary

**⌘K / Ctrl+K command palette lands end-to-end: NavigationRegistry + AppActionRegistry concretes replace 16-03's Null* defaults, a global Livewire CommandPaletteModal mounts in both base layouts, the Alpine x-data body handler dispatches `palette:open` on ⌘K and navigates /dev on ⌘.. Sidebar Dev block shows live queue + heartbeat with `wire:poll.5s`. AppMenuBuilder gains a conditional Developer submenu (Open Dev Console + Run-a-command) for is_developer=true. DEVUI-09 satisfied; Phase 16 ready for `/gsd:verify-work`.**

## Performance

- **Duration:** ~60 min (env bootstrap + 2 atomic task commits + verification + summary)
- **Tasks:** 2 (both autonomous; TDD; combined RED + GREEN per `<done>` criteria)
- **Commits:** 2 atomic task commits + 1 final docs commit (this SUMMARY)
- **Files created:** 9
- **Files modified:** 12
- **Test growth:** 17 new tests added (+1 fixup) — CommandPaletteRegistryTest (5), AppSidebarDevBlockLiveDataTest (5), AppMenuDeveloperSubmenuTest (3), PaletteLayoutMountTest (4). Full sequential Pest reaches **2394 passed**, 0 failed, 19 todos, 6 skipped.
- **Larastan L10 strict:** clean (0 errors across 631 analyzed files)
- **Pint:** clean on every plan-modified file (one pre-existing unrelated flag on `Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` — landed in 16-07 commit `2034b91`, out of this plan's scope).

## Accomplishments

### Task 1 — NavigationRegistry + AppActionRegistry concretes + CommandPaletteModal + Fuse.js + Recent cache (commit `862d016`)

- `NavigationRegistryImpl` + `AppActionRegistryImpl` final-readonly concretes replacing the 16-03 Null* defaults. The DevModeServiceProvider singleton factories materialise the full roster lazily through the Router (so route-name resolution happens after every module has registered its routes). Main-app nav covers 10 surfaces; Dev Console nav covers 9 sub-routes. Two missing routes (e.g. Receipts, which is not yet route-registered) drop out cleanly via `Route::has()`.
- `NavigationEntry` + `AppAction` DTOs gain a stable client-side `id` field — the Fuse.js list-rendering key + the Recent cache dedupe key.
- `CommandPaletteModal` Livewire component mounted globally. `buildRegistry()` filters dev rows by `is_developer` at JSON-emit time; non-developers receive ZERO `source: dev` rows AND ZERO `dev.*` view rows. DESTRUCTIVE-tier commands are structurally absent from the merged registry (D-41 + T-16-33). `pickEntry()` writes the Recent cache (5 entries, 30d TTL, deduped, capped via prepend + slice).
- Blade view contains NO inline `<script>` (W-7) — the Alpine `palette()` factory lives in `resources/js/palette.js`. Fuse.js wrapper with the LOCKED weights/threshold/ignoreLocation per UI-SPEC. The factory dispatches `palette:picked` to persist the selection AND navigates the user (window.location for `url`-shaped rows, Livewire dispatch for `handlerEvent` / `spawn-command` rows).
- 5 Pest tests in `CommandPaletteRegistryTest` lock the binding swap, the roster shape, the developer/non-developer JSON contract, and the Recent cache semantics.

### Task 2 — Body keybind handler + palette mount + sidebar Dev-block live data + app-menu Developer submenu (commit `9940b5a`)

- Both base layouts gain an Alpine `x-data` on `<body>` declaring `onKey($event)` which:
  - dispatches `palette:open` on ⌘K / Ctrl+K (the metaKey || ctrlKey shape per D-42)
  - navigates to `/dev` on ⌘. / Ctrl+.
  - I-7 carve-out: short-circuits when focus is inside INPUT / TEXTAREA / contentEditable so the handler does NOT steal keystrokes from focused inputs.
- Both layouts mount `@livewire('dev.command-palette-modal')` so the dispatch sink is available regardless of which layout the request resolved through.
- `AppSidebar` render() takes `CacheRepository` + `DatabaseManager` + `Clock` via method-DI. Computes `$queueCount = jobs.count()` (pending only — see Decisions Made for the not-composite rationale) + `$workerSecondsAgo` (delta from the cache key `dev_mode.queue_worker_heartbeat`; null when absent). Blade renders `Queue {N} · Worker {N}s ago` (or `Worker —` for the no-heartbeat case) inside `<div wire:poll.5s>` so the row refreshes every 5 seconds.
- `AppMenuBuilder` gains constructor DI on `CurrentUser`. `build()` appends a `Developer` submenu with two entries ("Open Dev Console" with `Cmd+.` accelerator + "⌘K Run a command" with `Cmd+K` accelerator) — both routing through `Menu::route('dev.overview', ...)` per the B-4 fix (route-name based; works in both Herd dev + shipped Electron bundles). The submenu is structurally absent for non-developer + unauthenticated branches.
- 12 new Pest tests across `AppSidebarDevBlockLiveDataTest` (5), `AppMenuDeveloperSubmenuTest` (3), `PaletteLayoutMountTest` (4). One pre-existing audit-page test (`ArtisanRunnerSafeTierTest::filters /dev/audit?tier=destructive`) was widened to slice off the now-present palette JSON before assertion — the prior assertion would have falsely picked up the palette's `Run cache:clear` dev row label.

## Plan Output Section Answers

The plan's `<output>` block asks for explicit answers on six documentation items:

### 1. Palette JSON-emit-time filter for is_developer + SAFE-only filter for dev commands

Implemented inside `CommandPaletteModal::buildRegistry()` (`Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php` lines 145-230). Two filters layered:

- **is_developer filter:** the variable `$isDeveloper = $user->isAuthenticated() && $user->user()->is_developer === true` controls (a) whether `dev.*`-prefixed NavigationEntry rows appear in the registry (`continue` when not developer AND id starts with `dev.`), AND (b) whether the DevCommandRegistry SAFE-tier loop runs at all. Non-developers receive ZERO `source: dev` rows AND ZERO `dev.*` view rows. Tampering with the client-side JSON cannot bypass either — the rows are structurally absent from the response body.
- **SAFE-only filter for dev commands:** the dev row loop reads `$commands->safe()`, never `$commands->destructive()`. DESTRUCTIVE-tier commands are structurally absent from the palette (D-41 + T-16-33). They remain reachable only via `/dev/artisan`'s fallback Flux modal which gates them behind the triple-gate.

Verified by tests #3 (developer JSON contains view + dev SAFE + action; ZERO destructive) and #4 (non-developer JSON has zero dev / dev-view rows) in `CommandPaletteRegistryTest`.

### 2. Recent cache key + dedupe + 30d TTL design

- **Cache key:** `dev_mode.palette_recent.{userId}` — per-user (so a partner account cannot poison another user's Recent). Built via `recentCacheKey($userId)` for a single source-of-truth.
- **Value shape:** `list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}>` — mirrors the registry row shape so the Recent rail can render labels without a second lookup.
- **Dedupe:** `pickEntry()` filters the existing list by `$r['id'] !== $id` before `array_unshift`-ing the new entry at the head. The dedupe is deterministic on the canonical `id` field — re-picking the same row does not duplicate it.
- **Cap:** `array_slice($filtered, 0, RECENT_LIMIT)` after the prepend; the oldest evicts when a 6th distinct pick arrives.
- **TTL:** `RECENT_TTL_SECONDS = 30 * 86400` (30 days). Cache repository's `put()` is the canonical entry — every pickEntry call refreshes the TTL.

Verified by test #5 (single pick → 1 entry; dedupe → still 1 entry; 6 distinct picks → cap at RECENT_LIMIT with oldest evicted) in `CommandPaletteRegistryTest`.

### 3. NativePHP app-menu Pitfall 9 caveat

**Native app-menu changes do NOT propagate until full bundle relaunch.** PHP autoreload (Vite + Livewire) does NOT trigger NativePHP to recompose the menu — the Electron main process composed the menu at boot via `Menu::create()` and the running process retains that composition.

For the operator:

1. After a code change that affects `AppMenuBuilder::build()`, the developer MUST fully quit the running Electron process (Cmd+Q on macOS) and re-launch (`composer dev` or the bundled app).
2. The PHP `php artisan` AppMenuBuilderTest assertions exercise `build()` directly, so test feedback is correct without relaunch — only the visible menu in the running app needs the relaunch.

This is documented as a class-level docblock note on `AppMenuBuilder` so future contributors do not chase the ghost.

### 4. Sidebar Dev block queue-count choice rationale

**Choice:** `jobs.count()` only (pending jobs). NOT a composite `jobs + failed_jobs`.

**Rationale:**

- Failed jobs already have a dedicated surface — the dashboard toast (D-37 → `route('dev.queue.tab', ['tab' => 'failed'])`) plus the `/dev/queue/failed` tab. Surfacing them again in the sidebar "Queue {N}" would mix two distinct operational signals (work-in-progress vs work-that-failed).
- The sidebar pulse row is the operator's at-a-glance signal — "how much work is the queue holding right now?" The pending count is the right answer to that question; a composite count would conflate two different remediation paths (run more workers vs investigate failures).
- The dot-live + pulse aesthetic is "everything is fine" green when the count is steady and low — that semantic break when a stable count drifts upward only works for pending; failed counts would create a permanent "elevated" reading that defeats the at-a-glance value.

Documented in `AppSidebar`'s class docblock.

### 5. Final DEVUI-01..09 satisfaction matrix

| Requirement | Landed in | Surface |
| --- | --- | --- |
| DEVUI-01 — `/dev` overview with live tiles + EnsureDeveloperMode gate | 16-03 (skeleton) + 16-07 (overview upgrade) | `/dev` route + DevOverviewPage Livewire |
| DEVUI-02 — Whitelisted artisan runner with SAFE/DESTRUCTIVE tiers + SSE-streamed stdout | 16-04 (SAFE pipeline) + 16-04b (DESTRUCTIVE + triple-gate + audit) | `/dev/artisan` + ArtisanRunnerPage + CommandSpawner + spawn-then-tail SSE |
| DEVUI-03 — `dev_mode_audit` table + audit log page + retention | 16-04b (SpatieAuditWriter + AuditLogPage) + 16-03 (table migration) | `/dev/audit` + spatie/laravel-activitylog ^5.0 |
| DEVUI-04 — Log tailer with redaction processor | 16-05 (LogStreamController + RedactSecretsProcessor + OAuth scrub-set) | `/dev/logs` + SSE stream + Monolog tap |
| DEVUI-05 — Queue inspector with per-row + bulk actions + triple-gated bulk delete | 16-06 (QueueInspectorPage + QueueActions + DevSidebarItems registry) | `/dev/queue/{tab}` |
| DEVUI-06 — Doctor panel | 16-07 (DoctorPanelPage + DoctorCommand driver) | `/dev/doctor` |
| DEVUI-07 — Env + system snapshot | 16-07 (SystemSnapshotPage + ConfigFlattener) | `/dev/system` |
| DEVUI-08 — Embedded Horizon iframe (two-signal gate) + Failed-job dashboard toast retarget | 16-06 (HorizonFramePage + Routes/web.php gate + Dashboard toast) | `/dev/horizon` (conditional) + Dashboard $isDeveloper gate |
| DEVUI-09 — ⌘K / Ctrl+K command palette | **16-08 — THIS plan** | Body keybind handler + CommandPaletteModal + Fuse.js + Recent cache + AppSidebar live data + AppMenu Developer submenu |

All 9 DEVUI requirements satisfied across plans 16-03..16-08. Phase 16 ready for `/gsd:verify-work`.

### 6. Full Phase 16 hand-off checklist for the verifier

**Contract tests** — must be green at PR / verify-work time:

- `tests/Contracts/BoundaryArchTest::it requires every /dev route to apply the ensureDeveloperMode middleware` — locks the route gate coverage (D-07).
- `tests/Contracts/BoundaryArchTest::impersonationSurfaceRemoved` — locks the D-11 cleanup.
- `tests/Contracts/BoundaryArchTest::noDiederikLiteralAfterRename` — locks the 16-02 rename.
- `Modules/DevMode/tests/Contracts/SelectOnlyValidatorContractTest::*` — locks the SQL panel parser-time guard.

**Manual cut-over steps** from 16-02 SUMMARY that the verifier should confirm on the local dev machine:

- `.env`: `DIEDERIK_DEV_MODE` → `BEATRAX_DEV_MODE` (only the env var name; `config('app.dev_mode')` key itself is unchanged).
- Herd hostname: `diederik.test` → `beatrax.test`.
- macOS bundle id flipped to `com.beatrax.*` (relevant only if the operator has built + installed the Electron bundle locally).

**Layout assertions** — automated:

- `Modules/DevMode/tests/Feature/PaletteLayoutMountTest::it declares the palette + ⌘. keybind handler on the body tag of resources/views/layouts/app.blade.php` — locks the main-app keybind.
- The same test class locks the dev-shell keybind + both palette mounts.

**Sidebar live data** — automated:

- `Modules/Core/tests/Feature/AppSidebarDevBlockLiveDataTest::*` — five behaviour tests cover queue count + worker delta + em-dash + wire:poll.5s + non-developer gate.

**App menu** — automated:

- `Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest::*` — three tests cover developer / non-developer / unauthenticated branches. The Pitfall 9 caveat (visible menu in a running bundle needs full quit + relaunch) is documented in `AppMenuBuilder`'s class docblock; no automated test can exercise the Electron menu without spinning up the actual bundle.

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 (TDD) | `862d016` | feat(16-08): NavigationRegistry + AppActionRegistry concretes + CommandPaletteModal + Fuse.js wiring + Recent cache |
| 2 (TDD) | `9940b5a` | feat(16-08): body keybind handler + palette mount + sidebar Dev-block live data + app-menu Developer submenu |

Each task is a single commit (RED + GREEN halves landed together because the plan's `<done>` criteria explicitly required the GREEN-phase tests to pass for the task to be considered complete; the RED phase was verified by running each new test class before implementation and confirming the expected failures).

## Decisions Made

See `key-decisions` in the frontmatter for the full list. Quick recap:

- **`jobs.count()` (pending only)** for the sidebar Dev-block queue count — failed jobs surface elsewhere.
- **Palette JSON-emit-time filter for is_developer** + **SAFE-only filter for dev commands** — server-side enforcement; defense-in-depth atop the EnsureDeveloperMode route gate.
- **Per-user Recent cache** at `dev_mode.palette_recent.{userId}` (5 entries, 30d TTL, deduped).
- **NavigationEntry + AppAction gain a stable `id` field** — Fuse.js key + Recent dedupe.
- **AppMenuBuilder constructor-DI on CurrentUser** — submenu is structurally absent for non-developers.
- **Body Alpine x-data + I-7 text-input carve-out** — global keybind handler that does not steal keystrokes from focused inputs.
- **Fuse.js bundled via Vite, NOT a window global** — `palette.js` imports `fuse.js` directly and the Alpine factory registers via the `alpine:init` event.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Bootstrap the test environment that the worktree lacked**
- **Found during:** Task 1 pre-RED-phase
- **Issue:** Same per-worktree environment hygiene every preceding wave surfaces — no `.env`, no `vendor/`, no `database/database.sqlite`, no built assets.
- **Fix:** `cp .env.example .env && composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate --force && npm install && npm run build`.
- **Verification:** Pre-Task-1 baseline reached Wave 7's 2377 passed sequentially before the new test cases landed.
- **Committed in:** N/A — environment-bootstrap actions, not tracked changes.

**2. [Rule 1 — Bug] UrlGenerator does not expose `getRoutes()` directly**
- **Found during:** Task 1 first test run
- **Issue:** Initial NavigationRegistry / AppActionRegistry factories called `$urls->getRoutes()` on the injected UrlGenerator. The method does not exist (`Method Illuminate\Routing\UrlGenerator::getRoutes does not exist`).
- **Fix:** Switched to injecting `Illuminate\Routing\Router` directly + reading `$router->getRoutes()->hasNamedRoute()` / `->getByName()->uri()`. Wrapped in a try/catch for defense in depth in case a route is registered without a URI.
- **Files modified:** `Modules/DevMode/Providers/DevModeServiceProvider.php`.
- **Verification:** All 5 CommandPaletteRegistry tests + 16 dependent EnsureDeveloperMode + DevOverview tests pass.
- **Committed in:** `862d016` (Task 1).

**3. [Rule 2 — Outdated assertion] EnsureDeveloperModeTest's contract-resolution case asserted empty registries**
- **Found during:** Task 1 broader regression sweep
- **Issue:** 16-03's test asserted `expect($nav->all())->toBe([])` because the NavigationRegistry / AppActionRegistry were bound to Null* concretes at that time. Task 1 of THIS plan replaces both bindings.
- **Fix:** Updated the assertions to `expect($nav)->toBeInstanceOf(NavigationRegistry::class)` + `expect($nav->all())->not->toBe([])` so the test now locks the new contract resolution shape.
- **Files modified:** `Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php`.
- **Verification:** All 4 EnsureDeveloperMode tests pass.
- **Committed in:** `862d016` (Task 1).

**4. [Rule 1 — Bug] Phpstan L10 strict — nullCoalesce.offset on `$r['id'] ?? null`**
- **Found during:** Task 1 phpstan run
- **Issue:** `loadRecent()` returns rows with `id` typed as `string` (never null). The `?? null` inside the dedupe closure was a redundant defensive null-check that phpstan flagged as `nullCoalesce.offset` ("Offset 'id' on array... always exists and is not nullable").
- **Fix:** Dropped the `?? null` from the dedupe closure — the loadRecent return shape guarantees `id` is set.
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php`.
- **Verification:** Larastan L10 strict clean.
- **Committed in:** `862d016` (Task 1).

**5. [Rule 2 — Outdated assertion] ArtisanRunnerSafeTierTest::filters audit picks up palette JSON**
- **Found during:** Task 2 regression sweep
- **Issue:** The pre-existing audit-page filter test (`$response->assertDontSee('cache:clear')` after filtering /dev/audit to destructive) now falsely fails because the palette modal's JSON registry contains `Run cache:clear` (the SAFE-tier dev row). The palette is mounted globally in the dev-shell layout so it appears on every /dev/* response body.
- **Fix:** Sliced the response body at the palette mount marker before assertion: `$auditRegion = explode('command-palette-modal', $html)[0]; expect($auditRegion)->not->toContain('cache:clear')`. The audit-table region (which precedes the palette mount in the rendered HTML) is now the only surface checked.
- **Files modified:** `Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php`.
- **Verification:** All 8 ArtisanRunnerSafeTier tests pass.
- **Committed in:** `9940b5a` (Task 2).

**6. [Rule 1 — Bug] Pint `fully_qualified_strict_types` + `ordered_imports` on the new test file**
- **Found during:** Task 2 Pint --test run
- **Issue:** `CommandPaletteRegistryTest.php` used inline FQNs (`\Modules\Core\Public\Contracts\CurrentUser`) which Pint's `fully_qualified_strict_types` fixer hoists into top-of-file `use` statements + `ordered_imports` then alphabetises them.
- **Fix:** Ran `vendor/bin/pint` to apply the fixes; verified the tests still pass.
- **Files modified:** `Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php`.
- **Verification:** Pint `--test` passes on all plan-modified files; tests still pass.
- **Committed in:** `9940b5a` (Task 2).

**7. [Rule 1 — Bug] Sidebar snapshot test stale after the live-data swap**
- **Found during:** Task 2 regression sweep
- **Issue:** `tests/Snapshot/SidebarTest::matches the rendered sidebar HTML for a developer (snapshot lock)` failed because the dev-pulse row literal flipped from `Queue 0 · Worker —` to the live-data shape (`Queue 0 · Worker —` PLUS the new `wire:poll.5s` attribute). The snapshot is intentionally a structural lock that downstream plans update as they evolve the sidebar.
- **Fix:** Re-ran `vendor/bin/pest tests/Snapshot/SidebarTest.php -d --update-snapshots` to regenerate the baseline; verified subsequent runs are green.
- **Files modified:** `tests/.pest/snapshots/Snapshot/SidebarTest/it_matches_the_rendered_sidebar_HTML_for_a_developer__snapshot_lock_.snap`.
- **Verification:** Sidebar snapshot test passes.
- **Committed in:** `9940b5a` (Task 2).

---

**Total deviations:** 7 auto-fixed (3 Rule 1 — bugs; 2 Rule 2 — outdated assertions; 1 Rule 3 — blocking; 1 Pint cosmetic). All seven are necessary follow-throughs of the plan's intent. None changed scope.

## Issues Encountered

- **Pre-existing Pint flag on `Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php`.** Landed in 16-07's commit `2034b91`; Pint flags `class_definition`, `fully_qualified_strict_types`, `braces_position` fixers. This file is unrelated to 16-08's scope and Pint is clean on every file 16-08 touches. Documented as a deferred item.

## Deferred Items

- **Pint cosmetic flag on Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php** — pre-existing from 16-07 (commit `2034b91`). The file uses inline FQNs + anonymous-class brace style that Pint's preset hoists. Out of 16-08's scope per the SCOPE BOUNDARY rule. A follow-up plan can sweep `vendor/bin/pint` to apply the fix.
- **spawn-command Livewire listener on ArtisanRunnerPage** — the palette dispatches `spawn-command` for dev rows, but `ArtisanRunnerPage` does not yet register an `#[On('spawn-command')]` listener. The palette JS factory navigates to /dev/artisan as a fallback so the spawn intent is never silently dropped, but a future plan should wire the listener so the spawn can fire from inside /dev/artisan without a navigation roundtrip.
- **email-scan.run + theme.cycle handler listeners** — the AppActionRegistry exposes two `handlerEvent`-shaped actions ("Scan email now", "Toggle theme") whose Livewire event listeners do not yet exist anywhere in the app. The palette dispatch is a no-op for now; a future plan should land the listeners (Modules/EmailScan + Modules/Core respectively).

## User Setup Required

None — no external service configuration. The two new files in `resources/js/` are bundled by the existing Vite pipeline (`npm run build`) and the palette is wired through the existing Livewire / Alpine stack.

## Known Stubs

- **Two AppAction handlers without listeners** — `email-scan.run` and `theme.cycle` are dispatched by the palette but no Livewire component listens for either. The palette behaviour is graceful (the dispatch is a no-op and the modal closes), but selecting either row produces no observable effect. Documented in Deferred Items above; the listener wiring is a follow-up.

The two stubs do NOT prevent this plan's goal (DEVUI-09 — ⌘K palette covering every authenticated view + every SAFE-tier dev command + every named app action) from being achieved. The view + dev rows ARE wired end-to-end (navigation + spawn-command); the two action rows are listed in the palette JSON for discoverability but their target listeners land later.

## Self-Check: PASSED

Files asserted present:

- `Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php` — FOUND
- `Modules/DevMode/Internal/Navigation/NavigationRegistryImpl.php` — FOUND
- `Modules/DevMode/Internal/Navigation/AppActionRegistryImpl.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php` — FOUND
- `resources/js/palette.js` — FOUND
- `Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` — FOUND
- `Modules/Core/tests/Feature/AppSidebarDevBlockLiveDataTest.php` — FOUND
- `Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest.php` — FOUND
- `Modules/DevMode/tests/Feature/PaletteLayoutMountTest.php` — FOUND
- `Modules/DevMode/Public/Dto/NavigationEntry.php` (modified — `id` field) — FOUND
- `Modules/DevMode/Public/Dto/AppAction.php` (modified — `id` field) — FOUND
- `Modules/DevMode/Providers/DevModeServiceProvider.php` (modified — Null* bindings replaced + CommandPaletteModal registered) — FOUND
- `Modules/Core/Internal/Http/Livewire/AppSidebar.php` (modified — live-data DI) — FOUND
- `Modules/Core/Resources/views/livewire/app-sidebar.blade.php` (modified — wire:poll.5s + live row) — FOUND
- `resources/views/layouts/app.blade.php` (modified — body x-data + palette mount) — FOUND
- `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php` (modified — body x-data + palette mount) — FOUND
- `Modules/Desktop/Internal/Native/AppMenuBuilder.php` (modified — CurrentUser DI + Developer submenu) — FOUND
- `resources/js/app.js` (modified — palette factory registration) — FOUND
- `Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` (modified — non-empty registry assertions) — FOUND
- `Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php` (modified — slice off palette JSON before assertion) — FOUND
- `tests/.pest/snapshots/Snapshot/SidebarTest/it_matches_the_rendered_sidebar_HTML_for_a_developer__snapshot_lock_.snap` (regenerated) — FOUND

Commits asserted present:

- `862d016` (Task 1 — NavigationRegistry + AppActionRegistry concretes + CommandPaletteModal + Fuse.js + Recent cache) — FOUND
- `9940b5a` (Task 2 — body keybind handler + palette mount + sidebar Dev-block live data + app-menu Developer submenu) — FOUND

## Next Phase Readiness

- **Phase 16 verification (`/gsd:verify-work`):** every DEVUI-01..09 requirement is satisfied across plans 16-03..16-08. The verifier should run the full Pest suite + Larastan + Pint and confirm 2394 passed, 0 failed (the 19 todos + 6 skipped are pre-existing baseline). The four contract tests listed under Hand-off Checklist § 6 must be green. The manual cut-over steps from 16-02 SUMMARY are operator-local and need confirmation on the developer's machine.
- **Future plan — `spawn-command` listener on ArtisanRunnerPage** — the palette dispatches the intent; the runner page should register `#[On('spawn-command')]` so the dispatch fires without a /dev/artisan navigation roundtrip. Out of scope here.
- **Future plan — `email-scan.run` + `theme.cycle` handler listeners** — wire the AppAction-shaped palette rows to the right Livewire components (Modules/EmailScan + Modules/Core respectively). Out of scope here.

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
