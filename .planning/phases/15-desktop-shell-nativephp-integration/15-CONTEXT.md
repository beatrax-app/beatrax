# Phase 15: Desktop Shell (NativePHP Integration) - Context

**Gathered:** 2026-05-22
**Status:** Ready for planning

<domain>
## Phase Boundary

Wrap diederik in a NativePHP/Electron desktop shell. `php artisan native:build`
produces signed-ready `.dmg` (macOS), `.msi/.exe` (Windows), and
`.AppImage/.deb` (Linux) installers; double-clicking an installer launches
diederik as a native window with complete native chrome (window, dock/taskbar
icon, app menu, system tray, OS notifications, dark-mode-follows-OS).
Double-clicking a `.eml` or `.csv` file in the OS opens diederik with an
ingestion intent, routed through a new `FileOpenedFromOs` event. A new
`Modules/Desktop/` module quarantines every `Native\Laravel\*` import, enforced
by `BoundaryArchTest::noNativePhpImportsOutsideDesktopModule`.

Phase 15 ships:

- `nativephp/desktop ^2.2` integration + the `php artisan native:build`
  pipeline producing all three platform installers (unsigned/signed-ready).
- A new `Modules/Desktop/` module — the only place `Native\Laravel\*` imports
  are permitted; arch-test enforced.
- Native chrome: window, dock/taskbar icon, app menu (standard
  File/Edit/View/Window/Help + diederik-specific entries), system tray, OS
  notifications, dark-mode follows OS.
- A new `FileOpenedFromOs` event + `.eml`/`.csv` file-association handlers.
- The shipped-build worker daemon + scheduler (deferred to here from Phase 14).
- A full dark theme across every module's Blade views.
- Brand-icon assets for all platforms generated from the supplied logo SVG.
- First-launch DB bootstrap + the macOS Hardened Runtime entitlements file
  (`com.apple.security.cs.allow-unsigned-executable-memory`,
  `com.apple.security.cs.disable-library-validation`).
- A CI PHP 8.4 axis skeleton (the matrix lands fully in Phase 17); Larastan
  L10 strict + Pint + Pest must all pass on PHP 8.4.

Phase 15 does NOT ship:

- **Code signing + notarization execution** — Phase 17. Phase 15 produces
  signed-READY installers and configures the entitlements file (PKG-08); it
  does not run the Apple Developer ID / Windows EV signing pipeline.
- **Auto-update plumbing** — Phase 18.
- **The Developer Mode UI / queue inspector / ⌘K palette** — Phase 16.

</domain>

<decisions>
## Implementation Decisions

### File-Open Intent (`FileOpenedFromOs`)

- **D-01: `.csv` double-click lands on a staging page first.** A neutral
  "File received: `<name>`" page with an explicit "Start import" button —
  NOT straight into the parsed import-preview UI. The preview/confirm flow
  runs only after the user clicks through.
- **D-02: `.eml` double-click UX is Claude's discretion** — pick the cleanest
  fit with the existing `FileDropEmlBlobStore` receipts pipeline. For
  consistency, a staging page mirroring the `.csv` pattern (D-01) is the
  expected default unless research shows the pipeline argues otherwise.
- **D-03: Single-instance enforcement.** When diederik is already running and
  a file is double-clicked, focus the existing window and navigate it
  straight into the import flow (replacing the current screen). No second
  window, no second app process.
- **D-04: A file double-clicked while no user is logged in holds its
  intent.** Show the login screen, remember the pending file, and continue
  to the import flow once the user authenticates. The file intent must
  survive the login round-trip.

### Background Processing in the Bundle

- **D-05: The packaged bundle runs BOTH a queue worker and the Laravel
  scheduler.** Queued jobs drain and scheduled work fires automatically while
  the app is alive — "it just works" for the non-technical partner. This is
  the shipped-build worker daemon Phase 14 deferred here (Phase 14 D-07).
