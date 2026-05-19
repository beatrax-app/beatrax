# Feature Research — diederik v2.0

**Milestone:** v2.0 — Public Release (Desktop Packaging + Multi-User + Developer Mode + CI/CD)
**Domain:** Code-signed desktop app shell over an existing Laravel personal-finance dashboard, with multi-user activation, an in-app developer console, first-run onboarding, and a beta-cycle feedback loop.
**Researched:** 2026-05-19
**Confidence:** HIGH for the desktop-shell / multi-user / dev-mode UI patterns (verified against NativePHP v2 docs + Electron docs + Slack/1Password/Linear/Raycast/Beekeeper/TablePlus precedents); MEDIUM for the password-reset-without-SMTP question (the field has no single dominant pattern — we're synthesising across 1Password / Beekeeper / Bitwarden / Standard Notes); HIGH for the telemetry stance (the privacy-first cohort is unanimous).

## Executive Framing

v2.0 is a **shell + activation + console** milestone, not a domain-feature milestone. The Laravel app behind the shell is already shipped and validated (v1.0, 11 phases, 1644 tests green). The user-facing question for v2.0 is: *"Does diederik feel like a real desktop app, can two people share it, and can the developer fix it from inside the app instead of from a terminal?"*

The category mash-up (desktop shell + multi-user dashboard + dev-mode panel + first-run + beta feedback) doesn't have a single comparable product. Closest precedents:

- **Desktop shell** — NativePHP v2 (official Laravel-native), with patterns cribbed from Linear (calm shell, no notification spam), TablePlus / Beekeeper Studio (data-tool desktop apps), Tinkerwell (Laravel-native menubar app).
- **Multi-user activation** — Firefly III's own painful experience (shipped single-user, has been retrofitting "administrations" sharing for years, still not done in 2026) is the *cautionary tale*. The right reference is Slack workspace switcher + 1Password multi-account.
- **Developer Mode UI** — Telescope + Horizon dashboards are the Laravel-canonical "inside the app" feel; Raycast is the canonical command-palette reference; Postman / TablePlus's query runner are the right UX for the SQL panel.
- **First-run + onboarding** — Monarch Money's wizard style + Linear's progressive onboarding are the calm references; avoid the dense fintech-app pattern.
- **Beta / telemetry** — Signal / Standard Notes / Cryptee are the privacy-first benchmark: *opt-in only, never default-on, never a dark pattern*.

**The differentiator is the in-app developer console.** The user is a Laravel developer who explicitly wants to debug, restore backups, run `diederik:doctor`, prune failed jobs, and inspect SQLite *without leaving the app window*. No personal-finance product does this — every comparable tool assumes a non-technical user. This is a deliberate, single-user-perspective feature; partner users will simply never see the toggle.

**The biggest risks for v2.0:**
1. **Password reset without SMTP in a desktop context** — there is no industry-standard answer. We recommend printed recovery codes + filesystem-fallback "reset via doctor probe" rather than reinventing email.
2. **NativePHP file-association / deep-link / launch-at-login on Windows + Linux** — well-supported on macOS, less battle-tested on the other two platforms; flag for phase-specific spike research.
3. **Destructive artisan commands behind a panel that wasn't designed for them** — `migrate:fresh` from a web UI on a financial database is genuinely dangerous. Gating must be ironclad.
4. **Telemetry by default** — antithetical to v1.0's local-only stance. Recommendation: explicit opt-in, *off by default*, never silent.

## Feature Landscape

This section is organised by the **five v2.0 capability areas** rather than the v1.0-style alphabetical feature matrix. Each area has its own Table Stakes / Differentiators / Anti-Features split.

---

### Area 1 — Desktop App Shell

The user-perceptible "this is a real macOS / Windows / Linux app" layer that NativePHP gives us.

#### Table Stakes (Desktop Shell)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **App window with native chrome** (close / minimise / maximise; macOS traffic lights; Windows / Linux equivalents) | Universal — any Electron app gets this for free; users notice if it's missing | S | NativePHP v2's `Window::open()` defaults; `NativePHP/Windows.php` config. |
| **Native app icon (Dock / taskbar / Start menu)** | Universal; missing = "this looks like a hobby project" | S | PNG / ICO / ICNS variants generated from `resources/brand/logo.svg`. NativePHP icon pipeline auto-generates `.icns` and `.ico` at build. |
| **App menu with standard items** (File / Edit / View / Window / Help; per-OS conventions) | Mac users expect "About diederik" + "Quit" under app name; Windows expects Alt+F File menu; Linux follows Mac/GTK conventions | M | NativePHP `Menu` facade; `Menu::app()` for the app menu, `Menu::default()` for window menu. Include Edit menu (Cut/Copy/Paste/Select All) so OS shortcuts work in form inputs. |
| **System tray icon (Windows/Linux) / menu bar icon (macOS)** | "I want diederik out of the way but quickly reachable" — universal expectation for a tool you use daily | M | NativePHP `MenuBar::create()`. Click opens a small detached window pointing at a `/tray` route. Use 16×16 / 22×22 template PNG (auto-adapts to light/dark on macOS). Right-click on Windows/Linux for context menu. |
| **OS notifications** (subscription drift, doctor warnings, backup success/failure, import complete) | Native notifications signal "this app integrates with my OS"; v1.0 already produces these events as `system_alerts` rows | M | NativePHP `Notification::title(...)->body(...)->show()`. Pipe `system_alerts` writes through a `NotificationDispatcher` that decides "show OS notification vs in-app banner vs both" based on severity. |
| **Dock badge (macOS) / Windows taskbar badge** for unread alerts / pending review-queue items | Standard in Slack, Discord, Mail. Users expect it. | S | NativePHP `App::badge(count: $n)`. Drive from `system_alerts.unread_count + chain_review_queue.pending_count`. On Linux Unity launcher only — degrade gracefully elsewhere. |
| **Open at login / launch on startup** (toggle in Settings) | Daily-use tool — user wants it running without thinking. **Default off** (anti-pattern: auto-enabling without consent) | S | NativePHP `App::openAtLogin(true)`. Persists at OS level (LaunchAgent on macOS, Run registry on Windows, autostart `.desktop` on Linux). User-toggleable in app preferences. |
| **OS-following light/dark mode** | Every modern desktop app does this; Linear / Notion / Slack set the bar | S | NativePHP exposes the OS theme via JS bridge; Livewire/Alpine reads it and toggles Tailwind's `dark:` variant. Override toggle in Settings (System / Light / Dark). |
| **"Reveal in Finder / Explorer"** action on backup files, exported CSVs, log files | Standard affordance — clicking should jump to the file in the OS file manager | S | NativePHP `Shell::showItemInFolder(path: ...)`. Wire from the Backup list, Export list, and log tailer in Dev Mode. |
| **Single-window posture by default** | A finance dashboard is a single thing, not a workspace — Linear/Notion are single-window | S | NativePHP `Window::singleton()` — clicking the app icon focuses the existing window rather than opening a second. Allow opt-in to "open new window" via app menu (rare). |
| **Quit confirmation if a background job is running** | "Don't lose my import" — table stakes for any tool that does work in the background | M | Hook NativePHP's `before-quit` event; check Horizon for active jobs; show modal "Backup in progress — quit anyway?". |
| **About dialog** with version, build hash, license, link to repo | Standard "Help → About" — expected | S | Static Livewire page; pull version from `composer.json`, build hash from a build-time-stamped env var. |

#### Differentiators (Desktop Shell)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **File-association handlers** — double-click a `.eml` or `.csv` to import directly into diederik | Removes a 6-click workflow; matches how Mail.app, Apple Wallet handle file types | M | NativePHP's `FileHandlers` mechanism (`app/NativePHP/FileHandlers.php`). Register MIME types `message/rfc822` (`.eml`), `application/mbox` (`.mbox`), `text/csv`. On open: route into the Ingestion module's existing drop-in handler. **Watch for v1.0 `chmod 600` invariant** — files opened via Finder/Explorer are owned by the user, not the app sandbox; no rewriting permissions. |
| **Drag-and-drop import** anywhere on the app window (dropping `.eml`, `.csv`, `.mbox`, `.xlsx`) | Calmer than a wizard for power users; matches Linear / Notion drag-and-drop UX | M | NativePHP file-drop events forwarded to a single `BulkImportDispatcher` that routes by extension. Show a soft overlay on drag-enter. |
| **Menu-bar (tray) compact summary view** — "this month at a glance" without opening the main window | "Calm tool" goal — peek at finances without context-switch; matches Tinkerwell / Itsycal / Fantastical menubar UX | M | Separate route `/menubar` rendered as a small 360×500 Livewire view showing month totals + next 3 charges. NativePHP `MenuBar::create()->width(360)->height(500)->url('/menubar')`. |
| **Global hotkey to open / focus diederik** (e.g. ⌘⇧D, configurable) | Raycast pattern — opens by reflex | M | NativePHP `GlobalShortcut::register('CmdOrCtrl+Shift+D', fn () => App::focus())`. **Default unset** — users opt in (default-bound shortcuts collide with everyone else's app). |
| **In-app command palette** (⌘K / Ctrl-K) — fuzzy search across routes, accounts, transactions, settings | Linear / Raycast pattern. Cuts navigation depth from ~3 clicks to 1 keystroke. Particularly valuable for the Dev Mode artisan picker | M | Livewire component bound to `/command-palette` modal. Index: routes (registered via attribute on controllers), account names, recent merchants, top 50 settings. Fuzzy match via simple substring + token-overlap (no need for a library). |
| **Recently-opened files list** in the File menu (last 5 `.eml`/`.csv` imports) | "Where did I import that from again?" — standard in any desktop app that opens files | S | Persisted to a small `recent_files` user-scoped table. macOS NSRecentDocuments integration via NativePHP if exposed; otherwise just an app-menu submenu. |
| **Inertia-feel internal navigation** — no full-page reloads when clicking between views | Livewire 4 + `wire:navigate` already gives this; surfaces a "this is a real app" feel | S | Already in v1.0 stack — just make sure desktop wrapper isn't doing `history.go(0)` on tray clicks. |
| **App-menu profile-quick-switch** in the user-account submenu (multi-user only) | Slack-style "Switch workspace" in the menu — keyboard discoverable; ⌘⌥1 / ⌘⌥2 to jump | S | Renders dynamically from `User::all()` for the current machine. Disabled when only one user exists. |

#### Anti-Features (Desktop Shell)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Auto-launching at install** (a.k.a. "Open at Login" defaulting to ON) | "It's a daily-use tool, why wouldn't you?" | Universally hated. macOS Big Sur+ shows a system notification for *every* auto-launching agent; users blame the app, not Apple. | Default OFF; surface the toggle prominently in first-run + Settings. Tell the user *why* they might want it on. |
| **Background-only mode** (the app is *only* a tray icon, no main window) | "Make it like Itsycal" | Disorients new users; tray-only apps need a discoverability story (right-click? double-click?) that diederik doesn't need to invent | Main window + tray peek view. Tray is a shortcut, not a substitute. |
| **Custom non-native window chrome** (frameless window with HTML close button) | "Looks more designed" | Breaks OS conventions (no right-click in title bar; no Aero-snap on Windows; no fullscreen-from-traffic-light on macOS); accessibility regression | Native chrome with calm Tailwind interior. Linear / Notion both use native chrome. |
| **Notification spam** (notify on every transaction import, every classified merchant, every successful backup) | "More notifications = more engagement" | Daily-use tool gets muted within a week; users disable OS notifications globally, which then kills the legitimate drift alerts | Notify only on: drift threshold crossed, doctor probe failed, backup failed, import requires review, app updated. Everything else is in-app only. |
| **A separate "diederik agent" background process** that runs even when the app is closed | "So scheduled imports keep working" | v1.0 already solved this with `launchd` plists owned by the user. Wrapping it inside an Electron agent doubles RAM (~150MB) for no value, and Electron + Squirrel auto-update on Windows is shaky enough without a hidden background process | Keep `launchd` / equivalent at the OS layer. Document it in Settings ("Background tasks managed by launchd — open Log").  |
| **Forcing the user into the tray view when minimised** | "Save dock space" | Confuses users who expect Cmd-H / minimise to mean "stay on the dock" | Standard minimise behaviour; tray icon is additive, not a replacement. |
| **A "diederik://" URL scheme that's overly permissive** | "Beautiful deep links from emails into the app" | Deep links into a finance app from an arbitrary URL are a phishing/CSRF surface; v1.0 has no need for cross-app links | If implemented at all, scope to `diederik://import/<token>` where token is a one-time-use, pre-authenticated handle minted from inside the app, with explicit confirmation prompt. **Defer to v2.x — not needed for v2.0 ship.** |

---

### Area 2 — Multi-User UX

Activate the dormant `user_id` columns and `BelongsToUser` trait. Add real login/signup. Handle the "two people on one machine" scenario the schema was always designed for.

#### Table Stakes (Multi-User)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **Sign-up form** — email + display name + password (≥10 chars, no other rules) | Universal | S | Laravel 13's auth scaffolding via Livewire starter kit; replace mail-driver password-reset bits. **Email is identifier only — no SMTP verification.** No "verify your email" step (we don't have an SMTP). |
| **Login form** — email + password + "remember me" | Universal | S | Standard `auth()->attempt()` with Livewire form. Remember-me sets a 30-day cookie scoped to `127.0.0.1` only. |
| **Logout button** — visible top-right in the user menu | Universal — missing = "where do I sign out?" | S | Standard. Flux UI dropdown in the topbar. |
| **"Currently logged in as: X"** indicator | Universal in multi-user apps; without it users get confused about whose data they're looking at | S | Topbar avatar + name. Disambiguates partner-shared dashboards. |
| **Password change** (current + new + confirm) | Universal | S | `password.update` controller; rehash with Laravel's default hasher (bcrypt 12). |
| **Password reset** — the hard one, see deep dive below | Universal expectation, no SMTP means we reinvent | M | **Printed recovery codes** at signup + filesystem-fallback reset via `php artisan diederik:reset-password`. See Deep Dive 1. |
| **Per-user data scoping enforced at every read** | v1.0 already enforces via `BelongsToUser` + arch test; UI layer just needs to use scoped queries | S | Already in place at the model layer (`UserIdColumnArchTest` invariant). UI layer: ensure no Livewire component leaks data via implicit query without `whereBelongsTo($this->user())`. |
| **404 (not 403) on cross-user URL guessing** | "Don't reveal that resource X exists for user Y" — security table stake | S | Single global resolver: `Modules\Core\Http\Concerns\ResolvesOwnedModel` finds-or-404 via `where('user_id', $auth->id())->findOrFail($id)`. No 403s — they leak existence. |
| **Session expiry + idle logout** | Daily-use tool — a partner's session left open on a shared machine is the threat model | S | Laravel default 120 minutes; tighten to 60 for a finance app. JS-side: warn at 55 min, force logout at 60 with state restoration on re-login. |
| **First-user-is-admin** convention | Someone has to invite the second user; first-user-is-admin is the simplest answer | S | `users.is_admin` boolean; auto-set on first user creation, never changeable from UI (only via `diederik:promote-admin` artisan). |
| **Invite a second user** flow | The partner-sharing use case explicitly in PROJECT.md | M | See Deep Dive 2 (no-SMTP invite). |
| **Per-user OAuth secret isolation** | v1.0's `OAuthSecretsRepository` is keyed by user_id; UI must respect this | S | Already enforced by `PLT-03` BoundaryArchTest. UI just needs to scope token lists. **Don't show user A's Gmail connect status on user B's dashboard.** |
| **Per-user "remember last view" preferences** | `default_currency_view`, default account filter, dashboard layout — all already user-scoped in v1.0 | S | Existing `user_preferences` table. Just make sure new v2.0 UIs respect it. |

#### Differentiators (Multi-User)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **Profile selector on login screen** (when ≥2 users exist on the machine) | Slack-workspace-list pattern — picks a user and pre-fills the email field | S | If ≥2 `users` rows exist, login screen shows avatars + names instead of an empty email field. Click an avatar → email pre-filled → enter password. Faster than typing the email every time. |
| **App-menu quick-switch** (⌘⌥1 / ⌘⌥2 between users) | Power-user feature borrowed from Slack | S | Requires both users to have an active session — practically: persists per-user session tokens in a `local_sessions` table, scoped to the machine. Logging out one doesn't log out the other. |
| **Partner read/write modes per dataset** (e.g. "partner sees joint expenses but not my salary") | Firefly III is still building this in 2026 — clear gap | **L** | Out of scope for v2.0 — flag for v2.x. Adds a `data_visibility` column on accounts/transactions: `private` / `shared` / `joint`. Schema-only stub in v2.0; UI deferred. |
| **Per-user theming** (accent colour or avatar colour for the topbar) | Visually distinguishes which user you're in — Slack does this; reduces "whose data am I looking at" cognitive load | S | One `users.accent_color` enum (6 options); applied as a CSS variable on `<body data-user-accent="...">`. Cheap to add, high signal. |
| **First-run "is this a single-user or shared install?"** branch in the wizard | Skips the second-user invite UI entirely for solo users | S | One radio button in first-run; if "Just me" selected, hide the invite-related Settings panes. Toggleable later in Settings if the user changes their mind. |
| **Auto-logout on system sleep / lid close** (macOS) | Banks do this; finance dashboards should too | S | NativePHP exposes power events. Configurable in Settings (default ON for multi-user, OFF for single-user). |
| **"Switch user" without quitting** via menu bar / tray | Slack-style; faster than logout-then-login | S | Same `local_sessions` table — tray icon shows current user + "Switch to..." submenu. |

#### Anti-Features (Multi-User)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Email-based password reset** | "It's how everyone does it" | We have no SMTP; running one locally is fragile (DKIM/SPF/local-port-25); using a third-party (SendGrid, Mailgun, Postmark) violates the local-only privacy stance from v1.0 | Printed recovery codes + filesystem-fallback artisan reset. See Deep Dive 1. |
| **2FA / TOTP** | "Security best practice" | Local-only app, on a single machine, behind the user's OS login. Adds login friction without a credible threat model. Bank-grade auth on a localhost dashboard is theatre. | Out of scope for v2.0. Re-evaluate if remote access is ever added (which is also out of scope). |
| **Social login (Sign in with Google / Apple / Microsoft)** | "More convenient" | Pushes auth identity off-device — violates local-only. Requires OAuth client registration with each provider. The user already has OAuth tokens for Gmail/Graph, but those are for *reading mail*, not for identity. | Email + password. No exceptions. |
| **Full RBAC / roles / permissions matrix** | "Multi-user means roles" | Two-person household doesn't need a permissions matrix. Building one costs weeks; using one costs minutes per change. | `is_admin` boolean. That's it for v2.0. |
| **Per-user encryption keys with key escrow** | "Protect user A's data from user B if the SQLite file leaks" | Threat model: shared household, shared machine, shared backup. Per-user encryption breaks shared accounts ("joint household expenses") which was the whole point of multi-user. | Filesystem-level encryption (FileVault / BitLocker) is the right layer. App-level: trust the OS user account boundary. |
| **A "guest mode" / read-only viewer** | "Show my mom my dashboard without logging in" | First step toward an authless attack surface | If needed: a one-time-use share link with expiry (defer to v2.x). |
| **Username instead of email** | "Email feels formal" | Confuses users when they later want password reset ("what was my username again?"); locks out future SMTP integration if that ever changes | Email-as-identifier. Display name is separate, freely editable. |
| **Auto-create a "demo" user with seeded data** | "Better first impression" | Pollutes the dashboard with fake data; users have to figure out how to delete the demo. v1.0 already validated that "starting empty" works for technical users | First-run "Start fresh" button starts genuinely empty. Sample-data option in Dev Mode only, not in normal flow. |

---

### Area 3 — In-App Developer Mode UI

The user-as-developer console. Visible only when `Settings → Developer Mode` toggle is ON. Hidden completely for partner users.

#### Table Stakes (Dev Mode)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **Dev Mode toggle in Settings** | The on/off switch itself is table stakes. Every IDE / dev tool has one | S | Per-user preference: `users.dev_mode_enabled` boolean. Default OFF. Toggling reveals the "Developer" entry in the main nav. |
| **Live log tailer** (`storage/logs/laravel.log`) with filter (level + module) | Telescope / Tinkerwell have it; the user expects to see crashes inline | M | Server-Sent Events stream from `tail -f`-equivalent. Filter by `[ERROR]` / `[WARNING]` / module name. Auto-scroll toggle. Max 1000 visible lines, with "load more". |
| **`system_alerts` table viewer** with acknowledge / clear actions | v1.0 already has `SystemAlertsBanner` as the user-facing surface; Dev Mode needs the full table view + history | S | Standard Livewire data table over the `system_alerts` model. Bulk-acknowledge action. Group by alert type. |
| **Queue inspector** — currently running jobs / queues / failed jobs / retry | v1.0 already has Horizon; embed its dashboard inside the diederik chrome rather than redirecting to `/horizon` | M | Two options: (a) embed Horizon's UI via an iframe inside a diederik tab (preserves the calm aesthetic); (b) reimplement on top of the Horizon API (more work, calmer UI). **Recommend (a) for v2.0**: less code, less drift risk. Style sidebar/topbar to match diederik. |
| **`diederik:doctor` probe runner with result display** | v1.0 ships `diederik:doctor` as a CLI; Dev Mode UI shows the same probes with green/amber/red badges | S | Wraps the existing `Diederik\Doctor\ProbeRunner` service; renders a checklist. "Run all" + per-probe "Run". |
| **Env-snapshot viewer** — PHP version / Laravel version / SQLite version + PRAGMA settings / loaded extensions / module list / queue worker status | "What's the actual state of this install?" — universal dev-tool feature | S | Read-only diagnostic page. Single Livewire component pulling from `phpversion()`, `DB::select("PRAGMA ...")`, `php -m`-equivalent, `composer.lock`. |
| **Database schema viewer** — table list + column list per table | Beekeeper / TablePlus equivalent. Read-only — no DDL from the UI | M | Iterates over the SQLite schema via `sqlite_master`. Shows columns, indexes, foreign keys, row counts. Click a table → row browser. |
| **Read-only query runner** — accepts `SELECT ...` only | TablePlus / Beekeeper / Postman query-tab equivalent. Hard-blocks non-SELECT statements | M | Server-side AST-check: reject if not exactly one `SELECT` statement, no `WITH ... INSERT`, no `pragma_writable`. Results in a paginated grid. **Never** echo raw query — render via parameter binding to the grid. |
| **Whitelisted artisan command runner** with live stdout/stderr stream | "Run `php artisan diederik:backup` without leaving the app" — entire reason Dev Mode exists | **L** | See Deep Dive 3. Whitelist of safe commands; form-driven argument input; SSE streaming output. |
| **Command history** — last 20 artisan invocations with re-run | Raycast pattern. Crucial when iterating on an import: re-run the same command without retyping args | S | Per-user table. Click a row → restores the form with the same args + opens the run panel. |
| **Cancel running command** button | Universal — long-running imports need a kill switch | M | Send SIGTERM to the subprocess via the `Process` facade's `signal()` (Symfony Process). Streaming pipe closes; UI shows "cancelled". |
| **Failed-jobs viewer + retry / forget** | v1.0 has `diederik:failed-jobs prune` as CLI; Dev Mode mirrors it | S | Eloquent over `failed_jobs` table; per-row Retry + Forget actions; bulk Prune. |
| **Notification when a backup / restore / long-running command finishes** | "I clicked Run Backup 5 minutes ago, did it work?" | S | OS notification on command-exit-zero (success) or non-zero (failure). Same notification pipeline as Area 1. |

#### Differentiators (Dev Mode)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **Command palette integration** — ⌘K → type "doctor" → run doctor without navigating | Raycast pattern. Massive ergonomic win. | S | Add artisan commands as a third source in the global ⌘K command palette (alongside routes and accounts). Filter by `dev_mode_enabled`. |
| **Telescope-style query log overlay** — "show me every SQL query for this page" | Laravel Telescope flagship feature. Critical for debugging the v1.0 chain resolver in production. | M | Optional toggle. When ON: every SQL query for the user's session is captured to `dev_query_log` (rolling window of last 500). UI: a slide-over panel from the right. **Disable on partner users absolutely** — leaks all data. |
| **Visual state-machine inspector** — for the 4 state machines from v1.0 (chain resolver, recurring detection, drift alerts, forecast scenarios) | "Show me the state of this transaction's chain-resolution lifecycle" — turns invisible state changes into a visual timeline | M | Read-only timeline view per record. Pulls from the `state_transitions` audit table (which v1.0 wrote per phase). |
| **Live `diederik:doctor` dashboard tile** on the home screen (Dev Mode only) | At-a-glance "is everything healthy?" — saves navigating to Dev panel | S | Dashboard card showing aggregate doctor health (1 red → red; all green → green badge). Click → Dev Mode doctor page. |
| **One-click "Open project in editor"** (VS Code / PhpStorm) | Tinkerwell pattern — shells out to `code .` or `phpstorm .` | S | NativePHP `Shell::openPath(...)`. Reads `EDITOR` env or a Setting. Single button on the Dev Mode home. |
| **Test runner integration** — "Run Pest test for this file" | Tinkerwell / IDE pattern; if dogfooding the app while building it, this is high-leverage | M | Streams `vendor/bin/pest <filter>` output to the same pane as artisan runner. **Dev Mode + `APP_ENV=local` only.** |
| **"Generate a debug bundle"** — zips `storage/logs/`, `composer.lock`, doctor output, SQLite PRAGMA dump, `system_alerts` table, and saves to Desktop | Crash-report-as-file-download pattern. Useful for partner users to send to the developer without invoking Sentry. | M | Single button: produces `diederik-debug-2026-05-19-1432.zip` + reveals in Finder. Strips secrets (matches `OAuthSecretsRepository` paths). |
| **Sample-data seed runner** ("Start with seeded test data") | First-run developer onboarding — gives a populated dashboard to explore | S | Wraps `Modules\Core\Database\Seeders\SampleDataSeeder` (existing). Idempotent. Confirms before running on a non-empty DB. |

#### Anti-Features (Dev Mode)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Free-text artisan input** ("just let me type any artisan command") | "I'm a developer, I know what I'm doing" | One typo → `migrate:fresh` wipes the database. UI is not the right surface for arbitrary commands; the terminal is. | Whitelisted commands only; for arbitrary commands, the user opens Terminal.app from the "Open project in editor" affordance. |
| **Destructive artisan commands without a confirm modal** | "I trust myself" | The user *is* the threat model — fat-finger Friday afternoon, partner accidentally clicks the wrong row. Cost of a confirm modal: 1 second. Cost of restoring from backup: 30 min. | **Triple gating** for destructive commands: (a) Dev Mode ON, (b) Advanced Toggle ON, (c) Confirm modal with typed app name. See Deep Dive 3. |
| **Read/write SQL query runner** | "Sometimes I need to UPDATE a row" | The state machines in v1.0 are *the* mutators of `state` columns; trigger-enforced at DB layer. A read/write panel bypasses every invariant. | Read-only SELECTs only. For write fixes: write a proper artisan command (Dev Mode runs it), code-review-pathed via git. |
| **`tail -f` of `storage/logs/laravel.log` exposed without log-rotation awareness** | "Just stream the file" | 5 GB log file → 5 GB memory; default Laravel does not rotate; v1.0 writes a lot to logs | Tail the last N MB; warn on file > 100 MB; surface a "rotate now" action that invokes `php artisan log:clear` (which itself is a whitelisted destructive command). |
| **Telescope embedded with default settings** | "Just `composer require laravel/telescope`" | Telescope's default install captures all requests, all queries, all jobs — *including the secrets-passing OAuth dance*. Storage explodes; secrets leak to a dev table. | If Telescope is adopted, pin it to local-only routing, sample at 1%, and add the secrets filter list. Otherwise, build the slimmer custom panels described above. |
| **Telemetry pipeline that runs in Dev Mode** ("send my dev usage to improve diederik") | "Help the project grow" | Mixes diagnostic data with usage telemetry; users can't reason about which is which | If Dev Mode adds any telemetry, it MUST be the same opt-in toggle as Area 5's general telemetry — not a separate dev-mode tap. |
| **Auto-enabling Dev Mode when `APP_ENV=local`** | "Convenience for developers" | Partner users running a local install accidentally see (and can run) the artisan panel | Dev Mode is a per-user opt-in, regardless of APP_ENV. The first-run wizard asks "Are you the developer of this app?" — defaults to NO. |

---

### Area 4 — First-Run & Public-Release UX

The "I just installed this and double-clicked" experience. New for v2.0 — v1.0 never had it because the user ran `php artisan` to set up.

#### Table Stakes (First-Run)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **First-launch wizard** (3–5 steps, progress indicator) | Monarch / Linear / Notion pattern; users expect to be guided. Calm copy, not a sales pitch | M | Steps: (1) Welcome + brand splash, (2) Create first user (email/name/password + recovery code download), (3) "Just me" vs "Me + partner" choice, (4) Data directory pick (defaults to OS user-data dir), (5) "Start fresh" vs "Restore from backup" vs "Import bank exports now". |
| **Data-directory picker** (with sensible default) | Standard for any app that stores meaningful data; users expect to be able to relocate it | M | NativePHP file-picker. Default: macOS `~/Library/Application Support/diederik/`, Windows `%APPDATA%\diederik\`, Linux `~/.local/share/diederik/`. Stores SQLite, backups, OAuth secrets directory. Restart required if changed after initial setup. |
| **"Restore from backup" path** in first-run | v1.0 produces `.sqlite` backup files; first-run on a new machine should be able to consume them | M | File picker → calls `php artisan db:restore --confirm --force-maintenance` under the hood. Validates the file before destroying any state. |
| **"Start fresh" path** in first-run | Standard counterpart to restore | S | Creates DB, runs migrations, doesn't seed. User lands on an empty dashboard with an inline "Import your first bank export →" CTA. |
| **In-app version display** (Settings → About) | Universal | S | From `composer.json` + a build-time-stamped build hash; displayed as `v2.0.0 (build a1b2c3d)`. |
| **"Check for updates" button + auto-update install flow** | Universal in 2026 — apps that don't update get pinned to dying versions | **L** | Electron-updater wired to GitHub Releases (see Deep Dive 4). Manual "Check now" button + automatic background check daily. On update available: notification + "Restart to install" button. |
| **"Send feedback" link** in the Help menu | Universal | S | Opens the user's mail client with a pre-filled mailto pointing at the project email, or — better — a `/feedback` route with a form that produces a `mailto:` or GitHub-issues link (see Differentiators). |
| **Link to docs / GitHub issues / changelog** in Help menu | Universal | S | Static menu items. |
| **License + privacy summary** displayed once on first-run | Hippocratic License 3.0 + "Your data never leaves this machine" — both are user-facing decisions, not legal footnotes | S | Standalone screen with "I understand" before completing setup. Must be re-shown after major version bumps if the license text changes (rare). |
| **"Skip wizard"** option for technical users who want to set up via artisan | Power user respect | S | Bottom-of-wizard link "I'll set this up from the CLI" — closes wizard and shows the empty dashboard, leaving the user to run `php artisan` themselves. Idempotent: re-opening the app re-shows the wizard until the user creates a first row. |

#### Differentiators (First-Run)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **Inline onboarding hints** in the empty dashboard (Linear-style "Get started" cards) | Calmer than a 7-step wizard. Cards: "Import your first ASN CSV", "Connect Gmail for receipts", "Add ICS Cards PDF". Dismissable individually | M | Each card maps to a v1.0 capability. Dismissed cards live in `user_preferences.dismissed_onboarding_cards`. Reset from Help menu. |
| **Real-time wizard verification** ("Your data directory is writable ✓; SQLite 3.45 detected ✓; PHP 8.5 detected ✓") | Reduces "set it up, then it crashes" support load. The user's `diederik:doctor` runs *during* the wizard | S | Reuses Doctor probes — they already exist. Show inline next to the directory picker. |
| **Onboarding sample-data preview** ("Want to see what this looks like with data?") | One-click toggle between empty and seeded states during first-run | S | Wraps `SampleDataSeeder`; on Wizard-Complete, clear the seeded data if the user clicked "OK that was helpful, clear it". |
| **OAuth connect from inside the wizard** (Gmail + Microsoft Graph) | Frontloads the v1.0 Phase 6 OAuth dance; saves users hunting for it later | M | Reuses the existing OAuth controllers; just exposes them as wizard steps. Skip button always available. |
| **Auto-update with release notes preview** | Show what's new before installing | M | electron-updater fetches the release notes from GitHub Releases; show in a modal before installing. Standard pattern in Linear / Slack. |
| **Soft re-onboarding after major versions** (a one-screen "what's new in v2.x") | Helps users discover new features without being intrusive | S | Show a single splash screen on first launch after a major version bump; never again. Dismissible. |
| **Onboarding checklist persistent in sidebar** until 80% complete | Slack pattern; reduces "I forgot to connect Gmail" support | S | Auto-detected milestones: "Imported first transaction", "Categorised 10 transactions", "Connected one inbox", "Saw first recurring suggestion". |

#### Anti-Features (First-Run)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Mandatory cloud-account creation on first launch** | "Easier user identity tracking" | Antithetical to local-only. There's no cloud to create accounts in. | Email-as-identifier, all-local. |
| **Dense multi-screen welcome carousel** with "Continue" between each | "More content = more perceived value" | First-run friction kills retention. Linear / Notion have 1–3 screens, not 7 | Maximum 4 wizard screens. Cut anything that doesn't *block* using the app. |
| **Auto-import from previous installation** without explicit consent | "Migrate v1.0 data automatically" | v1.0 SQLite paths are different from v2.0 paths; auto-detecting and copying without consent risks data corruption or a partner discovering a colleague's data | First-run *offers* to restore from backup; never auto-detects without asking. |
| **Telemetry opt-in pre-checked** | "Most users won't change defaults, more telemetry = better product" | Dark pattern; permanently damages trust for the privacy-positioned project | Telemetry off by default; opt-in on a *separate screen*, not bundled into the welcome flow. See Area 5. |
| **Aggressive feature discovery tooltips** that follow the cursor | "Educate users about features" | Annoying; users disable tooltips globally | Calm, dismissable cards. One tooltip at a time, only on first feature use. |
| **A "rate us on the App Store" prompt** | "Distribution best practice" | We're not on any store. Even if we were, prompting on first run is the most-hated UX pattern of the last decade | Don't. Help → "Star us on GitHub" link, that's it. |
| **Sample data that survives "Start fresh"** | "Don't lose helpful demo content" | Confuses the user about what's real and what's example | "Start fresh" means fresh. Sample data only via explicit Dev Mode action. |

---

### Area 5 — Beta Cycle Support

The privacy-sensitive plumbing for getting v2.0 from "1 user (the developer)" to "3–4 users (developer + partner + 1–2 beta testers)" without leaking financial data or annoying anyone.

#### Table Stakes (Beta)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **"Report a problem" link** in Help menu | Universal | S | Opens GitHub Issues template OR a `/feedback` form that drops to GitHub. Pre-fills version, OS, build hash. |
| **In-app build/version display** | Beta testers need to tell you what version they're on | S | Already in About dialog (Area 4). |
| **Crash recovery on next launch** ("It looks like diederik didn't shut down cleanly. Restoring last session…") | Universal in any Electron app | M | NativePHP can capture renderer crashes; on next launch, surface a "Reopen last view" affordance. |
| **A way to roll back an update** if the new version breaks | Beta testers will hit broken builds; the answer can't be "wait for v2.0.1" | M | electron-updater doesn't natively support rollback. Pragmatic answer: keep the previous build's `.dmg`/`.exe` downloadable from GitHub Releases; document the manual rollback in `CONTRIBUTING.md`. Auto-rollback is out of scope for v2.0. |
| **Changelog / What's New** view | Beta testers want to know what they're testing | S | Static Markdown rendered in Help → What's New. Auto-shown on first launch after update. |
| **Privacy disclosure in-app** explaining what does and doesn't leave the machine | Trust requirement for a privacy-first product | S | Settings → Privacy. Plain language: "Diederik never sends your financial data anywhere. Optional crash reports never include transaction data." |
| **Clear "Where is my data stored?"** location surfaced in Settings + Help | Beta users need to back up before reinstalling | S | Shows the actual SQLite path + an "Open in Finder/Explorer" button. |

#### Differentiators (Beta)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **In-app feedback form with screenshot capture** | Removes the "describe what you saw" friction; cribs from Linear's bug-report flow | M | Capture window via NativePHP's screenshot capability; user redacts before submitting; submits as a GitHub-Issue-shaped JSON or as a mailto. **Default screenshot OFF; opt-in per submission.** |
| **"Generate a debug bundle"** (also a Dev Mode feature) | Beta testers can produce a self-contained zip the developer can inspect, secrets stripped | M | Shared with Area 3. Differentiator for beta: the bundle is the support artifact. |
| **Opt-in anonymous error reporting** via Sentry, with a tight allow-list of fields | Helps the developer find crashes without testing every flow by hand. Critical for shipping a Hippocratic-licensed public release | M | Sentry Electron SDK. **Off by default; opt-in on its own screen with explicit "What gets sent" preview.** Allowlist: stacktrace, route name, app version, OS version, Laravel version, anonymous install ID. Denylist: transaction data, user emails, file paths containing user names, OAuth tokens. Test with `before_send` hook + arch test. |
| **Beta opt-in channel** (Settings → Updates → "Receive pre-release builds") | Avoids accidentally pushing beta builds to the non-beta partner | S | electron-updater supports prerelease channels. UI: a toggle in Settings. Default OFF except for the original developer's install. |
| **Per-user feature flags** for risky changes | Lets you ship a new chain-resolver to your install while leaving partner on stable | M | A simple `feature_flags` table, per-user boolean. Read at runtime via a `Features::enabled('new_resolver', $user)` service. No external service needed. Out of scope for v2.0 ship, useful for v2.x. |

#### Anti-Features (Beta)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Default-on telemetry / usage analytics** | "Industry standard" | Antithetical to the privacy stance. Permanently damages trust. Plausibly violates Hippocratic License intent | **Hard no.** Telemetry is off by default, opt-in on its own dedicated Settings screen, with a "What gets sent" preview before enabling. |
| **Heatmap / replay tools** (Hotjar, FullStory, etc.) | "Understand user behaviour" | These vendors record DOM nodes including financial data; recording is on-device and exfiltrated; PII issues are unbounded | None. If user behaviour insight is needed, ask explicitly in feedback. |
| **Bundled analytics SDK** that's a transitive dep | "It came with our framework" | Often: PostHog, Mixpanel, Sentry-with-defaults — all phone home unless explicitly disabled | Audit `composer.lock` + `package.json` for known telemetry packages; arch test that fails the build if a banned package appears. |
| **Phone-home heartbeat** ("are users still using diederik?") | "License compliance" | License compliance for Hippocratic 3.0 is honor-based; there's no central server | None. |
| **Forced auto-update without consent** | "Security patch ASAP" | Users running a long import that you interrupt = lost work. Beta users especially hate this | electron-updater: download silently, *prompt* before installing. Allow "Install on next quit" option. |
| **Background crash reports that include database content** | "More data = better debugging" | Database = transaction data = catastrophic privacy breach | Stack trace + route name only. Sentry `before_send` hook strips body data; arch test enforces it never appears. |
| **A "Send us your usage" survey baked into the app** | "Direct user research" | The right way to research is async, opt-in, asked once with consent — not a re-appearing modal | Static "Help → Feedback" link, that's it. |

---

## Deep Dives

These are the questions where the answer isn't obvious from the table above and deserve a longer treatment.

### Deep Dive 1 — Password Reset Without SMTP

**The problem:** No SMTP server in a local-only app. Standard Laravel password reset relies on `Password::sendResetLink()` which mails a token. In a desktop context, there's nowhere to send it.

**Industry precedents:**

- **1Password** uses a printed recovery kit (PDF with Secret Key + email backup) + an email verification step. *Email is still required* for account recovery, which is acceptable for them (cloud product) but not for us.
- **Beekeeper Studio Team Workspace** uses a passwordless email magic-link for recovery. Requires SMTP — not viable for us.
- **Bitwarden / Vaultwarden** (the closer comparison: self-hosted, local-data-feels-local) requires SMTP for reset. Self-hosters universally run a local SMTP relay or use a third-party API for this. Friction is real.
- **Standard Notes** (self-hosted variant) ships with no built-in password reset for self-hosters; the docs explicitly say *"if you lose your password and don't have a recovery code, your data is unrecoverable"*. The user is given recovery codes at signup.
- **macOS Keychain** is the OS-level fallback in 1Password's threat model: if you have the device and the OS user account, you can recover.

**Recommendation for diederik:**

A two-rail recovery model:

**Rail 1: Recovery codes (printed at signup).**
- At signup (and via Settings → Account → "Generate new recovery codes"), the user gets 8 single-use codes shown in a printable layout.
- One-time-use; using one invalidates it; user can re-generate the full set.
- Stored hashed (bcrypt) in `user_recovery_codes`.
- On the login screen, "Forgot password" → "I have a recovery code" → enter code → set new password.
- **Complexity: S**. Standard pattern, Laravel-native.

**Rail 2: Filesystem-fallback via artisan command.**
- For "I have no recovery code and I lost my password": `php artisan diederik:reset-password --user=email@example.com`.
- Requires shell access — i.e. the user must own the OS user account.
- Generates a single-use reset token, prints it on the terminal, valid for 15 minutes.
- User pastes it into the login screen → sets new password.
- The threat model: if you have the SQLite file *and* shell access *and* you're the owner of the OS user account, you can reset the password. This is *equivalent to BitLocker / FileVault security* — we're trusting the OS user boundary.
- **Complexity: S**. ~30 lines of Artisan command.

**Hard no on:**
- Local SMTP relay (DKIM/SPF hell on a residential IP).
- Third-party transactional email API (Postmark/Sendgrid/Mailgun — phones home, violates local-only).
- Self-served reset link via clicking from an OS notification (would require a `diederik://` deep link with a privileged reset path — large security surface for a question that printed codes already solve).

**Invite-a-second-user flow follows the same pattern:** when User A invites User B, the app generates a one-time invite code; User A sends it to User B *via whatever channel they prefer* (Signal, in person, written down). User B enters the code on first launch. No SMTP needed.

### Deep Dive 2 — Multi-User Invite Flow (No SMTP)

Mirror of Deep Dive 1:

1. Admin (first user) clicks "Invite a user" in Settings → Users.
2. App generates a one-time invite code (16 random alphanumeric, base32-friendly) + a QR for it.
3. User A communicates the code to User B out-of-band (phone, paper, Signal).
4. User B, on the same machine, clicks "Have an invite code?" on the login screen, enters the code, sets up their account.
5. Invite codes expire after 24 hours and are single-use.

This is identical to how Slack / Discord / 1Password handle out-of-band invites, just without the email channel.

**Complexity: M.** Small invites table + a screen on first run.

### Deep Dive 3 — Whitelisted Artisan Command Runner

The hardest UX-meets-security tradeoff in v2.0. Get it wrong and the developer's partner runs `migrate:fresh` from a confused-deputy modal.

**Architecture:**

1. A `DeveloperCommandRegistry` service holds the whitelist as PHP code (not a config file — arch test enforces it).
2. Each entry is a typed value object: `{ name, description, level, arguments, options, gating }`.
3. `level` is one of: `safe` (db:backup, doctor, failed-jobs prune), `risky` (queue:flush, log:clear), `destructive` (db:restore, migrate:fresh, telescope:clear).
4. `gating` declares: Dev Mode required for `safe`; Dev Mode + Advanced Toggle for `risky`; Dev Mode + Advanced Toggle + Confirm Modal + typed app name ("type 'diederik' to confirm") for `destructive`.

**Execution:**

1. UI submits a `RunCommand` Livewire event.
2. Server validates: gating, arg types, user is admin (multi-user) or owner (single-user).
3. Spawns the command via Symfony's `Process::start()` with a non-interactive STDIN and a captured STDOUT/STDERR.
4. Streams output via SSE to the requesting Livewire component.
5. On exit: records run + exit code in `developer_command_runs` audit table (per-user, immutable).

**Anti-patterns explicitly prevented:**

- No free-text artisan input. Whitelist only.
- No `bash` execution. PHP `Process` only, with a fixed `php artisan` prefix and validated subcommand.
- Destructive commands can never run without all three gates simultaneously.
- Audit log is append-only (DB trigger blocks UPDATE/DELETE on `developer_command_runs`).

**Complexity: L.** This is the largest single piece of v2.0's Dev Mode.

### Deep Dive 4 — Auto-Update + Code Signing

**The chain:**

1. Tag pushed (e.g. `v2.0.0`) → GitHub Actions matrix builds for macOS / Windows / Linux.
2. Build artifacts: `diederik-2.0.0.dmg` (macOS, notarized), `diederik-2.0.0.exe` (Windows, EV-signed), `diederik-2.0.0.AppImage` + `diederik_2.0.0_amd64.deb` (Linux, GPG-signed).
3. electron-builder publishes the artifacts + `latest-mac.yml` / `latest.yml` / `latest-linux.yml` to GitHub Releases.
4. Running diederik installs check daily (configurable) by reading `latest-*.yml` from the release feed.
5. On new version: download in background, prompt "Restart to install".

**Code-signing requirements (table stakes for distribution outside the App Store):**

- **macOS:** Apple Developer ID Application + Developer ID Installer certificates. App must be notarized via `xcrun notarytool`. Without notarization, Gatekeeper blocks the app on first launch with a security warning.
- **Windows:** EV Code Signing certificate (Extended Validation). Standard (non-EV) certs trigger SmartScreen warnings until enough installs accumulate "reputation". EV bypasses this immediately.
- **Linux:** Optional — GPG-signed `.deb` is standard; AppImage doesn't require signing but benefits from a GPG signature file alongside.

**Secrets management (GitHub Actions secrets):**

- `APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID` (for notarization).
- `CSC_LINK` (base64-encoded `.p12` for Apple), `CSC_KEY_PASSWORD`.
- `WIN_CSC_LINK`, `WIN_CSC_KEY_PASSWORD` (EV cert).
- `GH_TOKEN` (for publishing to releases).

**Complexity: L.** The CI pipeline is straightforward; the certificates and notarization steps are 2–3 days of integration work.

### Deep Dive 5 — Telemetry Stance

**The principled answer:** None by default. Opt-in only. On its own Settings screen, separated from update/diagnostic preferences. A "What gets sent" preview is shown before enabling.

**What the field does:**

| Product | Default state | What's sent |
|---------|---------------|-------------|
| Signal Desktop | None | Nothing; debug logs are opt-in per-submission |
| Standard Notes (self-hosted) | None | Nothing |
| Cryptee | None | Nothing |
| Bitwarden | Opt-out | Anonymous install count + version |
| Sentry Electron SDK | Opt-in via SDK init | Configurable; defaults can include user emails — must scrub |
| Linear desktop | Opt-out (analytics) + opt-out (Sentry crash reports) | Lots — they're cloud-first |
| TablePlus | Opt-out (crash reports) | Stack traces only |

**Recommendation for diederik:** Match Signal / Standard Notes / Cryptee — off by default. Opt-in for two things, separately:

1. **Anonymous error reporting** (Sentry Electron SDK). Allowlist: stacktrace, route name, app version, OS version, PHP version, anonymous install UUID. Denylist enforced via Sentry `before_send` hook *and* a Pest arch test.
2. **Beta-only "improve diederik" telemetry** — feature usage (which views are opened, how often, never which transactions). Only enable-able when the beta channel is also enabled. Default OFF even for beta users.

The opt-in screen shows: "Here's a sample payload of what gets sent" (literally renders a fake stacktrace + the metadata, so the user can see what they're agreeing to).

**Hard nos:**
- Default-on anything.
- Tracking pixels.
- Heatmaps / session replay.
- Anything that includes a transaction value, an account name, or an email.

---

## Feature Dependencies

```
[NativePHP shell init]
    └──underpins──> [Desktop window / tray / icon / notifications / file associations]
                        └──enables──> [Drag-and-drop import]
                        └──enables──> [Reveal in Finder / Explorer]
                        └──enables──> [OS notifications from system_alerts]

[Multi-user auth (login/signup/logout)]
    └──requires──> [Recovery codes table + UI]
    └──requires──> [v1.0's BelongsToUser invariant — already in place]
    └──enables──> [Profile selector]
    └──enables──> [Per-user OAuth secret isolation (already enforced)]
    └──enables──> [Invite-a-user flow]
    └──underpins──> [App-menu profile quick-switch]

[Password reset (recovery codes + artisan fallback)]
    └──depends-on──> [Multi-user auth]
    └──no-dependency-on──> [SMTP] (deliberate)

[Dev Mode toggle]
    └──underpins──> [Artisan command runner, log tailer, doctor probe, query runner, schema viewer]
    └──depends-on──> [Multi-user auth] (only the user with dev_mode_enabled sees it)
    └──depends-on──> [Whitelisted command registry]

[Whitelisted artisan command runner]
    └──depends-on──> [Symfony Process streaming + Livewire SSE]
    └──depends-on──> [Triple-gating UI for destructive commands]
    └──enables──> [Command history + re-run]
    └──cross-feature──> [Command palette includes artisan commands when Dev Mode ON]

[First-run wizard]
    └──depends-on──> [Multi-user auth (signup screen)]
    └──depends-on──> [Recovery code generation (download step)]
    └──depends-on──> [Data-directory picker (NativePHP file dialog)]
    └──optionally-uses──> [Sample-data seed runner (Dev Mode shared)]

[Auto-update + code signing]
    └──requires──> [CI/CD pipeline with Apple Developer ID + Windows EV cert]
    └──requires──> [GitHub Releases as the publish target]
    └──enables──> [In-app version + Check for updates]
    └──enables──> [Beta channel toggle]

[Opt-in error reporting (Sentry Electron)]
    └──depends-on──> [Settings → Privacy screen]
    └──depends-on──> [before_send scrub hook + arch test]
    └──depends-on──> [Allow/deny lists pinned in code, not config]

[OS notifications]
    └──depends-on──> [v1.0 system_alerts pipeline (already exists)]
    └──depends-on──> [NotificationDispatcher service that bridges in-app banner ↔ OS notification]

[Dock/taskbar badge]
    └──depends-on──> [system_alerts.unread_count + chain_review_queue.pending_count]
    └──depends-on──> [NativePHP App::badge() polling or event-driven]

[Embedded Horizon]
    └──depends-on──> [v1.0 Horizon install + loopback Redis]
    └──depends-on──> [Iframe-or-API integration choice]

[Command palette (⌘K)]
    └──underpins──> [Routes index]
    └──underpins──> [Accounts index]
    └──underpins──> [Artisan commands (Dev Mode only)]
    └──underpins──> [Settings index]
```

### Dependency Notes

- **NativePHP shell is the prerequisite for everything in Area 1 and most of Area 4.** Build the shell first, then layer in the file/notification/badge integrations.
- **Multi-user auth is the prerequisite for Areas 2, 3 (Dev Mode gating), 4 (signup in wizard), and 5 (per-user opt-in toggles).** Cannot ship Dev Mode without it (Dev Mode gating is per-user).
- **Recovery codes must ship in the same phase as multi-user auth.** Otherwise the first user has no recovery path.
- **Whitelisted command runner depends on the registry being in code with arch-test enforcement** — don't make it config-driven (an env var override is a security hole).
- **Auto-update depends on CI/CD + signing.** These three are intertwined; treat them as one phase.
- **Sentry / opt-in error reporting is independent of everything else.** Can ship in any phase; recommend last (beta phase) so the early phases never accidentally enable it.
- **First-run wizard depends on multi-user auth (signup) and data-directory picker (NativePHP shell).** Ship after both are done.
- **Command palette is independent enhancement.** Slot it wherever capacity allows.
- **Embedded Horizon depends on v1.0's Horizon being already loopback-bound.** It is — Phase 5 of v1.0 set this up. Just need to embed.

---

## MVP Definition

### Launch With (v2.0 — public release)

The minimum to ship a publicly-installable, multi-user desktop app:

**Area 1 — Desktop Shell**
- [ ] NativePHP wrapper around the existing Laravel app
- [ ] Code-signed installers for macOS (.dmg), Windows (.exe), Linux (.AppImage + .deb)
- [ ] Tray / menu bar icon with "Open diederik" + "Quit"
- [ ] App menu with standard items
- [ ] Native app icon (from `resources/brand/logo.svg`)
- [ ] OS notifications routed from `system_alerts`
- [ ] Dock / taskbar badge from `system_alerts.unread_count`
- [ ] "Open at login" toggle in Settings (default OFF)
- [ ] OS-following light/dark mode
- [ ] Single-window posture with focus-existing-window behaviour
- [ ] Quit confirmation when background work is active
- [ ] About dialog with version + build hash + Hippocratic 3.0 link

**Area 2 — Multi-User**
- [ ] Login + signup + logout + password change
- [ ] Recovery codes generated at signup + Settings → Account regenerate
- [ ] Filesystem-fallback `diederik:reset-password` artisan command
- [ ] Invite-a-user flow with one-time codes (out-of-band)
- [ ] First-user-is-admin convention
- [ ] Profile selector on login screen (when ≥2 users)
- [ ] Per-user data scoping enforced at every read (already in v1.0 — verify)
- [ ] 404-not-403 on cross-user URL guessing
- [ ] Session expiry + idle logout (60 min for finance app)
- [ ] User indicator (avatar + name) in topbar

**Area 3 — Developer Mode**
- [ ] Dev Mode toggle per user (default OFF)
- [ ] Live log tailer with level + module filter
- [ ] `system_alerts` table viewer with bulk acknowledge
- [ ] Embedded Horizon (iframe inside diederik chrome)
- [ ] `diederik:doctor` probe runner with green/amber/red badges
- [ ] Env-snapshot viewer (PHP / Laravel / SQLite / extensions / queue state)
- [ ] Database schema viewer (read-only)
- [ ] Read-only `SELECT`-only query runner
- [ ] Whitelisted artisan command runner with SSE streaming + cancel + history
- [ ] Triple-gated destructive command modal (Dev Mode + Advanced Toggle + typed-name confirm)
- [ ] Failed-jobs viewer + retry / forget

**Area 4 — First-Run + Onboarding + Auto-Update**
- [ ] 4-step first-launch wizard (welcome → first user → solo-or-shared → data dir + start fresh/restore)
- [ ] Data-directory picker with sensible defaults per OS
- [ ] Restore-from-backup path
- [ ] In-app version display
- [ ] Manual "Check for updates" button + daily background check
- [ ] electron-updater wired to GitHub Releases
- [ ] License + privacy summary on first-run
- [ ] "Send feedback" link in Help menu
- [ ] Changelog / What's New view

**Area 5 — Beta Cycle**
- [ ] "Report a problem" link in Help menu → GitHub Issues
- [ ] Crash-recovery prompt on next launch after non-clean shutdown
- [ ] Privacy disclosure in Settings → Privacy
- [ ] "Where is my data stored?" surfaced in Settings with reveal-in-finder
- [ ] Opt-in Sentry Electron error reporting with `before_send` scrub + arch test enforcing denylist
- [ ] Beta channel opt-in toggle in Settings → Updates (default OFF)

### Add After Validation (v2.x)

- [ ] File-association handlers (`.eml`, `.csv`, `.mbox`) — once base v2.0 is stable
- [ ] Drag-and-drop import overlay
- [ ] Menu-bar compact summary view ("this month at a glance" without opening main window)
- [ ] Global hotkey (configurable; default unset)
- [ ] In-app command palette (⌘K) with routes + accounts + artisan-commands sources
- [ ] App-menu profile quick-switch (⌘⌥1 / ⌘⌥2)
- [ ] Per-user accent colours for topbar
- [ ] OAuth connect from inside the wizard
- [ ] Onboarding checklist persistent in sidebar
- [ ] Inline onboarding hint cards on empty dashboard
- [ ] Auto-update with release-notes preview modal
- [ ] Telescope-style query log overlay (Dev Mode only)
- [ ] Visual state-machine inspector for v1.0's 4 state machines
- [ ] One-click "Open project in editor"
- [ ] Test runner integration (Dev Mode + APP_ENV=local only)
- [ ] In-app feedback form with opt-in screenshot
- [ ] "Generate debug bundle" zip with secrets stripped
- [ ] Sample-data seed runner (Dev Mode)

### Future Consideration (v3+)

- [ ] Partner-sharing read/write modes per dataset (`private` / `shared` / `joint`) — currently Firefly's gap, may become diederik's differentiator
- [ ] Per-user feature flags
- [ ] `diederik://` deep-link scheme (scoped, one-time-token-only)
- [ ] Beta-only "improve diederik" feature-usage telemetry (off-by-default-off-by-default)
- [ ] Auto-rollback of broken updates
- [ ] 2FA (only if remote access ever becomes a thing — which it shouldn't)

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| NativePHP shell + signed installers | **HIGHEST (core deliverable)** | HIGH | P1 |
| Multi-user auth (login/signup/logout) | **HIGHEST (core deliverable)** | MEDIUM | P1 |
| Recovery codes + artisan reset fallback | HIGH (no SMTP → critical) | LOW | P1 |
| Invite-a-user flow | HIGH (partner-sharing entire point) | LOW | P1 |
| Dev Mode toggle + log tailer + doctor + env-snapshot | HIGH (developer's daily workflow) | MEDIUM | P1 |
| Whitelisted artisan runner + triple-gating | **HIGHEST (Dev Mode headline)** | HIGH | P1 |
| Embedded Horizon (iframe approach) | HIGH (free reuse) | LOW | P1 |
| First-run wizard | HIGH (table stakes for installer) | MEDIUM | P1 |
| electron-updater + GitHub Releases | HIGH (security patches) | HIGH | P1 |
| Tray icon + dock badge + OS notifications | HIGH (calm-tool feel) | MEDIUM | P1 |
| Schema viewer + read-only query runner | MEDIUM (Dev Mode polish) | MEDIUM | P1 |
| Per-user data scoping verification | HIGH (security — already partly done) | LOW | P1 |
| Opt-in Sentry crash reporting | MEDIUM (helps beta cycle) | MEDIUM | P1 |
| Privacy disclosure + data-location reveal | HIGH (trust requirement) | LOW | P1 |
| Drag-and-drop import | MEDIUM (calmer than wizard) | MEDIUM | P2 |
| File-association handlers | MEDIUM (workflow polish) | MEDIUM | P2 |
| Menu-bar compact summary | MEDIUM (calm-tool win) | MEDIUM | P2 |
| Command palette (⌘K) | HIGH (Linear / Raycast feel) | MEDIUM | P2 |
| App-menu profile quick-switch | MEDIUM (Slack pattern) | LOW | P2 |
| Per-user accent colour | LOW (delightful) | LOW | P2 |
| Onboarding hint cards | MEDIUM (reduces support load) | MEDIUM | P2 |
| Auto-update release notes modal | MEDIUM (Linear pattern) | MEDIUM | P2 |
| Generate debug bundle | MEDIUM (beta workflow) | MEDIUM | P2 |
| In-app feedback form | MEDIUM (beta workflow) | MEDIUM | P2 |
| OAuth connect in wizard | MEDIUM (frontloads setup) | MEDIUM | P2 |
| Open project in editor | LOW (Dev Mode polish) | LOW | P3 |
| Test runner integration | LOW (Dev Mode polish) | MEDIUM | P3 |
| Telescope-style query overlay | MEDIUM (Dev Mode polish) | MEDIUM | P3 |
| Visual state-machine inspector | LOW (delightful) | MEDIUM | P3 |
| Global hotkey | LOW (power user) | LOW | P3 |
| Partner read/write modes | HIGH (true differentiator) | HIGH | P3 (v3) |
| Per-user feature flags | LOW (developer workflow) | LOW | P3 |
| 2FA | LOW (no threat model) | MEDIUM | P3 (never) |

**Priority key:**
- **P1**: Must have for v2.0 ship (public release)
- **P2**: Should have, v2.x — once P1 validated with beta cohort
- **P3**: Nice to have, v3+ — only if compelling demand emerges

---

## Competitor / Pattern Source Analysis

| Pattern | Source App | How They Do It | Our Approach |
|---------|------------|----------------|--------------|
| Command palette (⌘K) | Linear, Raycast, Notion | Fuzzy search across actions + entities; keyboard-first; aliases | Livewire modal; fuzzy match across routes + accounts + (Dev Mode) artisan commands |
| Tray menu peek view | Tinkerwell, Itsycal, Fantastical | Small detached window showing summary; click to open main app | NativePHP `MenuBar::create()` route at `/menubar`, 360×500 |
| Profile selector | Slack workspace switcher, 1Password account list | Avatars on login screen + topbar dropdown for active session switch | Avatars on login when ≥2 users; topbar dropdown for switch |
| Recovery codes (no email) | 1Password, Standard Notes (self-hosted) | 8 single-use codes at signup; downloadable PDF | 8 single-use codes; printable HTML page; user is told to keep them somewhere safe |
| Invite without SMTP | Discord server invites, Signal group invites | Out-of-band invite code (shared in person / Signal / etc.) | One-time 16-char invite code, expires in 24 h |
| Triple-gated destructive actions | Vercel project deletion, GitHub repo deletion | Toggle "advanced" → confirm modal → type project name | Dev Mode + Advanced Toggle + typed app name "diederik" |
| Streamed command output | Tinkerwell, Nova Command Runner | SSE / WebSocket stream from Symfony Process | Livewire SSE stream; Process facade with stdout/stderr capture |
| Embedded admin panel | Filament dashboards (when adopted), Forge inside Forge | Iframe or sub-app inside the main chrome | Iframe Horizon at `/horizon` inside a diederik tab |
| First-run wizard | Monarch Money signup, Linear onboarding | 3–5 steps; progress indicator; reassuring copy | 4 steps max; calm Tailwind; "Skip" available for CLI users |
| Auto-update | Linear, Slack, VS Code | Background download + "Restart to install" prompt; release-notes modal | electron-updater + GitHub Releases; prompt-don't-force install |
| Opt-in telemetry | Signal, Standard Notes, Cryptee | Off by default; dedicated screen; explicit preview | Off by default; Sentry SDK with allow/deny lists; arch-test enforced denylist |
| Crash recovery | Electron-standard, NativePHP | Detect unclean shutdown → restore last session | NativePHP `before-quit` hook; restore route on next launch |
| Reveal in Finder/Explorer | macOS native, NativePHP | OS shell command | NativePHP `Shell::showItemInFolder()` |
| Beta channel opt-in | VS Code Insiders, Electron prereleases | Settings toggle; electron-updater prerelease channel | Settings → Updates → "Receive pre-release builds" |
| Read-only query runner | TablePlus, Beekeeper Studio | SQL editor with read-only mode | AST-validated SELECT-only; grid renderer |
| Schema viewer | Beekeeper, TablePlus | Sidebar tree of tables + columns + indexes | Sidebar tree from `sqlite_master` |
| Log tailer | Sentry, Telescope, Tinkerwell | SSE stream with filter | SSE from `tail -f` equivalent; max 1000 lines |
| Quit confirmation | Slack, Discord (call in progress) | Modal "Are you sure?" with context | Modal listing active Horizon jobs |

---

## Quality Gate Self-Check

- [x] **Categories cover all 5 areas** — Desktop Shell / Multi-User UX / Dev Mode UI / First-Run + Onboarding / Beta-Cycle Support, each with its own Table Stakes / Differentiators / Anti-Features split
- [x] **Complexity tags per feature** (S / M / L throughout)
- [x] **Cross-feature dependencies identified** — dedicated section + ASCII tree
- [x] **Pattern sources cited per feature** — Competitor / Pattern Source matrix at the end
- [x] **Password-reset-without-SMTP explicitly addressed** — Deep Dive 1 with rail 1 (recovery codes) + rail 2 (filesystem-fallback artisan) + hard-no list
- [x] **Telemetry stance explicit** — Deep Dive 5 + Area 5 anti-features. Off by default, opt-in on dedicated screen, allow/deny lists enforced via arch test, separate from updates and diagnostics
- [x] **Anti-features have explicit alternatives** — every anti-feature row has an "Alternative" column

---

## Sources

### NativePHP / Electron / Desktop shell
- [NativePHP Desktop v2 — Application](https://nativephp.com/docs/desktop/2/the-basics/application) — HIGH (official). Tray, badge, openAtLogin, App facade.
- [NativePHP Desktop v2 — Application Menus](https://nativephp.com/docs/desktop/2/the-basics/application-menu) — HIGH (official).
- [NativePHP Desktop v2 — Configuration](https://nativephp.com/docs/desktop/2/getting-started/configuration) — HIGH (official). Deep links, file handlers.
- [NativePHP Tutorial: Building a Mac MenuBar application (Laravel News)](https://laravel-news.com/nativephp-tutorial) — MEDIUM. Practical patterns.
- [System Tray & Menu Bar (NativePHP docs mirror on Mintlify)](https://www.mintlify.com/NativePHP/desktop/features/system-tray) — MEDIUM.
- [Electron — Tray Menu](https://www.electronjs.org/docs/latest/tutorial/tray) — HIGH (official, underlying tech).
- [Electron — Dock Menu (macOS)](https://www.electronjs.org/docs/latest/tutorial/macos-dock) — HIGH (official).
- [How To Make Your Electron App Sexy (Astec)](https://astec.net/insights-news/sexy-electron/) — MEDIUM. Desktop-feel patterns.

### Command palette / shell UX
- [Linear — How we redesigned the Linear UI part II](https://linear.app/now/how-we-redesigned-the-linear-ui) — HIGH (primary source).
- [Raycast Manual — Search Bar](https://manual.raycast.com/search-bar) — HIGH (official).
- [Raycast Manual — Keyboard Shortcuts](https://manual.raycast.com/keyboard-shortcuts) — HIGH (official).
- [Raycast Manual — Command Aliases & Hotkeys](https://manual.raycast.com/command-aliases-and-hotkeys) — HIGH (official).
- [Command Palette Pattern (uxpatterns.dev)](https://uxpatterns.dev/patterns/advanced/command-palette) — MEDIUM.
- [Command Palette UX Patterns (Mobbin)](https://mobbin.com/glossary/command-palette) — MEDIUM.

### Multi-user / authentication patterns
- [Firefly III — Multi-User documentation](https://docs.firefly-iii.org/how-to/firefly-iii/features/multi-user/) — HIGH (cautionary tale).
- [Firefly III — Administrations (sharing model)](https://docs.firefly-iii.org/explanation/financial-concepts/administrations/) — HIGH.
- [Firefly III — User and group management discussion (GitHub #6331)](https://github.com/firefly-iii/firefly-iii/issues/6331) — HIGH (still ongoing in 2026; great cautionary signal).
- [Slack — Switch between workspaces](https://slack.com/help/articles/1500002200741-Switch-between-workspaces) — HIGH (primary source for profile-switch pattern).
- [1Password — How to use multiple accounts](https://support.1password.com/multiple-accounts/) — HIGH.
- [Beekeeper Studio — Login and password reset](https://help.beekeeper.io/hc/en-us/articles/17392702737426-Login-and-password-reset) — MEDIUM.
- [Beekeeper Studio — Online Sync & Collaboration](https://www.beekeeperstudio.io/features/workspace) — MEDIUM.

### Password reset / recovery codes (no SMTP)
- [1Password — Generate and use recovery codes](https://support.1password.com/recovery-codes/) — HIGH.
- [1Password — Introducing recovery codes (blog)](https://blog.1password.com/introducing-1password-recovery-codes/) — HIGH.
- [Laravel 13.x — Resetting Passwords](https://laravel.com/docs/13.x/passwords) — HIGH (official). Cache driver for password resets without DB table.

### In-app developer panel / artisan runner
- [Laravel Telescope (12.x)](https://laravel.com/docs/12.x/telescope) — HIGH (official). Query log, request log, job inspection patterns.
- [Stepanenko3/nova-command-runner](https://github.com/stepanenko3/nova-command-runner) — HIGH. Prior art: artisan + bash runner inside Nova.
- [Running Laravel Artisan Commands from your Admin Dashboard (Lukas White)](https://lukaswhite.com/blog/running-laravel-artisan-commands-from-your-admin-dashboard/) — MEDIUM. Whitelist pattern, security guidance.
- [Streaming Command Output To The Browser (Freshleaf Digital)](https://www.freshleafdigital.co.uk/blog/streaming-laravel-command-output-to-the-browser) — MEDIUM. Symfony Process + StreamOutput.
- [Laravel 13.x — Artisan Console](https://laravel.com/docs/13.x/artisan) — HIGH (official). `Artisan::call()` with custom output buffer.

### Auto-update / code signing / CI
- [electron-builder — Auto Update](https://www.electron.build/auto-update.html) — HIGH (official).
- [iffy/electron-updater-example](https://github.com/iffy/electron-updater-example) — HIGH. Complete worked example.
- [electron-userland/electron-builder](https://github.com/electron-userland/electron-builder) — HIGH (official repo).
- [Implementing Auto-Updates in Electron with electron-updater (blog)](https://blog.nishikanta.in/implementing-auto-updates-in-electron-with-electron-updater) — MEDIUM. CI patterns, code signing env vars.

### First-run / onboarding
- [12 Apps with Great User Onboarding (UXCam, 2026)](https://uxcam.com/blog/10-apps-with-great-user-onboarding/) — MEDIUM. Monarch Money wizard pattern.
- [Mastering Fintech App Onboarding (CleverTap)](https://clevertap.com/blog/onboarding-fintech-app-users/) — MEDIUM. Progressive disclosure, trust building.
- [How to design a user-friendly fintech app onboarding (DECODE)](https://decode.agency/article/fintech-app-onboarding/) — MEDIUM.

### Crash reporting / telemetry / privacy
- [Sentry for Electron — Native Crash Reporting](https://docs.sentry.io/platforms/javascript/guides/electron/features/native-crash-reporting/) — HIGH (official).
- [Sentry for Electron — Privacy](https://docs.sentry.io/platforms/javascript/guides/electron/session-replay/privacy/) — HIGH (official).
- [getsentry/sentry-electron](https://github.com/getsentry/sentry-electron) — HIGH (official SDK).
- [Signal Support — Debug Logs and Crash Reports](https://support.signal.org/hc/en-us/articles/360007318591-Debug-Logs-and-Crash-Reports) — HIGH. Opt-in-per-submission pattern.
- [Mozilla Foundation — Signal App Review 2025: Privacy](https://www.mozillafoundation.org/en/nothing-personal/signal-privacy-review/) — MEDIUM. Telemetry-free baseline.
- [Opt-out phone-home, telemetry, crash reporting (HN discussion)](https://news.ycombinator.com/item?id=17805676) — LOW (signal but unverified).

---

*Feature research for: diederik v2.0 — Desktop Packaging + Multi-User + Developer Mode + CI/CD + Public Release*
*Researched: 2026-05-19*
