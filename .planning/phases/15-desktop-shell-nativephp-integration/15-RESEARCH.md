# Phase 15: Desktop Shell (NativePHP Integration) - Research

**Researched:** 2026-05-22
**Domain:** Desktop application packaging — NativePHP 2.x / Electron, native chrome, OS file associations, child-process supervision, dark theming
**Confidence:** MEDIUM-HIGH (core NativePHP APIs HIGH; file-association cross-OS behavior MEDIUM — confirms STATE.md's 2-day spike flag)

## Summary

Phase 15 wraps the existing Laravel 13 / Livewire 4 app in a NativePHP 2.2 desktop shell. NativePHP 2.x is a mature, actively-maintained framework (`nativephp/desktop` 2.2.0, released 2026-04-11) built on Electron v38 with a static PHP runtime. The integration model is well-defined: install via `composer require nativephp/desktop` + `php artisan native:install`, configure native chrome inside a `NativeAppServiceProvider::boot()` method, and build installers with `php artisan native:build`. The single biggest cross-OS unknown — and the one STATE.md explicitly flagged for a 2-day spike — is file-association behavior (`.eml`/`.csv` double-click): NativePHP's config does not expose a first-class `fileAssociations` key, so this requires customizing the published Electron project (`native:install --publish`) and is subject to documented Electron quirks (the `open-file` event fires reliably only on macOS; Windows/Linux require parsing `process.argv` / `second-instance` argv).

The bundle ships **PHP 8.4**: `nativephp/php-bin` 1.2.0 (released 2026-05-21) now ships 8.3, 8.4 **and 8.5** binaries — so technically 8.5 is available, but the safe MVP choice per the phase brief is to pin the bundle to 8.4 and add an 8.4 CI axis (the project dev box stays 8.5). This is a `[ASSUMED]`/decision point worth surfacing: php-bin 8.5 builds now exist, which could let the bundle match the dev pin — the planner/discuss should confirm whether to bundle 8.4 (conservative, matches brief) or 8.5 (matches dev). The brief and ROADMAP SC4 both say 8.4; treat 8.4 as locked unless the user re-decides.

**Primary recommendation:** Create `Modules/Desktop/` with its own `NativeAppServiceProvider`, quarantine every `Native\Desktop\*` import there (arch-test enforced), and slice the phase into ~5 vertical waves: (1) install + `native:build` produces a launchable `.dmg`; (2) native chrome (window/menu/tray/notifications); (3) child-process supervision for worker + scheduler; (4) file-association handlers + `FileOpenedFromOs` event (allow a spike sub-task here); (5) the full dark theme retrofit (its own wave — large, greenfield). Run dark mode in parallel with 1–4 since it touches no NativePHP code.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `native:build` installer pipeline | Build tooling (`nativephp/electron`) | — | Electron-builder under the hood; not app runtime |
| Native window / menu / tray / dock icon | Desktop shell (`Modules/Desktop`, Electron main process) | — | OS chrome owned by Electron; PHP configures via NativePHP facades |
| OS notifications | Desktop shell | Frontend (in-app `SystemAlertsBanner`) | D-13 context-aware split: OS notif when backgrounded, banner when focused |
| Queue worker + scheduler supervision | Desktop shell (NativePHP ChildProcess) | API/backend (jobs themselves) | NativePHP supervises the OS process; the jobs are existing module code |
| File-association `.eml`/`.csv` open | Electron main process → Desktop shell | Receipts / Import modules | Electron receives the OS open event; PHP routes the intent |
| Dark theme | Frontend (Blade + Tailwind v4) | Desktop shell (OS theme signal only) | Pure CSS/Blade; `System::theme()` only feeds the `system` resolution |
| First-launch DB bootstrap | API/backend (migrations) | Desktop shell (gate the screen) | `migrate` runs server-side; Desktop shows the "Setting up…" screen |
| macOS entitlements | Build tooling | — | Consumed by codesign at build time (Phase 17 signs; Phase 15 configures) |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `nativephp/desktop` | `^2.2` (2.2.0, 2026-04-11) | Desktop shell meta-package — pulls `nativephp/electron` + `nativephp/php-bin` | The only NativePHP desktop path; v2 is current, actively maintained, Electron v38 |
| `nativephp/electron` | `^1.3` (transitive) | Electron build tooling + main-process bridge | Installed automatically by `native:install` |
| `nativephp/php-bin` | `^1.2` (1.2.0, 2026-05-21) | Static PHP binaries (8.3 / 8.4 / 8.5) bundled into the app | Transitive; ships the runtime so users need no PHP installed |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Node.js | 22+ | Required by Electron build pipeline | Dev box + CI must have it; not shipped to users |
| `@tailwindcss/vite` | `^4.0` (already installed) | Tailwind v4 CSS-first build | Dark mode delivered here — already in `package.json` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| NativePHP 2.x | Tauri / raw Electron | PROJECT.md locks NativePHP ("NativePHP committed — no spike-phase comparison"). Not in scope to reconsider. |
| Bundle PHP 8.4 | Bundle PHP 8.5 | php-bin 1.2.0 now ships 8.5 — bundling 8.5 would match the dev pin. Brief + ROADMAP SC4 say 8.4 (conservative). See Assumptions Log A1. |
| `native:install --publish` Electron customization for file assoc | Wait for native config key | NativePHP exposes no `fileAssociations` config key today — publishing the Electron project is the only route for `.eml`/`.csv` association. |

**Installation:**
```bash
composer require nativephp/desktop
php artisan native:install        # publishes config/nativephp.php, NativeAppServiceProvider, adds native:dev script
php artisan native:install --publish   # exports the Electron project for file-association customization
```

**Version verification (performed this session):**
- `nativephp/desktop` — Packagist confirms `2.2.0` published 2026-04-11; `^2.2` resolves correctly. `[VERIFIED: Packagist repo.packagist.org]`
- `nativephp/php-bin` — Packagist confirms `1.2.0` published 2026-05-21; GitHub releases confirm it ships PHP 8.3 / 8.4 / 8.5. `[VERIFIED: Packagist + GitHub releases]`
- `nativephp/electron` — latest `1.3.0` (2025-09-04); pulled transitively. `[VERIFIED: Packagist]`

## Package Legitimacy Audit

> slopcheck was not run in this environment (no `slopcheck` binary; pip unavailable for install in sandbox). Per protocol, packages are verified against Packagist directly and the NativePHP packages are tagged with provenance below. NativePHP is a well-known, widely-used framework (official Laravel-ecosystem project, maintained by Marcel Pociot / BeyondCode) — not a slop risk — but the planner should still gate the `composer require` behind a `checkpoint:human-verify` task per the graceful-degradation rule.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `nativephp/desktop` | Packagist | 2.0.0 since 2025-10; project since 2023 | High (official NativePHP) | github.com/NativePHP/desktop | not run | Approved — verified via official docs + Packagist |
| `nativephp/electron` | Packagist | since 2025-04 | High | github.com/NativePHP/electron | not run | Approved — transitive of `desktop` |
| `nativephp/php-bin` | Packagist | since 2025 | High | github.com/NativePHP/php-bin | not run | Approved — transitive of `desktop` |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

*slopcheck unavailable → planner should add a single `checkpoint:human-verify` task confirming `nativephp/desktop ^2.2` before the `composer require` runs. Cross-verified against official docs (nativephp.com) and Packagist — high confidence these are legitimate.*

## Architecture Patterns

### System Architecture Diagram

```
                          ┌─────────────────────────────────────────┐
   OS double-click ──────► │ Electron main process (Node)            │
   (.dmg launch /          │  • single-instance lock                 │
   .eml/.csv open)         │  • open-file / second-instance argv     │
                           │  • window / menu / tray / dock chrome   │
                           └───────────────┬─────────────────────────┘
                                           │ spawns + supervises
                                           ▼
                           ┌─────────────────────────────────────────┐
                           │ Bundled static PHP 8.4 (php-bin)         │
                           │  Laravel 13 HTTP server (serves webview) │
                           └───┬──────────────────┬──────────────────┘
                               │                  │
            NativeAppServiceProvider          ChildProcess (persistent)
            (Modules/Desktop)                 ┌──────────────────────┐
            • Window::open()->rememberState   │ queue:work (database) │
            • Menu::create(...)               │ schedule:work         │
            • MenuBar::create() (tray)        └──────────────────────┘
            • Notification dispatch                  │ drains
                               │                     ▼
                               │            jobs / failed_jobs (SQLite)
                               ▼
   FileOpenedFromOs event ──► Receipts module (.eml staging page)
                          └─► Import module   (.csv staging page → preview/confirm)
                               │
                               ▼
   System::theme() ──────► dark/light/system → <html class="dark"> (Blade/Tailwind v4)
```

Entry points: installer double-click (cold launch) and `.eml`/`.csv` double-click (cold launch OR running-app focus). Processing stages flow PHP-side: `NativeAppServiceProvider::boot()` configures chrome, spawns supervised child processes, then `Window::open()` shows the webview. File-open intents enter via the Electron main process and are surfaced to PHP as a `FileOpenedFromOs` domain event consumed by Receipts/Import.

### Recommended Project Structure
```
Modules/Desktop/
├── Providers/
│   └── DesktopServiceProvider.php      # registers the module; binds services
├── Public/
│   ├── Events/
│   │   └── FileOpenedFromOs.php        # Public surface — Receipts + Import listen
│   └── Contracts/                      # (if a thin desktop-capability seam is needed)
├── Internal/
│   ├── NativeAppServiceProvider.php    # the config/nativephp.php "provider" target
│   ├── Native/                         # Window / Menu / Tray / Notification builders
│   │   ├── AppMenuBuilder.php
│   │   ├── TrayMenuBuilder.php
│   │   └── ChildProcessSupervisor.php
│   ├── Listeners/                      # ProcessExited → SystemAlert + OS notification
│   └── Http/Livewire/                  # "Setting up…", welcome, .csv/.eml staging, close prompt
├── Database/Migrations/                # none expected (theme pref likely on users table → Auth/Core)
├── Routes/web.php                      # staging-page + boot routes
├── Resources/views/                    # boot / welcome / staging Blade
└── tests/

resources/brand/logo.svg                # D-20: moved from .planning/brand/
public/icon.png  public/icon.icns  public/icon.ico   # build-consumed icons (D-17)
```

> **Provider naming note:** `config/nativephp.php` has a `provider` key pointing at the class NativePHP boots for native config (default `App\Providers\NativeAppServiceProvider`). Point it at `Modules\Desktop\Internal\NativeAppServiceProvider` so all `Native\Desktop\*` calls stay inside the module. `[CITED: nativephp.com/docs/desktop/2/getting-started/configuration]`

### Pattern 1: NativeAppServiceProvider as the native-chrome entry point
**What:** NativePHP boots one designated provider's `boot()` method to configure window, menus, tray. This is where all `Native\Desktop\*` facade calls live.
**When to use:** All native chrome setup (PKG-05).
**Example:**
```php
// Source: nativephp.com/docs/desktop/2/the-basics/application-menu + windows
namespace Modules\Desktop\Internal;

use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;
use Native\Desktop\Facades\MenuBar;

class NativeAppServiceProvider
{
    public function boot(): void
    {
        Menu::create(
            Menu::app(),     // macOS only
            Menu::file(),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::help(),
        );

        Window::open()
            ->width(1100)->height(800)
            ->rememberState();          // D-10 — size + position persist

        MenuBar::create()               // D-09 — system tray
            ->icon(resource_path('brand/tray-icon.png'))   // template/monochrome image, D-19
            ->withContextMenu(/* Open diederik · Scan email now · Quit */);
    }
}
```

### Pattern 2: Persistent ChildProcess for the worker + scheduler (D-05)
**What:** NativePHP's `ChildProcess::start(..., persistent: true)` supervises a process supervisor-style — auto-restarts on crash, graceful teardown on app quit.
**When to use:** The shipped-build queue worker + scheduler (replaces v1.0 launchd plists).
**Example:**
```php
// Source: nativephp.com/docs/desktop/2/digging-deeper/child-processes + queues
use Native\Desktop\Facades\ChildProcess;

// Queue worker: NativePHP boots one automatically from config/nativephp.php
// 'queue_workers' => ['default' => ['queues' => ['default'], 'memory_limit' => 128, 'timeout' => 60, 'sleep' => 3]]

// Scheduler: the Laravel scheduler runs automatically every minute inside a
// NativePHP app — no manual ChildProcess needed. (D-06 timer-based email scan
// is just a normal scheduled task in routes/console.php / a module schedule.)
```
**Key facts** (HIGH confidence, from docs):
- The default queue worker is configured under `queue_workers` in `config/nativephp.php`; each entry becomes a persistent child process.
- The Laravel scheduler **runs automatically** inside a NativePHP app — no extra process to spawn. D-06's timer-based scan is a plain `->everyFifteenMinutes()` schedule entry.
- `persistent: true` makes NativePHP behave "similarly to supervisord" — auto-restart on crash.
- On app quit, persistent processes shut down gracefully.

### Pattern 3: Worker-health detection (D-07)
**What:** Listen for `Native\Desktop\Events\ChildProcess\ProcessExited` to detect a crashed worker.
**When to use:** Surfacing worker failure via `SystemAlertsBanner` + OS notification.
**Example:**
```php
// Source: nativephp.com/docs/desktop/2/digging-deeper/child-processes
// ProcessExited carries $alias and exit $code; ErrorReceived carries STDERR $data.
// Listener: on ProcessExited for the worker alias, write a system_alerts row
// AND (if window not focused) fire Notification::title('Background work stopped')...
```
**Gotcha:** The docs give no built-in "repeated failure" counter. Implement repeated-failure detection in a small listener: count `ProcessExited` events for the worker alias within a time window; escalate after N restarts. NativePHP auto-restarts persistent processes, so a crash-loop is the failure signal — not a single exit.

### Pattern 4: Context-aware notification (D-13)
**What:** Fire an OS `Notification` only when the app is backgrounded; otherwise let the in-app `SystemAlertsBanner` handle it.
**Example:**
```php
// Track focus state via WindowFocused / WindowBlurred events into a tiny
// service (e.g. WindowFocusState). A notification-dispatch listener checks it:
//   if (!$focus->isFocused()) { Notification::title(...)->message(...)
//        ->event(DeepLinkToScreen::class)->reference($screenRoute)->show(); }
//   else { /* SystemAlertsBanner already shows it */ }
```
- `Notification::...->event(SomeEvent::class)` fires that event on click — use it for D-14 deep-linking. `->reference($x)` rides along so the listener knows which screen to navigate to. `[CITED: nativephp.com/docs/desktop/1/the-basics/notifications]` (v2 API is the same shape; the notification event class moved namespace — verify exact class during planning).

### Pattern 5: Tailwind v4 class-strategy dark mode (D-15, D-16)
**What:** Tailwind v4 is CSS-first; the dark variant is declared in `app.css`, not a JS config.
**Example:**
```css
/* resources/css/app.css */
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
```
- Toggle a `dark` class on `<html>` server-side from the user's `theme` preference.
- For `theme = system`, an inline `<head>` script reads `prefers-color-scheme` before first paint (prevents flash). `System::theme()` (returns `SystemThemesEnum::LIGHT|DARK|SYSTEM`) feeds the resolution when running inside the bundle. `[CITED: nativephp.com/docs/desktop/2/the-basics/system]`
- The UI-SPEC already specifies the exact strategy and token table — follow it verbatim.

### Anti-Patterns to Avoid
- **`Native\Desktop\*` imports outside `Modules/Desktop/`** — breaks `noNativePhpImportsOutsideDesktopModule`. Even the boot screen's theme read goes through a Desktop-module service.
- **Spawning `queue:work` via a hand-rolled `Symfony\Process`** — NativePHP's `queue_workers` config + persistent ChildProcess already supervises. Don't reinvent it.
- **Assuming `open-file` fires reliably on Windows/Linux** — it does not. macOS uses the `open-file` event; Windows/Linux pass the path in `process.argv` / `second-instance` argv. Handle all three explicitly.
- **Calling `database_path()` directly** — Phase 13 forbids it; `UserDataPathService` resolves the SQLite path under `NATIVEPHP_STORAGE_PATH`.
- **A second app window on file-open** — D-03 requires focusing the existing window and navigating it (`Window::current()->url(...)`), not opening a new one.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Worker process supervision | A custom restart-loop wrapper | NativePHP `persistent: true` ChildProcess | Built-in supervisord-style restart + graceful teardown |
| Scheduler in the bundle | A spawned `schedule:work` process | NativePHP runs the scheduler automatically | Docs confirm scheduler fires every minute with zero setup |
| Installer generation (.dmg/.msi/.AppImage/.deb) | Manual electron-builder config | `php artisan native:build` | NativePHP wraps electron-builder; produces all three OS formats |
| OS notifications | A toast library / custom IPC | `Notification` facade | Native OS notifications with click-event dispatch built in |
| Single-instance lock | A pidfile / port-check | Electron's single-instance lock (in the published Electron project) | Standard Electron primitive; reliable cross-OS |
| Window position persistence | Storing x/y in SQLite yourself | `Window::open()->rememberState()` | Built-in; one window at a time |
| macOS template/dark-mode tray tinting | Manual light/dark icon swap | macOS template-image convention (monochrome PNG) | OS tints it for the menu-bar theme automatically (D-19) |

**Key insight:** Almost every "native" capability this phase needs is a first-class NativePHP facade or build feature. The genuinely custom work is narrow: the `Modules/Desktop/` arch boundary, the `FileOpenedFromOs` event + cross-OS file-association wiring (the one real spike), the worker-health listener, the pending-intent-survives-login round-trip, and the dark-theme retrofit.

## Runtime State Inventory

> Phase 15 is largely greenfield (new module + new chrome) but it **migrates the v1.0 background-worker model** from launchd to NativePHP, and **moves a brand asset**. Both have runtime-state implications.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None new. SQLite DB already relocates via Phase 13's `UserDataPathService` under `NATIVEPHP_STORAGE_PATH`. First-launch `migrate` (D-21/D-23) writes the DB at that resolved path. | Verify `migrate` runs against the NativePHP storage root — covered by Phase 13's simulated-env test; re-verify under a real bundle. |
| Live service config | **v1.0 launchd plists** (`deploy/launchd/` — scheduler + queue + IMAP-idle workers) are superseded by NativePHP-supervised child processes for the *shipped bundle*. The plists remain valid for the *Herd dev box*. | Do NOT delete the launchd plists (dev box still uses them). The bundle simply never installs them — it relies on NativePHP `queue_workers` + auto-scheduler. Document the split. |
| OS-registered state | **File associations** for `.eml` and `.csv` are OS-registered at install time (macOS `CFBundleDocumentTypes` in Info.plist; Windows registry; Linux `.desktop` MIME). These are written by the installer, not by app code. | The published Electron project's builder config registers them. A re-install or a NativePHP version bump can reset them — flag for the Phase 21 beta install test. |
| Secrets/env vars | `NATIVEPHP_STORAGE_PATH` (path resolution, Phase 13) and `DIEDERIK_DEV_MODE` (dev gating, Phase 14) — both consumed, neither renamed. `config/nativephp.php` introduces `cleanup_env_keys` (env vars stripped from the production bundle) — ensure secrets are not bundled. `APP_KEY` per-install regen is Phase 17 (CI-06), not here. | Set `cleanup_env_keys` so dev-only env (`DIEDERIK_DEV_MODE`, Redis vars) is stripped from the shipped bundle. No key renames. |
| Build artifacts | `native:build` output lands in `vendor/nativephp/electron/dist` (v2 moved it there). The `.dmg`/`.msi`/`.AppImage`/`.deb` are fresh build artifacts each run. | Add `dist/` and any NativePHP build temp dirs to `.gitignore`. Brand icons (`public/icon.*`) ARE committed (build inputs). |

**Nothing found for cross-module data migration** — no string-rename, no datastore-key change. The one asset move is `.planning/brand/logo.svg` → `resources/brand/logo.svg` (D-20): a plain `git mv` plus updating any reference. The `.planning/` copy can stay or go; `resources/brand/logo.svg` becomes canonical.

## Common Pitfalls

### Pitfall 1: `.eml`/`.csv` file-association is the real risk (STATE.md flagged it)
**What goes wrong:** Double-click works on macOS but silently fails on Windows/Linux, or the running app never receives the path.
**Why it happens:** Electron's `open-file` event is **macOS-only**. On Windows/Linux the path arrives as a command-line argument — on cold start in `process.argv`, on a running instance in the `second-instance` event's argv (which Electron mangles: it injects flags like `--allow-file-access-from-files` and may reorder args). NativePHP exposes no `fileAssociations` config key, so this requires `native:install --publish` and editing the Electron `main.js` + the electron-builder `fileAssociations` block directly.
**How to avoid:** Plan a dedicated spike sub-task (the STATE.md 2-day estimate). Handle three code paths: macOS `open-file`, Windows/Linux cold-start `argv` parse, and `second-instance` argv parse. Pass the file path to PHP cleanly — prefer Electron's `additionalData` over raw argv where possible. Test on all three OSes (or at minimum macOS + Windows per BETA-01).
**Warning signs:** "Open With diederik" does nothing; the app launches but lands on the dashboard instead of the staging page.

### Pitfall 2: Single-instance + file-open interaction
**What goes wrong:** A file double-clicked while diederik is already running spawns a second process (or the event is lost).
**Why it happens:** Without the single-instance lock, Electron starts a new process; with it, the second process must forward the file path to the first via `second-instance`. Electron docs warn the `open-file` event "doesn't always fire" when single-instance + fileAssociations are combined.
**How to avoid:** Acquire the single-instance lock in the Electron main process; in the `second-instance` handler, extract the path, focus the existing window (D-03), and navigate it. Treat both cold-start and second-instance paths as equivalent inputs to one `FileOpenedFromOs` emission.
**Warning signs:** Two dock icons; the import flow opens in a new window.

### Pitfall 3: File-open while logged out loses the intent (D-04)
**What goes wrong:** The file path is received, but the user isn't authenticated, so the redirect to `/login` discards it.
**Why it happens:** A naive handler emits `FileOpenedFromOs` → routes to the staging page → auth middleware bounces to `/login` → intent gone.
**How to avoid:** Persist the pending intent server-side keyed to the session (or a short-lived store) BEFORE the auth redirect. After successful login, a listener/middleware checks for a pending intent and continues to the staging page. The session driver is `database` (Phase 12 D-26) — it round-trips inside the bundle.
**Warning signs:** Login then lands on the dashboard, not the import staging page.

### Pitfall 4: Larastan L10 strict on NativePHP facades / PHP 8.4
**What goes wrong:** `native:build` ships PHP 8.4 but the dev box is 8.5; or Larastan flags `Native\Desktop\*` facade calls.
**Why it happens:** NativePHP facades are well-typed but new to the codebase; the project runs Larastan level 10 strict. The 8.4-vs-8.5 split means quality gates must pass on 8.4 too (PKG-07).
**How to avoid:** Add an 8.4 CI axis skeleton (PKG-07 / SC4). The DI-only rule (CLAUDE.md) forbids facades in module code — but `Native\Desktop\*` facades are unavoidable in `NativeAppServiceProvider`. Resolve this exactly like Phase 14's `LockStore` carve-out: allow-list the Desktop module's native-chrome files in `BoundaryArchTest`'s facade rule, and add matching `phpstan.neon` ignores. The `noNativePhpImportsOutsideDesktopModule` invariant is the *containment*; the facade allow-list is the *DI-rule exception*. Both are needed.
**Warning signs:** CI green on 8.5 but red on 8.4; arch test fails on the new module's own provider.

### Pitfall 5: Dark theme is a large, easily-underestimated retrofit (D-15)
**What goes wrong:** Treated as a quick `dark:` sprinkle; ends up half-themed with WCAG-failing contrast.
**Why it happens:** diederik has **zero** dark styling — no `dark:` classes, no dark config — across 13 modules of Blade views. Every `bg-white`/`text-slate-900` needs a `dark:` companion; the UI-SPEC bans `slate-500` body text on dark (must step to `slate-400`).
**How to avoid:** Give dark mode its own wave (CONTEXT.md D-15 explicitly says "likely warrants its own plan(s)"). Work module-by-module against the UI-SPEC token table. It touches no NativePHP code, so it can run in parallel with the shell waves.
**Warning signs:** Flash of light theme on boot; unreadable captions in dark mode.

### Pitfall 6: macOS Hardened Runtime entitlements missing → notarization fails
**What goes wrong:** The bundled static PHP runtime won't execute under notarization without the right entitlements.
**Why it happens:** Hardened Runtime blocks unsigned executable memory by default; an embedded interpreter needs `com.apple.security.cs.allow-unsigned-executable-memory` and `com.apple.security.cs.disable-library-validation`.
**How to avoid:** Configure the entitlements `.plist` (PKG-08) in the published Electron project's build config. Phase 15 only *configures* it — Phase 17 runs the actual signing/notarization. The two entitlements are named explicitly in CONTEXT.md D's scope and PKG-08.
**Warning signs:** App runs on the dev machine but crashes on a notarized build / another Mac.

## Code Examples

### Configure native chrome (PKG-05)
```php
// Source: nativephp.com/docs/desktop/2/the-basics/application-menu
namespace Modules\Desktop\Internal;

use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider
{
    public function boot(): void
    {
        Menu::create(
            Menu::app(), Menu::file(), Menu::edit(),
            Menu::view(), Menu::window(), Menu::help(),
        );

        Window::open()->width(1100)->height(800)->rememberState();

        MenuBar::create()
            ->icon(resource_path('brand/tray-icon.png'))
            ->withContextMenu(Menu::make(/* Open diederik · Scan email now · Quit */));
    }
}
```

### Persistent supervised child process (D-05 / D-07)
```php
// Source: nativephp.com/docs/desktop/2/digging-deeper/child-processes
use Native\Desktop\Facades\ChildProcess;

ChildProcess::start(
    cmd: ['php', 'artisan', 'queue:work', '--queue=default'],
    alias: 'diederik-worker',
    persistent: true,   // supervisord-style auto-restart
);
// NOTE: prefer NativePHP's config/nativephp.php 'queue_workers' key over a
// manual start() — it gives the same persistent supervision declaratively.
```

### Native notification with deep-link click (D-12 / D-14)
```php
// Source: nativephp.com/docs/desktop/1/the-basics/notifications (v2 API same shape)
use Native\Desktop\Facades\Notification;

Notification::title('Import finished')
    ->message('42 transactions added from ASN.')
    ->event(\Modules\Desktop\Public\Events\NotificationDeepLink::class)
    ->reference(route('imports.results', $importId))
    ->show();
```

### Tailwind v4 dark variant (D-15)
```css
/* resources/css/app.css */
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| NativePHP v1 (`Native\Laravel\*`) | NativePHP v2 (`Native\Desktop\*`) | 2.0.0, Oct 2025 | Facade namespace changed — `Native\Desktop\Facades\*`. The arch test must forbid `Native\Desktop\*` (not `Native\Laravel\*`). CONTEXT.md says `Native\Laravel\*` — that wording is v1; v2 namespace is `Native\Desktop`. See Assumptions Log A2. |
| Electron v1x | Electron v38 | NativePHP 2.0 | Newer Chromium; "Enhanced Security by Default" |
| launchd plists for workers | NativePHP persistent ChildProcess + auto-scheduler | This phase | Shipped bundle no longer needs launchd; dev box keeps it |
| `database_path()` direct calls | `UserDataPathService` | Phase 13 | SQLite resolves under `NATIVEPHP_STORAGE_PATH` |
| Tailwind v3 JS `darkMode` config | Tailwind v4 `@custom-variant` in CSS | Tailwind v4 | Dark mode declared CSS-first; no `tailwind.config.js` |

**Deprecated/outdated:**
- NativePHP v1 docs and the `Native\Laravel\*` namespace — do not crib v1 examples; the facade namespace and several APIs changed in v2.
- `predis`/Horizon in the shipped tree — already carved out in Phase 14; the bundle is `database` queue + `database` cache locks.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Bundle ships **PHP 8.4** (not 8.5) even though php-bin 1.2.0 now ships 8.5 binaries | Summary / Standard Stack | Low — both work; brief + ROADMAP SC4 say 8.4. If the user prefers matching the 8.5 dev pin, the bundle target + CI axis change. Worth a one-line confirm in discuss/planning. |
| A2 | The v2 facade/import namespace is `Native\Desktop\*`; the arch invariant must target `Native\Desktop`, not `Native\Laravel` (CONTEXT.md's `Native\Laravel\*` wording is v1-era) | State of the Art / Architecture | Medium — if the arch test forbids the wrong namespace it passes vacuously. Verify the exact namespace from a fresh `composer require nativephp/desktop` install before writing the invariant. |
| A3 | `config/nativephp.php` `provider` key can point at a module-namespaced provider (`Modules\Desktop\Internal\NativeAppServiceProvider`) | Architecture Patterns | Low — it's a plain class string; any autoloadable class works. |
| A4 | The Laravel scheduler runs automatically inside the bundle with no extra ChildProcess (so D-06's timer scan is a plain schedule entry) | Pattern 2 | Low-Medium — docs state the scheduler "runs as normal every minute". Verify in the dev build smoke test. |
| A5 | File associations require `native:install --publish` + manual Electron/electron-builder config (no first-class NativePHP config key) | Pitfall 1 | Medium — this is the spike. If a newer NativePHP point release adds a `fileAssociations` key, the work shrinks. Re-check NativePHP 2.2.x changelog at planning time. |
| A6 | `native:build` output lands in `vendor/nativephp/electron/dist` | Runtime State Inventory | Low — confirmed by a v2 search result; verify and `.gitignore` accordingly. |

**These assumptions need confirmation before becoming locked decisions** — especially A1 (8.4 vs 8.5) and A2 (arch-test namespace).

## Open Questions (RESOLVED)

1. **Exact `FileOpenedFromOs` cross-OS plumbing**
   - What we know: macOS `open-file` event; Windows/Linux `argv` / `second-instance`; needs the published Electron project.
   - What's unclear: the cleanest PHP-side surface NativePHP gives for a file path passed at launch (is there an `OpenedFile`-style event in v2, or must the Electron `main.js` POST to a Laravel route?).
   - Recommendation: Make this the explicit spike sub-task STATE.md flagged. Budget 1–2 days; plan it as a self-contained wave so a slip doesn't block the rest.
   - **RESOLVED: deferred to spike task in plan 15-04 Task 1.**

2. **macOS entitlements file location in the published Electron project**
   - What we know: two entitlements are required (PKG-08); the build consumes a `.plist`.
   - What's unclear: the exact path NativePHP 2.2's electron-builder config expects (`build/entitlements.mac.plist` is the electron-builder convention).
   - Recommendation: Run `native:install --publish`, inspect the generated `electron-builder` config, place the entitlements `.plist` where it points. Verify against the electron-builder `mac.entitlements` convention.
   - **RESOLVED: electron-builder convention `build/entitlements.mac.plist`; verify after `native:install --publish`.**

3. **`.eml` staging-page UX (D-02 — Claude's discretion)**
   - What we know: D-02 leaves it to discretion; CONTEXT.md expects a staging page mirroring `.csv` (D-01); the UI-SPEC already specifies "File received: `<name>`" + "Start import" for both.
   - Recommendation: Mirror the `.csv` pattern — the UI-SPEC has already locked the copy. Route `.eml` into the existing `FileDropEmlBlobStore` pipeline after the staging click.
   - **RESOLVED: mirror the `.csv` staging-page pattern per 15-UI-SPEC.md.**

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP (dev box) | Build + dev | ✓ | 8.5.0alpha1 | — |
| Node.js | Electron build pipeline | ? — not probed (sandbox) | needs 22+ | None — `native:build` cannot run without it |
| `nativephp/desktop` | Whole phase | ✗ (not yet installed) | install `^2.2` | None — core dependency |
| macOS | `.dmg` build + smoke test (SC1) | ✓ (dev box is darwin) | macOS 24.6 | — |
| Windows / Linux machine | `.msi`/`.AppImage`/`.deb` smoke test | ✗ | — | CI matrix (Phase 17) or beta testers (Phase 21); Phase 15 produces the artifacts, full cross-OS smoke is later |

**Missing dependencies with no fallback:**
- `nativephp/desktop` — must be installed (the phase's purpose).
- Node.js 22+ — the planner must add a verification/install step; `native:build` is impossible without it. Probe `node --version` at plan-execution start.

**Missing dependencies with fallback:**
- Windows/Linux test machines — `native:build --os=win/linux` produces the artifacts on the macOS box (cross-compilation, "not supported on all platforms" — verify); real cross-OS install testing defers to Phase 17 CI / Phase 21 beta. Phase 15 SC1 only requires the macOS `.dmg` smoke test.

## Validation Architecture

> `nyquist_validation` is `true` in `.planning/config.json` — this section is included.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.x (on PHPUnit) + `pest-plugin-arch` |
| Config file | `phpunit.xml` (project root) — present |
| Quick run command | `./vendor/bin/pest --filter=<name>` |
| Full suite command | `./vendor/bin/pest` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PKG-04 | `native:build` produces a launchable `.dmg` | smoke (manual) | manual — launch `/Applications/diederik.app`, see dashboard | ❌ manual-only — documented in a HUMAN-UAT artifact (build is not CI-automatable in Phase 15) |
| PKG-05 | `noNativePhpImportsOutsideDesktopModule` arch invariant | arch | `pest --filter="NativePhp imports"` | ❌ Wave 0 — extend `tests/Contracts/BoundaryArchTest.php` |
| PKG-05 | Native chrome configured (window/menu/tray) | unit | `pest Modules/Desktop/tests/.../NativeAppServiceProviderTest.php` | ❌ Wave 0 |
| PKG-06 | `FileOpenedFromOs` routes `.csv`→Import, `.eml`→Receipts | feature | `pest Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php` | ❌ Wave 0 |
| PKG-06 | Pending file-intent survives login round-trip (D-04) | feature | `pest --filter="pending intent survives login"` | ❌ Wave 0 |
| PKG-06 | Single-instance focuses existing window (D-03) | feature/unit | `pest --filter="single instance file open"` | ❌ Wave 0 |
| PKG-07 | Larastan L10 strict + Pint + Pest green on PHP 8.4 | static + suite | `php8.4 vendor/bin/phpstan` / `pint --test` / `php8.4 vendor/bin/pest` | ❌ Wave 0 — CI 8.4 axis skeleton |
| PKG-08 | macOS entitlements `.plist` contains both required keys | unit/file-assertion | `pest --filter="hardened runtime entitlements"` | ❌ Wave 0 — assert the `.plist` content |
| D-07 | Worker `ProcessExited` → `system_alerts` row + notification | feature | `pest --filter="worker crash surfaces alert"` | ❌ Wave 0 |
| D-13 | OS notification only when window unfocused | unit | `pest --filter="notification suppressed when focused"` | ❌ Wave 0 |
| D-15 | No `bg-white`/`text-slate-900` without a `dark:` companion | arch/grep | a Blade-scanning test or `composer` grep gate | ❌ Wave 0 — optional but recommended |

### Sampling Rate
- **Per task commit:** `./vendor/bin/pest --filter=<task-area>` (the touched module/feature).
- **Per wave merge:** `./vendor/bin/pest` (full suite) + `vendor/bin/phpstan` + `vendor/bin/pint --test`.
- **Phase gate:** Full suite green on **both** PHP 8.5 (dev) and PHP 8.4 (bundle axis) before `/gsd:verify-work`; plus the manual `.dmg` launch smoke test.

### Wave 0 Gaps
- [ ] `tests/Contracts/BoundaryArchTest.php` — add `noNativePhpImportsOutsideDesktopModule` invariant + Desktop-module facade allow-list (extends the existing `LockStore` carve-out pattern).
- [ ] `Modules/Desktop/tests/` — new test directory + `composer.json` autoload-dev PSR-4 entry (`Modules\Desktop\Tests\`).
- [ ] `Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php` — covers PKG-06 routing + D-03/D-04.
- [ ] `Modules/Desktop/tests/Unit/` — `NativeAppServiceProvider`, tray/menu builders, worker-health listener, focus-state notification gating.
- [ ] CI 8.4 axis skeleton — a minimal matrix entry running phpstan + pint + pest on PHP 8.4 (full matrix is Phase 17).
- [ ] NativePHP facade testing: NativePHP 2.x facades are fakeable (`Shell` fake confirmed in v2 release notes — verify `Window`/`Menu`/`MenuBar`/`Notification`/`ChildProcess` fakes exist) so chrome configuration is unit-testable without a real Electron process. The availability of the `Window`/`Menu`/`MenuBar`/`Notification` fakes is UNVERIFIED — plan 15-01 Task 2 includes a verification step that inspects the installed package and records the result so plan 15-02's TDD tasks know whether to assert against fakes or defer to manual UAT.
- [ ] HUMAN-UAT artifact for the `.dmg` build + launch smoke test (PKG-04 SC1) — not CI-automatable.

## Security Domain

> `security_enforcement` is not present in `.planning/config.json` — treated as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Fortify (Phase 12) — must keep working inside the bundle; session driver `database` round-trips the webview |
| V3 Session Management | yes | `SESSION_DRIVER=database` (Phase 12 D-26); the pending file-intent (D-04) is session-scoped — must not leak across users |
| V4 Access Control | yes | File-open staging pages sit behind `auth` middleware; `FileOpenedFromOs` consumers run as the logged-in user; cross-user 404 rules (Phase 12) still apply |
| V5 Input Validation | yes | The OS-supplied file path is untrusted input — validate extension is exactly `.eml`/`.csv`, reject paths outside expected dirs, never `exec()` it. The staging page resolves the file via existing validated pipelines (`FileDropEmlBlobStore`, Import) |
| V6 Cryptography | partial | `cleanup_env_keys` must strip secrets from the bundle; `APP_KEY` per-install regen is Phase 17 (CI-06). No new crypto here. NativePHP `System::encrypt()` is available but not needed this phase |
| V12 Files & Resources | yes | File-association handler must treat the OS path as hostile: canonicalize, check extension, size-limit before reading |

### Known Threat Patterns for NativePHP / Electron desktop

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Malicious file path via "Open With" (argv injection) | Tampering / EoP | Validate extension allow-list; never shell-execute the path; route through existing validated ingestion pipelines |
| Electron renderer with `nodeIntegration` enabled | Elevation of Privilege | Keep NativePHP's v2 "secure by default" settings; don't enable `nodeIntegration` in the published Electron project |
| Secrets bundled into the shipped app | Information Disclosure | `cleanup_env_keys` strips dev/secret env; `.env.bundled` template (Phase 17) carries no real secrets |
| Unsigned executable memory blocked by Hardened Runtime | DoS (app won't launch) | Configure the two PKG-08 entitlements; sign + notarize in Phase 17 |
| File-open intent leaking across users (multi-user box) | Information Disclosure | Pending intent keyed to the authenticated session; re-check `CurrentUser` after login before continuing the import |
| Local web server bound beyond loopback | Information Disclosure | NativePHP serves the app on loopback only — verify the bundle does not expose a routable port |

## Sources

### Primary (HIGH confidence)
- [NativePHP Desktop v2 — Installation](https://nativephp.com/docs/desktop/2/getting-started/installation) — package name, `native:install`, PHP 8.3+ / Laravel 11+, Node 22+
- [NativePHP Desktop v2 — Building](https://nativephp.com/docs/desktop/2/publishing/building) — `native:build`, `--os` arg, prebuild/postbuild hooks, code-signing config
- [NativePHP Desktop v2 — Child Processes](https://nativephp.com/docs/desktop/2/digging-deeper/child-processes) — `ChildProcess::start(persistent:true)`, ProcessSpawned/Exited/MessageReceived/ErrorReceived, graceful teardown
- [NativePHP Desktop v2 — Queues](https://nativephp.com/docs/desktop/2/digging-deeper/queues) — `queue_workers` config, auto worker, scheduler runs automatically
- [NativePHP Desktop v2 — Application Menus](https://nativephp.com/docs/desktop/2/the-basics/application-menu) — `Menu::create()`, `Menu::app/file/edit/view/window`
- [NativePHP Desktop v2 — Menu Bar](https://nativephp.com/docs/desktop/2/the-basics/menu-bar) — `MenuBar::create()`, icon, `withContextMenu()`, MenuBar events
- [NativePHP Desktop v2 — Windows](https://nativephp.com/docs/desktop/2/the-basics/windows) — `Window::open()`, `rememberState()`, `current()`, `url()`, window events
- [NativePHP Desktop v2 — System](https://nativephp.com/docs/desktop/2/the-basics/system) — `System::theme()`, `SystemThemesEnum`
- [NativePHP Desktop v2 — Configuration](https://nativephp.com/docs/desktop/2/getting-started/configuration) — `config/nativephp.php` keys, `provider`, `deeplink_scheme`, `cleanup_env_keys`
- [NativePHP Desktop v2 — Release Notes](https://nativephp.com/docs/desktop/2/getting-started/releasenotes) — v2.2.0 (2026-04-11), Electron v38, faking facades
- [NativePHP/php-bin GitHub Releases](https://github.com/NativePHP/php-bin/releases) — 1.2.0 (2026-05-21) ships PHP 8.3/8.4/8.5
- Packagist API (`repo.packagist.org`) — version + date verification for `nativephp/desktop`, `nativephp/php-bin`, `nativephp/electron`

### Secondary (MEDIUM confidence)
- [NativePHP Desktop v1 — Notifications](https://nativephp.com/docs/desktop/1/the-basics/notifications) — `Notification::title()->message()->event()->reference()->show()` (v2 API same shape; verify exact event class namespace)
- [NativePHP for Desktop v2 Released — Blog](https://nativephp.com/blog/nativephp-for-desktop-v2-released) — Electron v38, secure-by-default
- [Apple — Allow Unsigned Executable Memory Entitlement](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.cs.allow-unsigned-executable-memory) — entitlement semantics for embedded runtimes
- [Kilian Valkhof — Notarizing your Electron application](https://kilianvalkhof.com/2019/electron/notarizing-your-electron-application/) — Electron + Hardened Runtime entitlements

### Tertiary (LOW confidence — needs validation)
- [Electron #14029 — fileAssociations + makeSingleInstance "open-file" doesn't always fire](https://github.com/electron/electron/issues/14029) — cross-OS file-association quirks
- [Electron #23220 / #20322 — second-instance argv handling](https://github.com/electron/electron/issues/23220) — argv mangling on Windows
- [Electron app API docs](https://www.electronjs.org/docs/latest/api/app) — `second-instance`, `open-file`, `additionalData`

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — versions verified on Packagist + GitHub this session; NativePHP 2.2 is current and documented.
- Native chrome (window/menu/tray/notifications): HIGH — all are documented first-class v2 facades.
- Child-process supervision: HIGH — `persistent` ChildProcess + auto-scheduler are documented.
- File associations: MEDIUM — confirmed there is no first-class config key; the cross-OS path is a documented Electron pain point. This is the STATE.md-flagged spike.
- Dark theme: HIGH on the Tailwind v4 mechanism; effort sizing is the risk (greenfield across 13 modules).
- macOS entitlements: MEDIUM — the two required keys are known; the exact published-Electron-project path needs verification at planning time.

**Research date:** 2026-05-22
**Valid until:** 2026-06-21 (NativePHP is fast-moving — re-verify `nativephp/desktop` version + check 2.2.x changelog for a `fileAssociations` config key before planning execution).