- **D-06: Email scanning in the bundle is a scheduled, timer-based
  auto-scan** (the ~15-min fallback cadence) — NOT the v1.0 always-on
  IMAP-idle daemon. Timer-based scheduling is simpler to supervise inside an
  Electron child process. The IMAP-idle daemon stays a dev-box concern.
- **D-07: A bundled-worker crash or repeated job failure surfaces via both
  the existing in-app `SystemAlertsBanner` AND a native OS notification.**
  The partner has no Horizon and no dev console, so worker health must be
  visible without one.

### Native Chrome — Tray, Window, Menu

- **D-08: The window close (X) button asks on first close** — "Quit" vs
  "Keep running in the tray" — and persists the choice for subsequent
  closes. Keeping the app in the tray keeps the D-05 worker + scheduler
  alive so scheduled scans continue.
- **D-09: System-tray icon — left-click toggles the main window
  (show/hide); right-click opens a menu** with: Open diederik / Scan email
  now / Quit.
- **D-10: Window size + position persist across launches** (standard native
  behavior).
- **D-11: The app menu carries diederik-specific entries beyond the standard
  File/Edit/View/Window/Help set:** File → "Import file…" and "Scan email
  now"; Help → "GitHub repo", "Report an issue", "About diederik".

### OS Notifications

- **D-12: Native OS notifications fire for these event categories:** drift
  alerts, import finished, new receipts found, forecast shortfall, and
  worker/system errors (the D-07 category).
- **D-13: Context-aware notification model.** An OS notification fires ONLY
  when the app is backgrounded / in the tray. When the window is focused,
  the in-app `SystemAlertsBanner` handles the event instead — no
  double-notifying. The banner remains the complete record of all alerts.
- **D-14: Clicking an OS notification deep-links to the relevant screen**
  (drift notification → drift alerts page; import notification → that
  import; etc.), focusing the app on the way.

### Dark Mode

- **D-15: A full dark theme ships this phase** — every module's Blade views
  get polished `dark:` variants. ⚠ This is a large effort: diederik has zero
  dark styling today (no `dark:` classes, no Tailwind dark config). Planner
  must size this realistically — it likely warrants its own plan(s) within
  the phase. Aesthetic stays calm / content-first per PROJECT.md.
- **D-16: Dark mode follows the OS by default, plus a Light / Dark / System
  toggle in Settings** so a user can override the OS preference.

### Branding & Icons

- **D-17: Platform icon generation mechanism is Claude's discretion** —
  pick pre-rendered committed assets vs a build-time generation step based
  on what NativePHP 2.2 expects for icon inputs.
- **D-18: The brand mark appears in four places:** the OS bundle/dock icon,
  the system-tray icon, the in-app nav/header, and the login/signup screen.
- **D-19: The system-tray icon is a monochrome/template image** so it adapts
  natively to light/dark menu bars (macOS template-image convention);
  applied across all three OSes.
- **D-20: The logo asset moves from `.planning/brand/logo.svg` to
  `resources/brand/logo.svg`** — the canonical in-repo location PROJECT.md
  names. Phase 15 commits it there.

### First-Launch Experience

- **D-21: First-boot DB initialization shows a visible "Setting up…"
  screen** while migrations run, then proceeds.
- **D-22: After DB init on a fresh install, show a brief welcome screen**
  ("Welcome to diederik" + a "Get started" button), then the open `/signup`
  screen (open only while `User::count() === 0` per Phase 12 D-03).
- **D-23: Pending migrations run on EVERY app launch** (idempotent — a no-op
  when none are pending). This cleanly absorbs future updates that ship new
  migrations, supporting the Phase 18 auto-update story.

### Claude's Discretion

- `.eml` double-click UX shape (D-02).
- Platform icon generation mechanism (D-17).
- Exact NativePHP child-process supervision strategy for the worker +
  scheduler (D-05) — how they are spawned, restarted on crash, and torn
  down on quit.
- The "Setting up…" screen's exact appearance and the threshold/cadence of
  the timer-based email scan beyond the ~15-min default (D-06).
