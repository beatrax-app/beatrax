# Phase 15: Desktop Shell (NativePHP Integration) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-22
**Phase:** 15-Desktop Shell (NativePHP Integration)
**Areas discussed:** File-open intent UX, Background work in bundle, Tray/window & menu, OS notifications, Dark-mode scope, App-icon/branding pipeline, First-launch experience

---

## File-Open Intent UX

### `.csv` double-click landing destination

| Option | Description | Selected |
|--------|-------------|----------|
| Straight to import preview | Routes directly into the Import preview screen, ready to confirm | |
| Staging page first | Neutral "File received" page with explicit Start import button | ✓ |
| Dashboard + banner | Lands on dashboard with a "File ready to import" banner | |

### Behavior when app already running

| Option | Description | Selected |
|--------|-------------|----------|
| Focus window + navigate to import | Single-instance: brings window front, navigates to import flow | ✓ |
| Focus window + non-disruptive prompt | Brings window front, shows a dismissible toast instead | |
| New window | Spawns a second window for the import | |

### File double-clicked while not logged in

| Option | Description | Selected |
|--------|-------------|----------|
| Hold intent through login | Show login, remember the file, continue to import after auth | ✓ |
| Drop the file, just log in | Show login, discard the file intent | |

### `.eml` double-click UX

| Option | Description | Selected |
|--------|-------------|----------|
| Ingest + show result | Process immediately, show parsed-receipt outcome | |
| Receipts review screen | Staging screen before the pipeline runs | |
| You decide | Let planning pick the cleanest fit with FileDropEmlBlobStore | ✓ |

**User's choice:** Staging page first; single-instance focus+navigate; hold intent through login; `.eml` left to Claude's discretion.
**Notes:** A staging page (vs jumping straight into parsed UI) keeps file-open from being disruptive. `.eml` expected to mirror the `.csv` staging pattern for consistency.

---

## Background Work in Bundle

### Background processing in the packaged app

| Option | Description | Selected |
|--------|-------------|----------|
| Worker + scheduler | Bundle runs both — queued + scheduled work happens automatically | ✓ |
| Worker only | Queue worker drains dispatched jobs; no scheduler | |
| Import-only | No background processes in the bundle at all | |

### Email scanning approach in the bundle

| Option | Description | Selected |
|--------|-------------|----------|
| Scheduled auto-scan | Timer-based periodic scan (~15-min fallback cadence), no IMAP-idle daemon | ✓ |
| Manual scan button | Email scanning only on explicit button press | |
| You decide | Defer to planning | |

### Worker crash / repeated job failure surfacing

| Option | Description | Selected |
|--------|-------------|----------|
| SystemAlertsBanner | In-app banner only | |
| Banner + OS notification | In-app banner plus a native OS notification | ✓ |
| You decide | Defer to planning | |

**User's choice:** Worker + scheduler both run; timer-based scheduled email scan; worker failures surface via banner + OS notification.
**Notes:** "It just works" for the non-technical partner. Timer-based scanning chosen over the always-on IMAP-idle daemon for simpler Electron child-process supervision.

---

## Tray, Window & Menu

### Window close (X) button behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Minimize to tray | App keeps running in tray; quit only via menu | |
| Quit the app | Closing the window fully quits diederik | |
| Ask the first time | Prompt on first close, remember the choice | ✓ |

### Tray icon click behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Toggle window + right-click menu | Left-click toggles window; right-click menu (Open / Scan / Quit) | ✓ |
| Always open a menu | Any click opens a menu | |
| Toggle window only | Click toggles window; no tray menu | |

### Window size/position persistence

| Option | Description | Selected |
|--------|-------------|----------|
| Persist size + position | Window reopens where the user left it | ✓ |
| Fixed default, centered | Always opens at a fixed size, centered | |
| You decide | Defer to planning | |

### diederik-specific app-menu items

| Option | Description | Selected |
|--------|-------------|----------|
| Practical actions + Help links | File → Import file… / Scan email now; Help → GitHub / Report issue / About | ✓ |
| Help links only | Only Help-menu additions | |
| Standard items only | No diederik-specific entries | |

**User's choice:** Close button asks on first close; tray left-click toggles window + right-click menu; window persists size+position; app menu gains practical actions + Help links.
**Notes:** Keeping the app in the tray keeps the worker + scheduler alive for scheduled scans.

---

## OS Notifications

### Event categories firing native OS notifications (multi-select)

| Option | Description | Selected |
|--------|-------------|----------|
| Drift alerts | Subscription price change / new recurring charge | ✓ |
| Import finished | A triggered file import completing/failing | ✓ |
| New receipts found | Scheduled email scan ingested new receipts | ✓ |
| Forecast shortfall | A projected cash-flow shortfall window | ✓ |

