# Stack Research — diederik v2.0

**Domain:** Desktop packaging + multi-user activation + in-app dev console + CI/CD + public release of an existing Laravel 13 / Livewire 4 / SQLite local-finance app
**Researched:** 2026-05-19
**Confidence:** HIGH on every package version (verified against Packagist / GitHub Releases on 2026-05-19). MEDIUM on a few integration paths flagged inline (Horizon-in-NativePHP, PHP 8.5 bundling, Pulse-in-desktop) — those carry real uncertainty and the report says so honestly rather than papering over it.

This document is **incremental** — it lists only the packages and CI tooling required to deliver the new v2.0 capabilities on top of the already-pinned v1.0 stack. The v1.0 picks (Livewire 4 + Flux + Volt, Pest 3, Larastan L10, Pint, brick/money, genkgo/camt, league/csv, spatie/laravel-data, ApexCharts) are **not re-researched** — they continue verbatim. See [milestones/v1.0-research/STACK.md](../milestones/v1.0-research/STACK.md) for the v1.0 picks.

---

## The Critical Finding (read this first)

Three v1.0 stack choices are **incompatible with a NativePHP-bundled desktop build** and need a deliberate replacement strategy in v2.0:

| v1.0 pick | Why it breaks inside NativePHP | v2.0 resolution |
|-----------|--------------------------------|-----------------|
| **`laravel/horizon` + `predis/predis` + loopback-Docker Redis** | NativePHP v2 ships **only a SQLite-backed queue worker as a child process** ([docs](https://nativephp.com/docs/desktop/2/digging-deeper/queues)). Redis is not bundled, cannot be assumed installed on a partner's Mac/Windows box, and shipping Docker Desktop inside an installer is a non-starter. Horizon requires Redis hard. | Make the queue driver **environment-conditional**: `redis`/Horizon in dev (Herd, existing developer machine) → `database` driver via NativePHP's `QueueWorker` facade inside the desktop bundle. Document a `DIEDERIK_RUNTIME=desktop\|herd` flag that the `QueueServiceProvider` reads to pick driver + skip Horizon registration. **No new package** — this is a configuration carve-out using `database` queue tables already provisioned in Phase 5 alongside Horizon (the v1.0 stack provisioned both). |
| **`launchd` plists for scheduler / queue / IMAP-idle** | macOS-only. Windows + Linux partner installs cannot rely on launchd. NativePHP runs `php artisan schedule:run` itself when the app window is open, and spawns one `queue:work` child process per `queue_workers` entry in `config/nativephp.php`. | Replace launchd wiring with **NativePHP's `ChildProcess` + `QueueWorker` facades** for desktop installs. Keep the launchd plists for the developer's Herd-hosted dev box (it's still the daily-use machine). The `diederik:install --launchd` command stays but learns a `--desktop-mode` skip flag. |
| **`@chmod 600` single-file OAuth secrets** | Single-user model. v2.0 needs **per-user isolation** — partner's Gmail OAuth refresh token must not be readable from the developer's session and vice versa. | Move to a **per-user encrypted secrets table** in SQLite (encrypted via `APP_KEY` + per-user-derived key) **OR** OS-keychain via NativePHP `ChildProcess::php()` shelling out to `security` (macOS) / `wincred` (Windows) / `secret-tool` (Linux). Recommendation below: SQLite-encrypted-row, because shelling out to three OS-specific CLIs from PHP under NativePHP is a portability tax the project doesn't need. |

If you remember nothing else from this report: **Horizon does not run inside the desktop bundle.** Plan the migration.

---

## Recommended Stack Additions

### Core: Desktop Packaging

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| **`nativephp/desktop`** | `^2.2` (2.2.0 released 2026-04-29; first Laravel 13 release; merged the previous `nativephp/electron` + `nativephp/laravel` packages into one consolidated repo) | The whole desktop-packaging story: bundles a static-built PHP runtime + Electron 38 shell + SQLite + electron-builder pipeline + auto-updater | Officially blessed Laravel 13.x compatibility merged via [PR #96 by laravel-shift](https://github.com/NativePHP/desktop/pull/96). Active maintenance (5 releases in 2026 alone). Requires PHP ^8.3 and `illuminate/contracts ^10\|^11\|^12\|^13`. User has committed to this path; alternatives table below documents the rejected ones for the record. |
| **`nativephp/php-bin`** | `^1.1` (1.1.1, 2025-09-02; weekly auto-builds via static-php-cli) | Statically-linked PHP binaries bundled inside the Electron shell, one per platform (Win x64, macOS x64+ARM64, Linux x64+ARM64) | Transitive dep of `nativephp/desktop`; you don't `composer require` it directly. **PHP 8.5 caveat below — currently ships 8.1 / 8.2 / 8.3 / 8.4 only.** |
| **Node.js (developer machine)** | `>=22` (NativePHP v2 requirement) | Build-time only; runs electron-builder, code-signing scripts, auto-update publisher. Not shipped to end-users. | Required for `php artisan native:run` and `native:build`. Install via Herd (Herd ships Node) or Volta. |
| **Electron** | `38.x` (bundled by `nativephp/desktop` 2.2.0) | Chromium + Node shell that hosts the PHP runtime + your Blade/Livewire UI | Transitive — pinned by NativePHP. v2 upgrade brought security improvements (`contextIsolation` on, `nodeIntegration` off by default). |
| **electron-builder** | `^26.0` (bumped in NativePHP `desktop` 2.2.0 to fix Azure-signing path-with-spaces bug) | Produces `.dmg` (mac), `.exe`/`.msi` (win), `.AppImage`/`.deb` (linux) installers; wires up code-signing + notarization + electron-updater publishing | Transitive — used inside NativePHP's `native:build`. |
| **electron-updater** | `^6.x` (transitive via electron-builder; auto-update only works on **signed** mac apps + supports GitHub Releases / S3 / generic provider) | The actual auto-update client baked into the shipped app | Transitive. NativePHP exposes `AutoUpdater::checkForUpdates()` / `AutoUpdater::quitAndInstall()` Laravel facades; you configure `publish` in `package.json` to point at `provider: github`. macOS auto-update **requires** Developer ID + notarization. |

### PHP 8.5 Bundling — KNOWN GAP

**The project pins PHP 8.5 (composer.json `"php": "^8.5"`). `nativephp/php-bin` 1.1.1 only ships PHP 8.1 / 8.2 / 8.3 / 8.4 static binaries** ([php-extensions.txt](https://github.com/NativePHP/php-bin/blob/main/php-extensions.txt) on `main`; weekly auto-build cadence). Three options:

1. **Wait + pin to PHP 8.4 for the desktop build only** (composer.json keeps `^8.5` for dev; the bundled binary is 8.4; tests must run on both). Recommended because Laravel 13's official requirements are PHP 8.3+, and the project's code targets typed Laravel-13 APIs, not 8.5-specific syntax. **Verify before committing** by running the Larastan L10 strict gate with PHP 8.4 — any `8.5`-only feature surface would explode there.
2. **Lower the project pin to `^8.4`** in composer.json. Loses ~6 months of PHP 8.5 features the project doesn't currently use (`#[\NoDiscard]`, asymmetric visibility on constants, AST API). Cleanest if the L10 gate passes on 8.4.
3. **Wait for `nativephp/php-bin` to ship 8.5.** static-php-cli supports PHP 8.5 in its own roadmap; the binary auto-build is weekly. This is a "watch and merge when ready" path. The packaging phase can ship with 8.4 and a tracking task ("upgrade-php-bin-to-8.5") in the carry-over backlog.

**Recommendation: option 1 — bundle PHP 8.4 in the desktop installer while keeping the dev pin at 8.5.** Confidence MEDIUM (this hinges on the L10 gate passing on 8.4; trivially verifiable in a 10-minute spike).

### Core: Multi-User Auth

| Library | Version | Purpose | Why |
|---------|---------|---------|-----|
| **`laravel/fortify`** | `^1.37` (1.37.2, 2026-05-15; explicit Laravel 13 support) | Headless auth backend — login, registration, password reset, "remember me", 2FA (optional), session management. Provides routes + actions + contracts; you ship your own Livewire/Volt UI on top. | **Already in v1.0 composer.json** (`laravel/fortify: ^1.21`) but the project never activated the UI / session layer for it. Fortify is the headless layer of Jetstream — perfect when Flux + Volt provide the UI and you want zero Tailwind-styling baked in. Stays facade-free if you wire its actions through DI (Fortify's `LoginViewResponse`, `RegisterViewResponse` etc. are bound via the container — match the project's DI-only invariant trivially). |
| **`livewire/flux`** | `^2.14` (already pinned at `^2.0`; latest 2.14.1) | Login / register / "logout button in user menu" UI components (Flux ships `<flux:input type="email">`, `<flux:button>`, password-strength meter, etc.) | Already in the v1.0 stack. v2.0 reuses these for the auth shells — no new package, only new Volt routes (`Auth/Login.blade.php`, `Auth/Register.blade.php`, `Auth/PasswordReset.blade.php`, `Profile/Switcher.blade.php`). |
| **Session driver** | `file` driver, **NOT** `database` for the desktop build | Laravel default. NativePHP's SQLite is single-writer + the user already pays for it on every Eloquent write — no benefit to adding `sessions` table contention. | Files live under `storage/framework/sessions/` which NativePHP relocates to the OS user-data dir (`~/Library/Application Support/diederik/`, `%APPDATA%\diederik\`, `~/.config/diederik/`) on first launch. **Caveat: file sessions don't survive an app reinstall** — acceptable for a desktop app where reinstalling logs you out is the expected mental model. |
| **Password hashing** | `bcrypt` (Laravel default) — cost factor `12` | Sufficient for a 1-3-user app | Don't switch to argon2id for this; it adds a `sodium` binary dependency that NativePHP's static-PHP binary already includes, but the operational complexity of "the new hasher is faster on M-series Macs but slower on the partner's older Intel Mac" isn't worth the marginal upside. |
| **Password-reset email delivery** | **`log` mail driver** in desktop builds + an in-app "reset link copied to clipboard" UX | A desktop app on `127.0.0.1` has no SMTP server. Anything that requires sending an actual email to the partner is the wrong design for a personal-finance app where the partner is sitting next to the developer. | The reset flow becomes: "click forgot password → token written to a `password_resets` row → app surfaces a modal with a one-time code → partner types it into 'I have a code' screen → set new password." Documented as **PWR-01: in-app password reset (no SMTP)** in REQUIREMENTS.md. Optional v2.1 upgrade: SMTP via Gmail OAuth using the existing `Modules/EmailScan` infrastructure. |
| **Per-user-id authorization** | Laravel policies + the existing `BelongsToUser` trait + `UserIdColumnArchTest` (v1.0) | The schema is already multi-user-ready; v2.0 just turns on the enforcement layer. | No new package. The trait already scopes `Model::query()` calls to `$user->id`. v2.0's only addition is a `ForCurrentUser` global scope binding in each module's service provider — wired through DI so it stays facade-free. |

**Rejected auth packages:**

- **`laravel/breeze`** (v2.4.2, 2026-05-14) — Breeze scaffolds Blade auth views with Tailwind. v1.0 already uses Flux + Volt + the Livewire starter kit, so Breeze's views are redundant; pulling it in would create two sets of auth views and a parallel Tailwind utility set to maintain. Don't.
- **`laravel/jetstream`** (v5.5.3, 2026-05-19) — Jetstream is Fortify + a UI stack opinion. Since Flux + Volt is already the UI stack, taking Jetstream means inheriting its Tailwind classes and team-management UI you don't need. Use bare Fortify under Flux instead.
- **`laravel/sanctum`** (v4.3.2, 2026-04-30) — Sanctum is for SPA/API token auth. The desktop app is a server-rendered Livewire UI talking to its own embedded PHP; there are no API tokens to manage. Skip.
- **`spatie/laravel-permission`** (v7.4.1, 2026-04-29) — RBAC with roles + permissions tables. Overkill for 1-3 trusted users; "owner vs guest" can be a single nullable column on `users`. Skip until v3.0.

### Core: Per-User Secrets Isolation

Currently the v1.0 stack stores all OAuth secrets in one `storage/app/secrets/imap.json` (chmod 600). The `OAuthSecretsRepository` is the single chokepoint, enforced by the `PLT-03` arch test. v2.0 needs per-user-id isolation. Two paths evaluated:

| Approach | Verdict |
|----------|---------|
| **SQLite-stored encrypted secrets row, keyed by `user_id`** | **RECOMMENDED.** Adds an `oauth_secrets` table with columns `(id, user_id, provider, encrypted_payload, created_at, updated_at, UNIQUE(user_id, provider))`. `encrypted_payload` is a Laravel `Crypt::encryptString()` blob (uses `APP_KEY`). Read/write only through the existing `OAuthSecretsRepository` (constructor-injected — matches DI-only invariant). Backs up cleanly via `db:backup` because it's part of the same SQLite file. Survives uninstall/reinstall via the SQLite-in-appdata location. **Zero new packages**, only the migration + repository swap. **Tradeoff: if `APP_KEY` leaks, every user's secrets leak.** Acceptable for a 1-3-user app on a trusted box; the threat model here is "partner accidentally reads my Gmail token", not "nation-state attacker has shell on my Mac." |
| **OS-keychain via `ChildProcess::php()` shelling out to `security` / `wincred` / `secret-tool`** | NOT recommended. Three CLIs, three argument shapes, three failure modes. Each `ChildProcess` call costs ~80-150ms PHP-spawn overhead. Real wins (Touch ID prompt on macOS, Windows Hello on Win11) are nice but require **another** PHP-process per read which fights the project's "DI calls are 1ms" implicit-perf-budget. **Defer to v2.1 if a real threat-model need emerges.** Documented as `PLT-04` future-work item. |

**Recommendation: SQLite-encrypted row. No new package.**

### Core: In-App Developer Mode UI

This is the most "build it yourself" of the v2.0 capabilities — there is no Filament-grade off-the-shelf "in-app artisan console + log tailer + queue inspector" Livewire package that meets the project's DI-only + arch-test-enforced quality bar. Here is the bill of materials:

| Need | Package | Version | Why |
|------|---------|---------|-----|
| **Whitelisted artisan-command runner UI** | **Build bespoke** under `Modules/DevConsole/` (new module) | n/a | Surveyed the only candidate, [`cleaniquecoders/laravel-artisan-runner`](https://packagist.org/packages/cleaniquecoders/laravel-artisan-runner) (1.2.1, 2026-03-30, **1 GitHub star, 4 releases over one month, no production deployments visible**). Too new and unproven for a security-critical capability ("run `db:restore` from the UI"). Build bespoke: a `DevConsoleCommandWhitelist` registry + a Volt page rendering `<flux:select :options="$commands">` + a queued `RunDevCommandJob` that uses `Symfony\Component\Process\Process` (already in dev-deps as `symfony/process: ^7.0`) to invoke `php artisan <cmd>`. Destructive commands (`db:restore`, `db:nuke`) gated by a second confirm screen + a 60-second hold timer. Inherits the existing DI-only + arch-test patterns. |
| **Job/queue inspector inside the desktop bundle** | **Build minimal `<JobInspector>` Livewire component** querying the `jobs` + `failed_jobs` tables directly | n/a | Horizon is removed for the desktop bundle (see Critical Finding). A 200-line Livewire component reading `jobs` (pending), the worker's heartbeat (NativePHP's `QueueWorker::status()`), and `failed_jobs` (last 50, retry/forget buttons) covers everything the developer asked for. **Optional later:** `laravel/pulse` (v1.7.3, 2026-03-26, supports Laravel 13) — see "Optional / nice-to-have" table below. |
| **Live log tailer** | **Build bespoke** — Livewire component with a `wire:poll.2s` tail of `storage/logs/laravel.log` | n/a | `tail -f` doesn't survive cross-platform (no `tail` on bare Windows; needs PowerShell `Get-Content -Wait`). PHP-side `fseek` + `fread` of the last 64 KB of the log file every 2 seconds is portable, simple, and matches Telescope's read pattern. Add a "download full log" button that streams the file via `StreamedResponse`. |
| **`diederik:doctor` UI surface** | **Build bespoke** — reuse the v1.0 `Diederik\HealthCheckService` (Phase 11) | n/a | The probe service already exists; v2.0 only adds a Volt page that renders `$health->runAll()` results in a sortable Flux table and a "re-run" button. |
| **Configuration inspector** | **Build bespoke** — renders `config(*)` as a tree | n/a | Useful for debugging "is the desktop runtime really using SQLite queue driver?". A 50-line component reading `app('config')->all()` (yes, via DI — inject `Repository`). Redact `*_key`, `*_secret`, `*_password`, `db_password`. |
| **`laravel/telescope`** (rejected for desktop bundle) | `v5.20.0` (2026-04-06) | — | Telescope's UI is heavy (full Vue SPA) + its DB writes are aggressive (one row per query/job/log entry). Adds material weight to a single-machine SQLite bundle. Use **only on the developer's Herd-hosted dev environment**, not inside the shipped desktop installer. |
| **`laravel/pulse`** (optional, deferred to v2.1) | `v1.7.3` (2026-03-26, explicit Laravel 13 support) | — | Lighter than Telescope. Server-load + queue-depth + slow-queries surfaced as widgets. Useful inside the desktop bundle. **Defer**: the bespoke dev-mode console above covers v2.0; Pulse adds a second observability surface to maintain. Note: Pulse uses Redis cache for rolling counters by default — inside the desktop bundle it must be reconfigured to the `database` cache driver. |

### Core: CI/CD on GitHub Actions

| GitHub Action / tool | Version | Purpose | Why |
|----------------------|---------|---------|-----|
| **`actions/checkout`** | `v6` (latest as of 2026-05; bumped from `v5` inside `nativephp/desktop` 2.2.0) | Source checkout in PR-gate + release workflows | Standard. Pin major version. |
| **`shivammathur/setup-php`** | `v2` (latest) | Installs PHP 8.5 (or 8.4 for the desktop-binary-build job) on Ubuntu / macOS / Windows runners with all needed extensions | The de-facto standard; pinned tightly to receive PHP point-release updates as floating tags. |
| **`ramsey/composer-install`** | `v3` (latest) | Composer install with cache | Faster than running `composer install` directly. |
| **`actions/setup-node`** | `v4` | Node 22 for the electron-builder build step | Required by NativePHP v2. |
| **`apple-actions/import-codesign-certs`** | `v7.0.0` (2026-04-21) | Imports Apple Developer ID Application certificate + private key into a temporary keychain on the macOS runner | The canonical action used by every Electron-on-macOS pipeline. Verified active (5 releases in 2026). |
| **`Azure/trusted-signing-action`** | `v2.0.0` (2026-05-14) | **The recommended Windows signing path.** Azure Trusted Signing is Microsoft's cloud-signing service that replaces an EV hardware-token cert with an Azure-managed certificate, signs from the runner without a USB token, and is **also OV-cert-equivalent for SmartScreen reputation purposes**. | NativePHP explicitly supports Azure Trusted Signing via env vars in `config/nativephp.php` ([docs](https://nativephp.com/docs/desktop/2/publishing/building)). Cheaper than buying a physical EV USB token (~$300/yr Azure subscription vs ~$300/yr per EV cert + the hardware-shipment dance). Single CI runner can sign without HSM presence. |
| **`softprops/action-gh-release`** | `v2` | Publish the produced `.dmg`/`.exe`/`.msi`/`.AppImage`/`.deb` to GitHub Releases on tag push | Industry standard. Works with electron-updater's `github` provider. |
| **`actions/attest-build-provenance`** | `v2` (latest as of 2026-05) | Generates SLSA-style attestations for the shipped installers; lets users verify the binary came from this repo's CI | Optional but cheap; recommended for a publicly-shipped binary in 2026. |

**Apple notarization wiring.** Apple's `notarytool` (built into Xcode 13+ command-line tools on macos-14+ runners) takes `--apple-id` + `--team-id` + an **app-specific password** (NOT your iCloud password). NativePHP reads `NATIVEPHP_APPLE_ID` / `NATIVEPHP_APPLE_ID_PASS` / `NATIVEPHP_APPLE_TEAM_ID` env vars and invokes notarytool automatically inside `native:build`. Set these as **GitHub Actions secrets** scoped to the release workflow only.

**Windows EV vs Azure Trusted Signing — the actual decision.** The project brief says "Windows EV cert." Azure Trusted Signing produces certificates whose SmartScreen reputation accrues **as if they were EV** in practice (Microsoft documentation, multiple 2025-2026 confirmations). The functional outcome is identical: signed `.exe`/`.msi`, no SmartScreen warning after initial reputation builds. Recommend **Azure Trusted Signing** unless the user already owns an EV USB token. If they do, switch to `signtool` via `nschloe/action-cached-signtool` (an alternative I evaluated but didn't recommend — adds an HSM-presence requirement to the CI runner, fighting GitHub-hosted runners; works only with self-hosted Windows runners hosting the USB token).

**Larastan L10 strict + Pint + Pest matrix in PR gates.** Already exists in v1.0 (the user mentioned 1644 tests green at v1.0 close). v2.0 adds:
- A matrix axis for PHP 8.4 *and* 8.5 (catches issues before they hit the desktop binary)
- A matrix axis for SQLite-mode runs (`DB_CONNECTION=sqlite_memory`) *and* Redis-mode runs (catches the Horizon-disable regression)
- A nightly run of `native:build` on all three platforms to catch packaging regressions outside the tag-trigger window

### Optional / nice-to-have

| Library | Version | Purpose | Recommendation |
|---------|---------|---------|----------------|
| **`laravel/pulse`** | `^1.7` | Lightweight performance observability inside the desktop bundle | **Defer to v2.1.** The bespoke dev-mode console covers v2.0. |
| **`spatie/laravel-backup`** | `^10.2` (10.2.1, 2026-03-24, Laravel 13) | Scheduled backups | **Skip.** v1.0 already shipped `php artisan db:backup` via `VACUUM INTO` (Phase 11). spatie/laravel-backup wraps `mysqldump`/`pg_dump` — not the path for SQLite. |
| **`spatie/laravel-activitylog`** | `^4.12` (4.12.3, 2026-03-24, Laravel 13) | Audit log of user actions (login, OAuth re-auth, destructive command runs) | **Recommended for the dev-mode destructive-command runner.** Specifically: log every `RunDevCommandJob` invocation with `user_id`, command, args, exit code, stderr tail. Five lines of trait-on-model + one config file. v5.0 (2026-03-25) requires PHP 8.4; either pin to 4.12.x or go with v5 if the desktop bundle ships 8.4. |
| **`spatie/laravel-csp`** | `^3.x` | Content-Security-Policy header for Livewire | **Skip.** Inside Electron the renderer is already sandboxed by `contextIsolation: true` (NativePHP v2 default); CSP is a web-only concern. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| **GitHub Actions self-hosted Mac runner** | macOS signing + notarization needs an Apple Silicon runner if shipping universal binaries | Optional. The free GitHub-hosted `macos-14` runner (Apple Silicon) is sufficient unless build times push past the 6-hour limit. |
| **Apple Developer Program membership** | Required for Developer ID certificate + notarization | $99/yr. Non-negotiable. |
| **Azure subscription + Trusted Signing account** | Windows code signing | ~$10/month. Cheaper than an EV USB token. |
| **DMG-Canvas / electron-builder's built-in DMG** | macOS `.dmg` cosmetics (background image, drag-to-Applications hint) | electron-builder's built-in DMG handling is sufficient; DMG-Canvas is a $20 polish purchase the user can defer. |

---

## Installation (additions only)

```bash
# 1. Desktop packaging — replaces nothing in composer.json; ADD:
composer require nativephp/desktop:^2.2
php artisan native:install   # publishes config/nativephp.php, adds native:dev npm script

# 2. Per-user secrets table — DB migration only, no new package:
php artisan make:migration create_oauth_secrets_table

# 3. Spatie activitylog for dev-mode audit (optional but recommended):
composer require spatie/laravel-activitylog:^4.12

# 4. Activate Fortify (already in composer.json; bind routes/views):
php artisan vendor:publish --tag=fortify-config
# Then write Modules/Auth/{Login,Register,PasswordReset}.blade.php (Volt SFCs) against Flux UI

# 5. Add a runtime flag to AppServiceProvider:
# config/diederik.php → 'runtime' => env('DIEDERIK_RUNTIME', 'herd')
# Queue + scheduler service providers branch on this flag.

# 6. Build the desktop app locally:
php artisan native:dev    # dev mode with hot reload
php artisan native:build  # production build (signed if env vars present)
```

GitHub Actions workflow files live under `.github/workflows/`:
- `pr-gates.yml` — Larastan L10 + Pint + Pest matrix (PHP 8.4 + 8.5, sqlite + redis)
- `desktop-build.yml` — tag-triggered, matrix over `macos-14` / `windows-2025` / `ubuntu-24.04`, runs `native:build`, signs (Apple notarytool + Azure Trusted Signing), uploads to GitHub Release via `softprops/action-gh-release@v2`

---

## Alternatives Considered

| Recommended | Alternative | When the Alternative Wins |
|-------------|-------------|---------------------------|
| **NativePHP / Electron** | **Tauri (via NativePHP's Tauri target, still beta as of 2026-05)** | Smaller installer (10-15 MB vs 80-120 MB), better RAM footprint. Reject because: (a) Tauri-target on NativePHP v2 is documented as a runtime option but the v2 Electron path is the mature, well-trodden one; (b) Tauri uses each OS's native WebView (WKWebView / WebView2 / WebKitGTK) — three rendering engines means three regression surfaces; (c) for a 1-3-user dashboard, installer size is not the differentiator. |
| **NativePHP** | **A `Process`-launched local PHP server + a thin Electron/Tauri shell built by hand** | Greater control. Reject: the user has committed to NativePHP and the v2.x maturity is now real. |
| **Fortify (headless) + Flux/Volt UI** | Breeze (with Tailwind views) | If you want auth UI scaffolded in 5 minutes and don't care that it doesn't match Flux's design tokens. Reject: clashes with the established UI system. |
| **Fortify** | Jetstream | If you want Fortify + ready-made team management + Tailwind UI. Reject: brings teams/2FA UI you don't need; clashes with Flux. |
| **Azure Trusted Signing for Windows** | **EV USB cert + signtool on self-hosted runner** | If the user already owns an EV USB token. Reject for greenfield setup: physical-token-presence requirement breaks GitHub-hosted runners. |
| **SQLite-encrypted secrets** | OS-keychain shell-outs (`security`/`wincred`/`secret-tool`) | If a threat model emerges where APP_KEY leakage is a real concern (e.g., the dev machine joins a multi-tenant Mac fleet). Defer. |
| **electron-builder's built-in DMG** | DMG-Canvas | If the DMG installer's drag-to-Applications cosmetics aren't pretty enough. Defer to a "polish" phase. |
| **Bespoke `Modules/DevConsole`** | `cleaniquecoders/laravel-artisan-runner` | If you accept a 1-star, brand-new package gating destructive command execution. Reject for v2.0 (security-critical surface). Revisit if it gains adoption. |
| **NativePHP queues (SQLite, single child worker)** | Run Horizon outside the bundle, talk to it via HTTP from the desktop app | Adds a network dependency for a single-user app on `localhost`. Reject: defeats the "ships as one installer" goal. |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| **`nativephp/electron` or `nativephp/laravel`** (the pre-v2 packages) | Superseded as of NativePHP v2 (Oct 2025); the two repos were merged into `nativephp/desktop`. The old packages still exist on Packagist but are frozen at 1.3.x with no Laravel 13 support. | `nativephp/desktop ^2.2` |
| **Laravel Horizon inside the desktop bundle** | Requires Redis. NativePHP doesn't ship Redis. Bundling Redis = bundling Docker = installer goes from 80 MB to ~500 MB and adds an OS-permission prompt. | NativePHP's built-in `QueueWorker` (SQLite-backed). Keep Horizon **only** for the developer's Herd dev environment, behind a `DIEDERIK_RUNTIME=herd` flag. |
| **Redis as a queue / cache driver inside the bundle** | Same reason. | `database` cache + `database` queue inside NativePHP. Both already exist as Laravel built-ins. |
| **`launchd` plists as the cross-platform background-worker mechanism** | macOS-only. Partner's box could be Windows. | NativePHP's `ChildProcess` + `QueueWorker::up()` facades + the Electron main-process lifecycle (workers die when the app window closes — that's the desktop-app contract). |
| **`laravel/telescope` inside the desktop bundle** | Heavy DB writes + a full Vue SPA UI inflate the bundle for marginal benefit over the bespoke dev-mode console. | Telescope on the developer's Herd machine only. Bespoke `Modules/DevConsole` inside the bundle. |
| **`laravel/breeze`** | Scaffolds Tailwind Blade views; Flux already provides better-styled equivalents and the v1.0 stack already chose Flux. | Fortify (headless) + Volt + Flux for the auth UI. |
| **`laravel/jetstream`** | Inherits Tailwind opinions + team-management UI you don't need. | Same as above. |
| **`laravel/sanctum`** | API-token auth. The desktop app has no API to call. | Session cookies (Laravel default). |
| **EV USB hardware token for Windows signing on GitHub-hosted runners** | Hardware tokens can't live in GitHub-hosted runners. Forces self-hosted Windows. | Azure Trusted Signing. |
| **SMTP password-reset email in the desktop runtime** | No SMTP server inside the bundle; partner's email config isn't yours to configure. | In-app reset code modal (PWR-01). SMTP via existing Gmail OAuth is a v2.1 nice-to-have. |
| **`cleaniquecoders/laravel-artisan-runner`** (for destructive command runs) | 1 GitHub star, 4 releases in one month, zero production deployments visible — too unproven to gate `db:restore` behind. | Bespoke `Modules/DevConsole` with whitelist + 60s hold timer for destructive operations. |
| **Setting NativePHP secrets in `.env`** | Bundled `.env` is read-only after install and visible to anyone who unzips the `.app`. | Use NativePHP `Settings::set()` (writes to `config.json` in user-data dir) for non-secret prefs; SQLite-encrypted rows for secrets. |
| **`storage/app/secrets/imap.json` (current v1.0 location)** in the desktop bundle | This path is read-only inside an installed `.app` on macOS; v1.0's `OAuthSecretsRepository` writes will silently fail (or, worse, succeed in `/tmp` and lose data on reboot). | SQLite-encrypted `oauth_secrets` table keyed by `user_id`. |
| **PHP 8.5 in the bundled binary today (2026-05)** | `nativephp/php-bin` 1.1.1 only ships 8.1-8.4. | Bundle PHP 8.4 in the installer; keep dev pin at 8.5; track [php-bin upgrades](https://github.com/NativePHP/php-bin) and bump when 8.5 lands (weekly cadence). |

---

## Stack Patterns by Variant

**If the partner's box is Windows-only:**
- Use NativePHP's `--platform=win` flag for `native:build`
- Azure Trusted Signing remains the right Windows path
- Skip the macOS notarization workflow in CI for this user-only-Mac scenario
- Linux build can be deferred

**If PHP 8.5 support lands in `nativephp/php-bin` before the packaging phase ships:**
- Bundle 8.5 directly; no compat shim needed
- Drop the 8.4 axis from the PR-gate matrix once verified

**If the user adopts macOS Keychain in v2.1 instead of SQLite-encrypted rows:**
- Add a `Modules/Secrets/Keychain/{MacOSKeychain, WindowsCredentialManager, LinuxSecretService}` strategy set, swap via dependency injection
- Schema-migrate `oauth_secrets` blobs out to the keychain on first launch after upgrade
- Touch ID / Windows Hello prompts surface for free

**If the user later wants real email-based password reset:**
- Wire the existing Gmail-OAuth infrastructure from `Modules/EmailScan` into a `MailerService` that uses the user's own Gmail account as outgoing SMTP via OAuth2
- No new package — reuses Phase 6's Google API client

**If Horizon-style queue observability is required inside the desktop bundle:**
- Wait for Pulse (v1.8+) to land a Pulse-without-Redis story
- OR build a bespoke `<JobInspector>` over the `jobs` + `failed_jobs` tables (recommended for v2.0)

**If installer size becomes a concern (> 200 MB):**
- Switch to NativePHP's Tauri target once it leaves beta (currently late 2026 / early 2027 per the team's roadmap signals)
- Strip unused PHP extensions from `nativephp/php-bin` via a custom rebuild

---

## Version Compatibility Matrix

| Package | Verified Version (2026-05-19) | PHP | Laravel | Notes |
|---------|-------------------------------|-----|---------|-------|
| `nativephp/desktop` | 2.2.0 (2026-04-29) | ^8.3 | ^10\|^11\|^12\|^13 | Laravel 13 merged via PR #96 in this release |
| `nativephp/php-bin` | 1.1.1 (2025-09-02) | 8.1 / 8.2 / 8.3 / 8.4 binaries | n/a | **No 8.5 binary yet.** Weekly auto-build cadence. |
| Electron | 38.x (transitive via nativephp/desktop 2.2.0) | n/a | n/a | `contextIsolation: true` + `nodeIntegration: false` by default in v2 |
| electron-builder | ^26.0 (transitive) | n/a | n/a | Bumped in nativephp/desktop 2.2.0 |
| electron-updater | ^6.x (transitive) | n/a | n/a | macOS auto-update requires Developer ID + notarization |
| `laravel/fortify` | 1.37.2 (2026-05-15) | ^8.2 | ^11\|^12\|^13 | Already in v1.0 composer.json at ^1.21 — bump |
| `laravel/framework` | 13.11.0 (2026-05-19) | ^8.3 (max 8.5) | self | PHP 8.3 minimum |
| `livewire/livewire` | 4.7.0 (2026-05-03) | ^8.3.0 | n/a | Already pinned |
| `livewire/flux` | 2.14.1 (2026-04-23) | ^8.1 | n/a | Already pinned |
| `laravel/horizon` | 5.46.0 (2026-04-20) | ^8.0 | ^9\|^10\|^11\|^12\|^13 | **Only for Herd dev mode, not desktop bundle** |
| `predis/predis` | ^3.4 | n/a | n/a | **Only for Herd dev mode** |
| `laravel/pulse` | 1.7.3 (2026-03-26) | ^8.1 | ^10\|^11\|^12\|^13 | Optional, deferred to v2.1 |
| `laravel/telescope` | 5.20.0 (2026-04-06) | ^8.0 | n/a | Dev only, NOT in desktop bundle |
| `spatie/laravel-activitylog` | 4.12.3 (2026-03-24) | ^8.1 | ^11\|^12\|^13 | v5 needs PHP 8.4; pin to 4.12.x for now |
| `apple-actions/import-codesign-certs` | v7.0.0 (2026-04-21) | n/a | n/a | GitHub Action |
| `Azure/trusted-signing-action` | v2.0.0 (2026-05-14) | n/a | n/a | GitHub Action |
| `actions/checkout` | v6 | n/a | n/a | GitHub Action |
| `actions/setup-node` | v4 | n/a | n/a | Node 22+ required |
| `softprops/action-gh-release` | v2 | n/a | n/a | Publishes installers to GitHub Releases |

---

## Conflicts With Existing v1.0 Constraints — Explicit Audit

| v1.0 Constraint | v2.0 Addition | Status |
|-----------------|---------------|--------|
| **DI-only — no facades, no helpers** | `nativephp/desktop` exposes `Native::*`, `Settings::*`, `Window::*`, `Menu::*`, `AutoUpdater::*` **as facades**. | **Wrap, don't use directly.** Add a `Modules/Desktop/Public/DesktopShell` service interface that DI-injects `\Native\Laravel\Facades\Native` instances. Same pattern v1.0 already uses for Carbon (`SystemClock`) and the IMAP client. Arch test extends to cover `\Native\*` namespace. |
| **DI-only** | Fortify ships `LoginViewResponse`, `RegisterViewResponse`, etc. — bound via the container, not the facade. | **No conflict.** Fortify is one of the cleanest container-friendly Laravel packages; constructor inject the contracts. |
| **DI-only** | electron-updater + `AutoUpdater` Laravel facade | Same wrap-with-DI-service treatment as `Native::*`. |
| **No Docker except loopback Redis (Phase 5 carve-out)** | NativePHP doesn't introduce any Docker. | **No conflict.** Redis carve-out stays for the Herd dev mode; not present in the desktop bundle. |
| **Modular architecture, public/internal split** | New modules: `Modules/Desktop`, `Modules/Auth`, `Modules/DevConsole` | **No conflict.** Each gets its own `Public/` surface; `BoundaryArchTest` extends automatically by directory pattern. |
| **Larastan L10 strict** | `nativephp/desktop` facades pass through `__callStatic` → may need `@phpstan-ignore-next-line` at the DI-wrapper boundary OR a stub class generated by `barryvdh/laravel-ide-helper` (already-common pattern). | **Low risk**, verifiable in a 10-minute spike. |
| **`ext-imap` forbidden** | `nativephp/php-bin` 1.1.1 static binaries; verify the extension list. | **Verify.** The [php-extensions.txt](https://github.com/NativePHP/php-bin/blob/main/php-extensions.txt) at HEAD is the source of truth. Don't enable IMAP. |
| **Multi-currency BIGINT minor units + brick/money** | NativePHP touches no money primitives. | **No conflict.** |
| **`user_id` on every domain table** | New tables (`oauth_secrets`, `dev_console_runs`, `activity_log`) all carry `user_id`. | **No conflict by construction.** `UserIdColumnArchTest` enforces. |
| **Secrets via file-with-chmod 600** | Moving to SQLite-encrypted-row for per-user isolation. | **Replaces the v1.0 model.** Update `PLT-03` arch test to verify `OAuthSecretsRepository` is the only writer of the `oauth_secrets` table. |
| **Idempotent ingestion** | None of the v2.0 additions touch ingestion. | **No conflict.** |

---

## Sources

All versions verified on **2026-05-19** via the Packagist v2 metadata API (`https://repo.packagist.org/p2/<vendor>/<package>.json`) and the GitHub Releases API.

**NativePHP**:
- [nativephp/desktop on Packagist](https://packagist.org/packages/nativephp/desktop) — HIGH. 2.2.0 (2026-04-29), requires PHP ^8.3 + illuminate/contracts ^10|^11|^12|^13.
- [NativePHP/desktop 2.2.0 release notes](https://github.com/NativePHP/desktop/releases/tag/2.2.0) — HIGH. Confirms `Laravel 13.x Compatibility by @laravel-shift in PR #96` and `Bump electron-builder to ^26.0.0`.
- [NativePHP v2 release announcement](https://nativephp.com/blog/nativephp-for-desktop-v2-released) — HIGH (official blog, 2025-10-14). Confirms repo consolidation + Electron 38 upgrade + `contextIsolation` default.
- [NativePHP v2 installation docs](https://nativephp.com/docs/desktop/2/getting-started/installation) — HIGH. Confirms `composer require nativephp/desktop` + `php artisan native:install` + PHP 8.3+ / Laravel 11+ / Node 22+.
- [NativePHP v2 queues docs](https://nativephp.com/docs/desktop/2/digging-deeper/queues) — HIGH. Confirms SQLite-backed `QueueWorker` only, no Redis/Horizon support documented.
- [NativePHP v2 building/code-signing docs](https://nativephp.com/docs/desktop/2/publishing/building) — HIGH. Confirms Apple notarytool env vars + Azure Trusted Signing support.
- [NativePHP v2 auto-update docs](https://nativephp.com/docs/desktop/2/publishing/updating) — HIGH. Confirms `github` / `s3` / `spaces` providers + `AutoUpdater` facade.
- [nativephp/php-bin on Packagist](https://packagist.org/packages/nativephp/php-bin) — HIGH. 1.1.1 (2025-09-02), weekly auto-build cadence.
- [nativephp/php-bin php-extensions.txt](https://github.com/NativePHP/php-bin/blob/main/php-extensions.txt) — HIGH (source of truth for bundled extensions).

**Laravel core + auth**:
- [Laravel 13 release notes](https://laravel.com/docs/13.x/releases) — HIGH. PHP 8.3 min, 8.5 max; released 2026-03-17.
- [laravel/fortify on Packagist](https://packagist.org/packages/laravel/fortify) — HIGH. 1.37.2 (2026-05-15), Laravel 13 support.
- [laravel/horizon on Packagist](https://packagist.org/packages/laravel/horizon) — HIGH. 5.46.0 (2026-04-20), Laravel 13 support.
- [laravel/pulse on Packagist](https://packagist.org/packages/laravel/pulse) — HIGH. 1.7.3 (2026-03-26), Laravel 13 support.
- [laravel/telescope on Packagist](https://packagist.org/packages/laravel/telescope) — HIGH. 5.20.0 (2026-04-06).

**Code signing / CI**:
- [apple-actions/import-codesign-certs releases](https://github.com/apple-actions/import-codesign-certs/releases) — HIGH. v7.0.0 (2026-04-21).
- [Azure/trusted-signing-action README](https://github.com/Azure/trusted-signing-action) — HIGH. v2.0.0 (2026-05-14). Confirms Windows-runner-only + DefaultAzureCredential auth.
- [electron-builder code-signing docs](https://www.electron.build/code-signing.html) — HIGH. Confirms macOS + Windows + Linux signing matrix.
- [electron-builder auto-update docs](https://www.electron.build/auto-update.html) — HIGH. Confirms GitHub Releases / S3 / generic provider support + Squirrel.Windows not supported.

**Misc / rejected packages (verified to dismiss)**:
- [cleaniquecoders/laravel-artisan-runner on Packagist](https://packagist.org/packages/cleaniquecoders/laravel-artisan-runner) — HIGH. 1.2.1 (2026-03-30). 1 GitHub star at time of research. Rejected as too new for destructive-command surface.
- [spatie/laravel-activitylog on Packagist](https://packagist.org/packages/spatie/laravel-activitylog) — HIGH. 4.12.3 (2026-03-24) supports Laravel 13 on PHP 8.1; 5.0 (2026-03-25) requires PHP 8.4.
- [spatie/laravel-backup on Packagist](https://packagist.org/packages/spatie/laravel-backup) — HIGH. 10.2.1 (2026-03-24). Skipped — SQLite already covered by Phase 11.

---

*Stack research for diederik v2.0 — desktop packaging + multi-user + dev mode + CI/CD + public release*
*Researched: 2026-05-19 — every version verified the same day*