- Internal structure of `Modules/Desktop/`, the `FileOpenedFromOs` event
  payload shape, and the `noNativePhpImportsOutsideDesktopModule` arch test.
- Whether the close-button first-time prompt (D-08) is a native dialog or
  an in-app modal.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` § "Phase 15: Desktop Shell (NativePHP Integration)"
  — goal + 5 success criteria. **Note the SC3 routing caveat in
  `<code_context>` Integration Points.**
- `.planning/REQUIREMENTS.md` — PKG-04, PKG-05, PKG-06, PKG-07, PKG-08
  (the five requirements in scope).

### Project conventions & milestone context
- `.planning/PROJECT.md` — v2.0 milestone goal, the supplied logo asset
  (used as in-app brand + exported installer icons), Hippocratic License
  3.0 posture, calm / content-first aesthetic, local-only constraint,
  DI-only rule.
- `CLAUDE.md` — DI-only rule (constructor injection; no facades / global
  helpers; Eloquent models direct OK); modular-boundary rule (cross-module
  access via Public services/events only); queue/scheduler stack notes.
- `.planning/STATE.md` — current milestone position; carried-forward
  decisions.

### Prior-phase context this phase depends on
- `.planning/phases/13-app-paths/13-CONTEXT.md` — D-01: `NATIVEPHP_STORAGE_PATH`
  is the authoritative path-resolution signal; `UserDataPathService` routes
  all I/O. First-launch DB bootstrap (D-21/D-23) writes the SQLite file at
  the `UserDataPathService`-resolved location.
- `.planning/phases/14-queue-rewire-horizon-carve-out/14-CONTEXT.md` —
  shipped bundle uses `QUEUE_CONNECTION=database`; no Redis/Horizon in the
  `--no-dev` tree; `DIEDERIK_DEV_MODE` gates dev-only features
  (`DIEDERIK_RUNTIME` retired). Phase 14 explicitly deferred the
  shipped-build worker daemon to this phase (D-05).
- `.planning/phases/12-multi-user-activation/12-CONTEXT.md` — Fortify auth,
  `SESSION_DRIVER=database` (must work inside the bundle), `/signup` open
  only when `User::count() === 0`, remember-me must round-trip the webview,
  the `BoundaryArchTest` pattern the new arch invariant extends.

### Brand asset
- `.planning/brand/logo.svg` — the supplied logo (321 KB SVG). Phase 15
  moves it to `resources/brand/logo.svg` (D-20) and derives all platform
  icons + in-app brand from it.

No external ADRs — requirements fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Modules/Receipts/Public/Pipeline/FileDropEmlBlobStore.php` +
  `EmlMimeReader.php` + `ReceiptSourceAdapter.php` — the existing `.eml`
  drop-in pipeline; the `.eml` file-open intent (D-02) routes here.