### Notification model vs in-app SystemAlertsBanner

| Option | Description | Selected |
|--------|-------------|----------|
| Context-aware | OS notification only when backgrounded; banner when focused | ✓ |
| Selective mirror | Chosen events always fire both notification and banner | |
| Full mirror | Every system_alerts row also fires an OS notification | |

### Clicking an OS notification

| Option | Description | Selected |
|--------|-------------|----------|
| Deep-link to relevant screen | Focuses app and navigates to the event's screen | ✓ |
| Just focus the app | Brings app front on the dashboard | |
| You decide | Defer to planning | |

**User's choice:** All four event categories fire OS notifications (plus worker/system errors carried from the background-work discussion); context-aware model; clicking deep-links to the relevant screen.
**Notes:** Context-aware model avoids double-notifying when the window is focused; the banner stays the full record.

---

## Dark-Mode Scope

### Depth of dark-mode work this phase

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal calm dark palette | Theme layout shell + nav + dashboard + common components | |
| Full dark theme | Every module's Blade views get polished dark variants | ✓ |
| Detect + persist only | Wire the OS signal but ship no dark CSS | |

### OS-follow vs in-app control

| Option | Description | Selected |
|--------|-------------|----------|
| Strictly follow OS | No in-app theme control | |
| Follow OS + Settings toggle | OS default plus a Light/Dark/System toggle | ✓ |

**User's choice:** Full dark theme across all modules; follow OS by default with a Settings toggle.
**Notes:** diederik has zero dark styling today — a full theme is a large effort and the planner must size it deliberately (likely its own plan(s) within the phase).

---

## App-Icon / Branding Pipeline

### Platform icon generation

| Option | Description | Selected |
|--------|-------------|----------|
| Pre-rendered committed assets | Render icon set once, commit static files | |
| Build-time generation step | Script renders icons from the SVG during native:build | |
| You decide | Pick based on NativePHP 2.2 icon-input expectations | ✓ |

### Brand-mark placement in-app

| Option | Description | Selected |
|--------|-------------|----------|
| Icon + tray + in-app header | Bundle icon, tray icon, nav/header | |
| Icon + tray + header + login | Also on the login/signup screen | ✓ |
| Bundle icon only | OS icon only; in-app brand later | |

### Tray-icon treatment

| Option | Description | Selected |
|--------|-------------|----------|
| Monochrome template icon | Adapts to light/dark menu bars natively | ✓ |
| Full-color logo | Full-color in the tray on every OS | |
| You decide | Defer to planning | |

**User's choice:** Icon generation mechanism left to Claude; brand mark on bundle icon + tray + header + login; monochrome/template tray icon.
**Notes:** Logo moves from `.planning/brand/logo.svg` to `resources/brand/logo.svg` (canonical per PROJECT.md).

---

## First-Launch Experience

### First-boot DB initialization appearance

| Option | Description | Selected |
|--------|-------------|----------|
| Silent auto-migrate | Migrate silently with a brief loading state | |
| Visible "Setting up…" screen | Dedicated setup/progress screen while migrations run | ✓ |
| You decide | Defer to planning | |

### First screen on a fresh install

| Option | Description | Selected |
|--------|-------------|----------|
| Straight to signup | Land directly on the signup form | |
| Brief welcome screen | "Welcome to diederik" + Get started, then signup | ✓ |

### Migration-on-launch policy

| Option | Description | Selected |
|--------|-------------|----------|
| Every launch | Run pending migrations on every launch (idempotent) | ✓ |
| First launch only | Migrate only on initial install | |
| You decide | Defer to planning | |

**User's choice:** Visible "Setting up…" screen; brief welcome screen before signup; migrations run on every launch.
**Notes:** Every-launch migration cleanly absorbs future update migrations from the Phase 18 auto-update story.

---

## Claude's Discretion

- `.eml` double-click UX shape — cleanest fit with the `FileDropEmlBlobStore` pipeline.
- Platform icon generation mechanism — pre-rendered vs build-time, per NativePHP 2.2 expectations.
- NativePHP child-process supervision strategy for the worker + scheduler.
- The "Setting up…" screen's exact appearance and the timer cadence beyond the ~15-min default.
- Internal structure of `Modules/Desktop/`, the `FileOpenedFromOs` event payload, and the arch test.
- Whether the close-button first-time prompt is a native dialog or an in-app modal.

## Deferred Ideas

- Code signing + notarization execution — Phase 17 (Phase 15 produces signed-ready installers + the entitlements file).
- Auto-update plumbing — Phase 18.
- Dev Console / queue inspector / ⌘K palette — Phase 16.
- Always-on IMAP-idle daemon inside the bundle — deliberately not done; revisit if the timer cadence proves too slow during the Phase 21 beta.
