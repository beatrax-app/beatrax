# Phase 16: Developer Mode UI - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-24
**Phase:** 16-developer-mode-ui
**Areas discussed:** Module/IA + Act-as-partner wiring, Artisan runner — whitelist + form UX + triple-gate, Log tailer + redaction + queue inspector + dashboard toast, Command palette + Doctor / env / SELECT-only SQL panels, Rename diederik → beatrax, Zero-config principle

---

## Module/IA + Act-as-partner wiring

### Where should the Developer Console live, structurally?

| Option | Description | Selected |
|--------|-------------|----------|
| New Modules/DevMode/, /dev/* (Recommended) | Dedicated module mirroring Modules/Desktop. Routes apply 'developer' middleware globally; arch-test forbids cross-module reach-in. Clean home for DevUI Livewire + a Public surface. | ✓ |
| Inside Modules/Auth/, /dev/* | Reuse the module that owns is_developer + middleware. Cheaper to set up, but bloats Auth with artisan-runner / log-tailer code. | |
| Inside Modules/Core/, /dev/* | Treat dev tooling as core infra. Risk: Core already hosts system_alerts / DoctorCommand / FailedJobsCommand; thickens the catch-all module. | |

### How should the Dev Console's pages be laid out?

| Option | Description | Selected |
|--------|-------------|----------|
| Sidebar with sub-pages (Recommended) | /dev/artisan, /dev/logs, /dev/queue, etc. Each its own Livewire page, wire:navigate between them. | |
| Single /dev page with tabs | All panels as tabs inside one component; simpler routing, balloons the component. | |
| Hybrid: /dev dashboard + sub-pages | Overview tile-grid at /dev (recent alerts, queue depth, log tail preview) plus sub-pages. Adds a 'where am I' surface. | ✓ |

### How should the existing RequireDeveloperMiddleware be exposed for /dev/* routes?

| Option | Description | Selected |
|--------|-------------|----------|
| Reuse 'developer' alias as-is (Recommended) | Add arch-test invariant only. No new class. | |
| Add an EnsureDeveloperMode alias pointing to same class | Cosmetic; helps the test name match the alias. | |
| Create new EnsureDeveloperMode middleware in Modules/DevMode/ | Brand-new class living inside Modules/DevMode/. Duplicates RequireDeveloperMiddleware behavior. Cleaner if DevMode wants self-contained ownership. | ✓ |

### Where do the 'Act as partner' button + re-auth modal live?

| Option | Description | Selected |
|--------|-------------|----------|
| Buttons on existing ManageUserPage + modal inline (Recommended) | Add button there, modal collects developer's password, calls ImpersonateUserAction. | |
| Dedicated page at /dev/impersonate | Standalone listing of non-dev users with an 'Act as' action per row. | |
| Quick-action in the ⌘K command palette | Type 'act as <username>' → password prompt → impersonate. | |
| **Other: "Does not exist"** | User dropped the feature entirely. | ✓ |

**User's choice:** Drop the feature entirely.
**Notes:** User followed up: "Yes, drop it and also remove the orphaned action." Phase 16 inventories + removes ImpersonateUserAction, EndImpersonationAction, ImpersonationBannerMiddleware, related Pest tests, the Blade partial, session keys, and the BoundaryArchTest allow-list entry.

### How should the Dev Console sidebar relate to the main app's chrome?

| Option | Description | Selected |
|--------|-------------|----------|
| Replace main nav while inside /dev/* (Recommended) | Main top-nav swaps for a Dev-Console sidebar inside /dev/*. Linear-style. Tray + system_alerts banner render globally. | ✓ |
| Sidebar in addition to main nav | Main top-nav stays; Dev Console renders a sidebar under it. | |
| Dev Console opens as a separate window via NativePHP | New native window for /dev/*. Adds Modules/Desktop coupling. | |

**Notes:** User added: "give both Nav UI's a bit of a visual upgrade. For the non-dev mode the nav is getting all propped up" — triggered the follow-up sidebar-restructure question.

### What lives on the /dev dashboard overview?

| Option | Description | Selected |
|--------|-------------|----------|
| Live tiles (Recommended) | Calm grid: queue depth, recent system_alerts, log tail preview, worker heartbeat, last backup. wire:poll. | ✓ |
| Static index card list | Minimal — card per panel with description. | |
| Hybrid: static cards + one 'Recent activity' feed | Cards plus a chronological feed. | |

### How should DevMode expose its command registry to other modules?

| Option | Description | Selected |
|--------|-------------|----------|
| Modules/DevMode/Public/Contracts/DevCommandRegistry (Recommended) | Public contract listing registered commands + metadata. Palette + artisan-runner consume it. Other modules register via the contract. | ✓ |
| Static config array in config/dev-mode.php | Commands in a config file. Simpler but no DI seam. | |
| Auto-discover from Artisan command registry, opt-in attribute | Walk Artisan::all(), pick those with a #[DevModeRunnable] attribute. | |

### Should the dashboard's hardcoded /horizon/failed deep link be replaced now?

| Option | Description | Selected |
|--------|-------------|----------|
| Replace in queue-inspector area (Recommended) | Decide later in the queue-inspector area where it belongs. | ✓ |
| Decide now: link to /dev/queue/failed always | Non-developers click → 404. Confusing. | |
| Decide now: hide the toast for non-developers | Toast gains an && $isDeveloper guard. | |

### How big a refresh do you want for the main (non-dev) top-nav?

| Option | Description | Selected |
|--------|-------------|----------|
| Light touch-up (Recommended) | Tighten spacing, group cluttered links, polish typography. | |
| Restructure: sidebar for the main app too | Replace top-nav with a left sidebar across the whole app, matching the Dev Console sidebar. Visually cohesive but every existing Blade view's layout assumptions change. | ✓ |
| Re-do top-nav with grouped sections + secondary row | Keep top-nav, split into primary + secondary actions. | |

### Should the main-nav refresh ship in Phase 16 or as a separate sliver?

| Option | Description | Selected |
|--------|-------------|----------|
| Fold into Phase 16 as its own plan (Recommended) | 16-XX-PLAN.md — nav refresh + base Blade layout polish + sidebar primitives reused by Dev Console. | ✓ |
| Defer to a v2.1 / polish phase | Phase 16 ships only the Dev Console sidebar; main top-nav stays as-is. | |
| Separate phase before public release | Add a new phase between 16 and 17. | |

---

## Artisan runner — whitelist + form UX + triple-gate

### Which artisan commands should be in the SAFE tier?

| Option | Description | Selected |
|--------|-------------|----------|
| db:backup | Roadmap explicitly lists this. | ✓ |
| diederik:doctor | Roadmap explicitly lists this. | ✓ |
| diederik:failed-jobs prune | Roadmap explicitly lists this. | ✓ |
| cache:clear, route:list, config:show, view:clear, queue:retry, diederik:rederive-fingerprints | The 'etc.' Roadmap left open. | ✓ |

**Notes:** All four. Names flip to beatrax:* after the rename plan lands.

### Which commands should be in the DESTRUCTIVE tier?

| Option | Description | Selected |
|--------|-------------|----------|
| db:restore, migrate:fresh | Roadmap explicitly lists these. | ✓ |
| diederik:reset-password | Roadmap explicitly lists this. | ✓ |
| diederik:regenerate-recovery-codes, diederik:grant-dev, diederik:install | Auth back-door commands + installer. | ✓ |
| migrate, migrate:rollback, db:seed | DDL effects on user data; conservative bucket. | |

**Notes:** First three options selected — `migrate`, `migrate:rollback`, `db:seed` explicitly NOT exposed in the UI (CLI only).

### How should command arg forms be built?

| Option | Description | Selected |
|--------|-------------|----------|
| Declarative schema per command in the DevCommandRegistry (Recommended) | Each command registers an array describing args + UI hints. Explicit, type-safe. | ✓ |
| Reflect Symfony InputDefinition from each Command | Walk getDefinition()->getArguments() / getOptions() and auto-render. Less hand-rolling; sparse descriptions. | |
| Hybrid: reflect + per-command overrides | Reflection by default; commands can opt into a 'devModeForm()' static method. | |

### How should command execution stream stdout/stderr + handle cancellation?

| Option | Description | Selected |
|--------|-------------|----------|
| Symfony Process::start() + SSE endpoint, wire:stream consumer (Recommended) | Process->getIncrementalOutput() over SSE; cancel → Process->stop(SIGTERM); pid+run_id in cache for refresh-reconnect. | ✓ |
| Artisan::call() with OutputStyle capture + Livewire wire:poll | Synchronous run; no live streaming. Doesn't meet success criterion. | |
| Dispatch as queued job + poll a streamed_runs table | Needs queue:work alive; latency; cancel needs polled kill flag. | |

### How should the 'Advanced toggle' behave?

| Option | Description | Selected |
|--------|-------------|----------|
| Session-scoped, default OFF (Recommended) | Resets to OFF on every login + on Dev Console first-load per session. | ✓ |
| Persistent per user (off by default) | Stored on user row; stays on across sessions. | |
| Time-windowed (auto-off after N minutes idle) | Session-scoped + auto-resets after idle. | |

### What is the typed-app-name string?

| Option | Description | Selected |
|--------|-------------|----------|
| Exactly 'diederik' (lowercase) (Recommended) | Case-sensitive match. | ✓ |
| Command-specific token: e.g., 'restore diederik db' | Per-command sentence. | |
| Username of the current developer | Forces typing your own username. | |

**Notes:** Selection was 'diederik' (the recommended option as worded). After the rename plan lands (16-02), this string becomes 'beatrax' — D-21 in CONTEXT.md reflects the post-rename string.

### What does the spatie/laravel-activitylog 'dev_mode_audit' row contain?

| Option | Description | Selected |
|--------|-------------|----------|
| command, args, tier, caller_user_id, started_at, finished_at, exit_code, stdout_excerpt(8KB), error_excerpt(8KB) (Recommended) | 8KB excerpts cover typical commands without bloating SQLite. Pass through Monolog redaction processor. | ✓ |
| Same fields + FULL stdout/stderr (no truncation) | Complete; risk: MB per run, SQLite grows. | |
| Metadata only — command + args + caller + exit_code | No output excerpt. | |

### Where does the dev_mode_audit log render in the UI?

| Option | Description | Selected |
|--------|-------------|----------|
| /dev/audit page + recent rows on /dev/artisan (Recommended) | Dedicated page + Recent runs panel on runner. | ✓ |
| Inline only on /dev/artisan (no dedicated page) | Audit lives as a panel; no standalone view. | |
| Only via spatie's default activity_log view + a /dev/audit redirect | Aesthetic mismatch with rest of app. | |

### Concurrent-run policy?

| Option | Description | Selected |
|--------|-------------|----------|
| One command at a time per user (Recommended) | Prevents accidental double-firing. | |
| Multiple concurrent runs, separate run cards | Each run renders as own SSE-streamed card. | ✓ |
| Queue runs serially (FIFO) | New click queues behind active run. | |

### Where + how should command history be persisted?

| Option | Description | Selected |
|--------|-------------|----------|
| Reuse dev_mode_audit — history IS the audit log (Recommended) | Single source of truth. | ✓ |
| Separate session-only history (last 10 in localStorage) | Quick-access ring buffer. | |
| Separate persistent dev_mode_command_history table | Decouples convenience from forensics. | |

### Worker check behavior?

| Option | Description | Selected |
|--------|-------------|----------|
| Pre-flight check + soft warning (Recommended) | Status pill + inline warning beneath Run when worker is dead — doesn't block. | ✓ |
| Block the command from running if no worker | Strictest UX. | |
| No worker awareness | Developer's responsibility. | |

---

## Log tailer + redaction + queue inspector + dashboard toast

### How should the log tailer stream storage/logs/laravel.log?

| Option | Description | Selected |
|--------|-------------|----------|
| SSE endpoint reading the file with inotify-style polling (Recommended) | clearstatcache + fseek loop, 250ms. Cross-platform. | ✓ |
| wire:poll on a server-side ring buffer fed by Monolog handler | Custom Monolog handler pushes into in-memory ring buffer; Livewire polls. | |
| Shell out to tail -f via Process::start() + SSE | Simplest streaming; Windows lacks `tail -F`. | |

### Where should the Monolog redaction processor be wired?

| Option | Description | Selected |
|--------|-------------|----------|
| Globally on every Monolog channel, always-on (Recommended) | tap on every channel; secrets never hit disk. | |
| Only when the dev tailer is active | Disk file keeps raw secrets. | |
| Both: redact on write AND re-redact on stream (belt + braces) | Belt-and-braces. | ✓ |

### How should the redactor handle 'values reachable from the oauth_secrets table'?

| Option | Description | Selected |
|--------|-------------|----------|
| Cached scrub-set, invalidated on oauth_secrets writes (Recommended) | Singleton + observer; bounded cost. | ✓ |
| Regex-only (Bearer, JWT, common token shapes) | Misses unique token formats. | |
| Read oauth_secrets fresh on every log line | Catastrophic perf cost. | |

### What filtering / scrollback does the log tailer UI offer?

| Option | Description | Selected |
|--------|-------------|----------|
| Severity dropdown + free-text + 10k-line scrollback buffer (Recommended) | Severity multi-select + free-text + rolling 10k buffer + Pause/Resume. | |
| Severity + free-text only, no scrollback | Live tail only. | |
| Plus channel/module filter + click-to-expand context line | Adds channel filter + ±10-line context. Closer to a log-explorer. | ✓ |

### How should the queue inspector be laid out?

| Option | Description | Selected |
|--------|-------------|----------|
| Single /dev/queue page with three tabs (Recommended) | Tabs: Pending / Failed / Batches. ~200-line Livewire component. | ✓ (combined with 2) |
| Three separate pages: /dev/queue/jobs, /dev/queue/failed, /dev/queue/batches | Each on its own URL. | ✓ (combined with 1) |
| Unified table with a 'state' column | Mixes pending / failed / batched. | |

**Notes:** User picked '1 and 2' — interpreted as single Livewire component with per-tab routes (/dev/queue/pending, /failed, /batches) backed by the same component. Best of both: one component file + deep-linkable URLs.

### Per-row actions in the queue inspector?

| Option | Description | Selected |
|--------|-------------|----------|
| Pending: delete | Failed: retry, delete | Batches: retry-failures, cancel, delete (Recommended) | Matches Laravel's first-class verbs. | |
| Same set + bulk select | Multi-row checkbox + bulk Retry / Delete. | ✓ |
| Read-only inspector — no actions, CLI only | Safest but defeats the purpose. | |

### Replace the dashboard's hardcoded /horizon/failed deep link how?

| Option | Description | Selected |
|--------|-------------|----------|
| Developers → /dev/queue/failed; non-developers → hide toast entirely (Recommended) | && $isDeveloper guard. Non-devs get the SystemAlertsBanner. | ✓ |
| Always show toast, link to /dev/queue/failed for everyone | Non-devs hit 404. | |
| Always show toast, link to a non-dev fallback page that explains the situation | Build a /chains/failures-help non-dev page. | |

### How is the embedded Horizon iframe gated?

| Option | Description | Selected |
|--------|-------------|----------|
| Show panel only when config('app.dev_mode') === true AND Horizon class is loaded (Recommended) | Belt + braces. | ✓ |
| Gate only on class_exists Horizon | Drops the app.dev_mode check. | |
| Gate only on app.dev_mode | Less safe. | |

### Today's logging uses 'single'. What should the tailer do when the file rolls?

| Option | Description | Selected |
|--------|-------------|----------|
| Stay single-file; tailer handles truncation via inotify-style file-id check (Recommended) | Keep 'single' channel. | |
| Switch to 'daily' channel + show today + yesterday in tailer | Per-day rotation. | ✓ |
| Keep single, add 'Jump to N minutes ago' button | Quick-rewind affordance. | |

### Should the queue inspector show full job payloads?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — expandable inline JSON viewer per row (Recommended) | Inline expand → JSON, exception, attempts. Passes through redactor. | ✓ |
| Yes, but on a separate /dev/queue/jobs/{id} detail page | More clicks. | |
| No — only metadata rows, no payload viewer | Smallest scope. | |

### How long is dev_mode_audit retained?

| Option | Description | Selected |
|--------|-------------|----------|
| Forever (Recommended) | Matches 'full history retained forever'. | |
| 90 days + prune via scheduled job | Lower disk footprint; loses long-tail forensic ability. | |
| Forever + explicit prune command for the dev to call when needed | Default forever plus a registered SAFE-tier prune command. | ✓ |

### Should the queue worker heartbeat be added in Phase 16?

| Option | Description | Selected |
|--------|-------------|----------|
| Add in Phase 16: tiny Queue::looping listener writes cache key (Recommended) | Zero cost when no worker. | ✓ |
| Punt to Phase 15 retro | Phase 15 owns the daemon. | |
| Don't add heartbeat — use 'is the daemon process alive' OS check | Cross-platform mess. | |

### Bulk-select UI in the queue inspector — should DESTRUCTIVE bulk actions follow the same triple-gate?

| Option | Description | Selected |
|--------|-------------|----------|
| Bulk delete requires triple-gate; bulk retry does not (Recommended) | Matches SAFE/DESTRUCTIVE split. | ✓ |
| All bulk actions require triple-gate | Maximum friction. | |
| Single-confirm modal for all bulk actions | Less friction; bulk-delete footgun. | |

### Should the queue inspector show counts even when no rows match the filter?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — small header tiles: Pending(N) / Failed(N) / Batches(N) (Recommended) | wire:poll(5s); feeds /dev dashboard tile. | ✓ |
| No — counts only via wire:poll on the active tab | Simpler; loses 'something failed' at-a-glance signal. | |

---

## Rename diederik → beatrax (mid-discussion add-in)

### How much should the rename cover?

| Option | Description | Selected |
|--------|-------------|----------|
| Full rename: code + artisan + env + bundle id + Herd host + planning docs + tests (Recommended) | Everything. | ✓ |
| Code + artisan + env only (defer bundle id + Herd hostname to Phase 17) | Smaller blast radius now. | |
| Code + artisan + env + bundle id; leave planning docs (.planning/) as-is | Future readers see ROADMAP referring to old name. | |

### When in Phase 16 does the rename land?

| Option | Description | Selected |
|--------|-------------|----------|
| First plan after the base-layout sidebar (Recommended) | 16-01 base layout → 16-02 rename → 16-03+ DevMode panels. | ✓ |
| Last plan, after every DevMode panel is built | DevMode lands using old names; rename at end. | |
| Its own plan but order chosen by planner | Let the planner decide. | |

### How should the rename be 'zero-config' for the upgrading operator?

| Option | Description | Selected |
|--------|-------------|----------|
| First-launch migration auto-detects old paths/env (Recommended) | Silent detection + system_alerts row. | |
| Document in CHANGELOG; operator does .env edit + folder rename | One-page migration note. | |
| Both: runtime detection AND a migration note for edge cases | Belt-and-braces. | |
| **Other: "not used by anyone yet, dw about that, just fix it on my machine"** | No migration code; clean cut. | ✓ |

**User's choice:** No migration code. App isn't used by anyone yet. Manual update on the dev's machine.

### Where do the opening-balance pre-fill + the broader zero-config polish live?

| Option | Description | Selected |
|--------|-------------|----------|
| Its own plan inside Phase 16: 'Zero-config defaults' (Recommended) | Settings UI pre-fill from MIN(opening_balance_minor). | |
| Defer the opening-balance feature to its own phase (e.g., 16a 'Zero-config & onboarding') between 16 and 17 | Genuinely Ledger/Accounts UX, not DevMode. | ✓ |
| Just the opening-balance pre-fill in Phase 16; broader zero-config is v2.1 | Ship one deliverable now. | |

**Notes:** Captured as deferred — user to run /gsd-phase to add the new phase to ROADMAP.md before planning it.

---

## Command palette + Doctor / env / SELECT-only SQL panels

### How should the ⌘K / Ctrl+K command palette be built?

| Option | Description | Selected |
|--------|-------------|----------|
| Livewire 4 + Alpine + Fuse.js for fuzzy search (Recommended) | Modal Livewire + Alpine handler + client-side Fuse.js ranking. | ✓ |
| Pure server-side fuzzy via Livewire wire:model.debounce + symfony/string | 150ms input lag on every keystroke. | |
| cmdk (React component) wrapped in a Livewire mount point | Pulls React into a Livewire-only stack. | |

### What sources feed the palette?

| Option | Description | Selected |
|--------|-------------|----------|
| Registered views + Dev commands + named app actions (Recommended) | NavigationRegistry + DevCommandRegistry + AppActionRegistry. | ✓ |
| Views + Dev commands only | No 'named app actions' source. | |
| Views only (non-dev) / + Dev commands (dev) | Minimal: navigation-only. | |

### How does the /dev/doctor panel run the doctor command?

| Option | Description | Selected |
|--------|-------------|----------|
| Re-use the artisan runner pipeline + render result inline (Recommended) | Same Process + SSE machinery; parses structured probe output. | ✓ |
| Call doctor probes directly (skip the artisan layer) | Bypasses streamed/audit pipeline. | |
| Hybrid: Process-spawn for run, probes for tile preview | Two code paths. | |

### What fields should the env snapshot show on /dev/system?

| Option | Description | Selected |
|--------|-------------|----------|
| PHP version + SQLite PRAGMAs + extension list + Laravel/PHP paths + Beatrax env vars (redacted) + NativePHP runtime info (Recommended) | Comprehensive. | ✓ |
| Same fields without the env vars section | Drops .env dump. | |
| Minimal: PHP version + SQLite PRAGMAs + extensions + Laravel version | Smallest scope. | |

### How does the SELECT-only SQL panel enforce 'SELECT only at parse time'?

| Option | Description | Selected |
|--------|-------------|----------|
| doctrine/sql-formatter + a hand-rolled token-prefix check, reject non-SELECT (Recommended) | Tokenize, assert first token = SELECT, reject semicolon-chained statements, 5s timeout. | ✓ |
| Regex /^\s*SELECT/i + execute via DB::select() | Simplest; less defensible. | |
| Open a read-only SQLite connection (PRAGMA query_only=1) and let the engine reject writes | Belt-and-braces. | |

**Notes:** D-45 in CONTEXT pairs the doctrine tokenizer WITH PRAGMA query_only=1 for double protection.

### Should the SQL panel be triple-gated?

| Option | Description | Selected |
|--------|-------------|----------|
| Dev Mode + Advanced toggle only (no typed-name modal) (Recommended) | Parser + read-only PRAGMA are the actual guard. | ✓ |
| Triple-gated each query | Maximum friction; gates add no real protection. | |
| Dev Mode only (no Advanced toggle) | Lowest friction; habitual queries leak PII. | |

### What does the schema viewer expose?

| Option | Description | Selected |
|--------|-------------|----------|
| Tables + columns + indexes + row count + foreign keys, click-to-browse first 100 rows (Recommended) | Full schema view + sample data. | ✓ |
| Tables + columns + indexes only — no browse | Read-only metadata. | |
| Tables + columns only | Smallest. | |

### Where does the keybind handler for ⌘K / Ctrl+K live?

| Option | Description | Selected |
|--------|-------------|----------|
| Global Alpine x-data on the base layout body (Recommended) | Single handler in app.blade.php. | ✓ |
| Livewire dispatch event from a hidden component | More PHP-side; less crisp. | |
| Native menu bar entry (macOS-only) via NativePHP | Doesn't work in dev-on-Herd-browser. | |

---

## Claude's Discretion

- Internal structure of `Modules/DevMode/` beyond locked Public surfaces.
- Exact Flux component choices for runner form, audit table, queue table, tailer scrollback, schema viewer, palette modal.
- Monolog `tap` class name and impl detail.
- Per-command args-schema shape beyond `[name, label, type, validation]`.
- JSON shape the palette server emits to Fuse.js.
- `dev_mode_audit` action-type taxonomy for non-command rows.
- Doctor probe output parser turning streamed lines into pass/warn/fail rows.
- Whether the rename plan (16-02) uses a single mass commit or per-area commits.
- Exact set of Phase 12 impersonation artifacts to remove beyond D-11's list — planner does a grep sweep.

## Deferred Ideas

- **Opening-balance pre-fill in Settings + broader zero-config polish pass** — NEW phase between 16 and 17. User to run `/gsd-phase add` before planning. Belongs to Ledger/Accounts domain.
- **Apple notarization / code signing under `com.beatrax.*` bundle id** — Phase 17.
- **CI matrix updates for renamed `beatrax:*` commands** — Phase 17.
- **Public README + onboarding docs reflecting rename** — Phase 19.
- **`laravel/pulse` (TELE-03)** — already deferred (Phase 14); Phase 16's bespoke queue inspector makes it redundant.
- **Sentry / crash reporting** — Phase 21 (beta cohort).
- **WebSockets / `laravel/reverb` for the SSE pipeline** — not needed; SSE works fine.