- `Modules/Import/Public/Actions/` — `RunImport`, `ConfirmImport`,
  `DiscardImport`; `Modules/Import/Public/Dto/ImportPreviewResult.php`;
  `Modules/Import/Routes/web.php` — the CSV import preview/confirm flow the
  `.csv` staging page (D-01) leads into.
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` +
  `HeaderSniffer.php` — format detection / source adapters.
- `Modules/Core/Public/Services/SystemAlertQuery.php` + the `system_alerts`
  table + the v1.0 `SystemAlertsBanner` — reused for worker-health
  surfacing (D-07) and the focused-state half of the notification model
  (D-13).
- `Modules/Core/Public/Services/UserDataPathService.php` — path resolution
  under `NATIVEPHP_STORAGE_PATH`; the first-launch DB lands at its
  resolved SQLite path.
- `tests/Contracts/BoundaryArchTest.php` — host for the new
  `noNativePhpImportsOutsideDesktopModule` invariant; its existing
  allow-list carve-out pattern is the model to follow.
- `bootstrap/providers.php` — already uses a `class_exists()`-guarded
  conditional provider registration (Horizon); the new
  `Modules/Desktop/` service provider registers here.
- `resources/css/app.css` — minimal; Tailwind v4 CSS-first, **no dark-mode
  config and no `dark:` classes anywhere** — the full dark theme (D-15) is
  greenfield.

### Established Patterns
- DI-only: constructor injection everywhere; no facades / global helpers in
  module code; Eloquent models direct OK.
- Module Public/Internal split; cross-module access only via Public service
  classes or events. `Modules/Desktop/` follows the same shape.
- Migrations live inside the owning module's `Database/Migrations/`.
- The `tests/Contracts/*ArchTest.php` layer is the load-bearing safety net
  — every new boundary gets an arch-test invariant.
- v1.0 used macOS `launchd` plists for the scheduler + queue + IMAP-idle
  workers; the shipped bundle replaces these with NativePHP-supervised
  child processes (D-05).

### Integration Points
- **New `Modules/Desktop/`** — the sole permitted home for `Native\Laravel\*`
  imports; `BoundaryArchTest::noNativePhpImportsOutsideDesktopModule`
  forbids them everywhere else.
- **New `FileOpenedFromOs` event** (Public surface) — emitted by
  `Modules/Desktop/`; consumed by `.eml` → `Modules/Receipts` and `.csv` →
  the CSV import flow. ⚠ **SC3 routing caveat:** ROADMAP SC3 says `.csv`
  routes to `Modules/Ingestion`, but the user-facing CSV preview/confirm
  pipeline lives in `Modules/Import` (`Ingestion` provides format
  detection / adapters). The planner should route the `.csv` intent to
  whichever module owns the user-facing import flow — do not read the SC3
  wording over-literally.
- `composer.json` — add `nativephp/desktop ^2.2`.
- The worker + scheduler child processes (D-05) are NativePHP-supervised,
  replacing the v1.0 `launchd` plists for the shipped bundle.
- **Downstream phase dependencies:** Phase 16 (Dev Mode UI) builds on this
  shell; Phase 17 (CI/CD + signing) consumes the `native:build` target and
  the entitlements file; Phase 18 (auto-update) relies on the every-launch
  migration (D-23).

</code_context>

<specifics>
## Specific Ideas

- **`.csv` staging page copy:** "File received: `<name>`" with a "Start
  import" button (D-01).
- **First-launch welcome screen copy:** "Welcome to diederik" + a "Get
  started" button (D-22).
- **First-boot setup screen:** a visible "Setting up…" screen while
  migrations run (D-21).
- **Tray right-click menu, verbatim:** Open diederik / Scan email now /
  Quit (D-09).
- **Close-button first-time prompt:** "Quit" vs "Keep running in the tray"
  (D-08).
- **App-menu additions:** File → "Import file…", File → "Scan email now";
  Help → "GitHub repo", "Report an issue", "About diederik" (D-11).
- **Settings theme control:** Light / Dark / System (D-16).
- **Brand asset:** `.planning/brand/logo.svg` → `resources/brand/logo.svg`;
  drives bundle/dock icon, monochrome/template tray icon, in-app
  nav/header, and login/signup screen (D-18, D-19, D-20).

</specifics>

<deferred>
## Deferred Ideas

- **Code signing + notarization execution** — Phase 17. Phase 15 produces
  signed-READY installers and configures the macOS Hardened Runtime
  entitlements file (PKG-08); it does not run the signing pipeline.
- **Auto-update plumbing** — Phase 18. Phase 15's every-launch migration
  (D-23) is the supporting hook, but the Electron auto-updater itself is
  later.
- **Dev Console / queue inspector UI / ⌘K palette** — Phase 16.
- **Always-on IMAP-idle daemon inside the bundle** — deliberately not done;
  D-06 uses a timer-based scheduled scan instead. Revisit only if the
  ~15-min cadence proves too slow during the Phase 21 beta.

None of the above are scope creep — they are explicit phase boundaries from
the ROADMAP. Discussion stayed within Phase 15's domain.

</deferred>

---

*Phase: 15-Desktop Shell (NativePHP Integration)*
*Context gathered: 2026-05-22*
