# Stack Research

**Domain:** Local-only personal finance / transaction reconciliation tool (PHP/Laravel, single-user, macOS)
**Researched:** 2026-05-12
**Confidence:** HIGH (every library version below verified directly against Packagist or official docs in May 2026)

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP | 8.3.x (LTS-style) | Language runtime | Laravel 12 requires PHP 8.2+ and supports 8.2–8.5. PHP 8.3 is the sweet spot: typed class constants, readonly classes, `json_validate()`, mature on macOS Herd, and avoids the PHP 8.4 IMAP-extension fallout (see PITFALLS.md). Pin to 8.3 so the `ext-imap` removal in 8.4 is a deliberate future migration, not a surprise. |
| Laravel | 12.x | Web framework | Released Feb 24, 2025; bug-fix support to Aug 2026, security to Feb 2027. Officially packaged Inertia 2 + shadcn/ui + Tailwind starter kits and a Livewire/Volt starter kit ship out of the box — exactly the calm-dashboard primitives this project wants. |
| SQLite | 3.45+ (whatever Herd ships) | Local data store | Single file on disk, zero setup, perfect for a single-machine, single-user app. Laravel 11+ made SQLite the default driver. Enable WAL mode (`PRAGMA journal_mode=WAL`) to allow background IMAP/queue workers to read while the web request writes. Single-writer is fine for one human. |
| Laravel Herd | latest (free tier) | Local dev environment | Native macOS app, zero Homebrew dependency, pre-compiled PHP + nginx + dnsmasq, ships `*.test` HTTPS by default, painless PHP version switching, launches an app at `https://diederik.test` that the user can pin as a desktop bookmark. The free tier is sufficient — Pro features (mail catcher, log viewer, MySQL/Redis services) are not needed for a SQLite-only app. |
| Tailwind CSS | 4.x | Styling | Bundled in Laravel 12 starter kits; oxide-engine (Rust-based) build is fast; v4's CSS-first config matches the "calm, content-first" aesthetic without a heavy design system. |

### Frontend Stack Decision (this is the single biggest call)

**Recommendation: Livewire 4 + Volt + Flux UI (the Laravel 12 "Livewire Starter Kit")**

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Livewire | 4.x | Reactive UI without SPA | Livewire 4 shipped Jan 2026 with single-file components (SFC), batched requests, `wire:transition`, and a much faster diffing engine. For a solo PHP developer building a dashboard that is mostly tables + forms + a couple of charts, Livewire keeps everything in PHP/Blade — no Vite component graph, no TypeScript build, no Inertia adapter layer. |
| Volt | 1.x (ships with Livewire 4 starter) | Functional Livewire syntax | Lets each page live as one `.blade.php` file with inline PHP class — perfect for the project's "vertical MVP per phase, not a six-month architecture exercise" constraint. |
| Flux UI | latest (Livewire-native component library, ships with starter) | Headless components | Official Livewire team components: data tables, modals, dropdowns, charts wrappers. Matches the Linear/Notion aesthetic out of the box with sensible defaults. |
| Alpine.js | bundled | Sprinkle interactivity | Comes with Livewire; used for purely-client widgets (collapse, popover) without a roundtrip. |

**Why NOT Inertia + React/Vue (the other obvious choice):**
- Adds a build pipeline, a Node toolchain, a typed component layer, and a serialization protocol to learn — all for a single-user dashboard.
- Inertia shines when the UI is genuinely SPA-shaped (drag-and-drop editors, complex client state). A finance dashboard is form-heavy and table-heavy, where Livewire is faster to ship.
- If a specific page later needs heavier client interactivity (e.g. an interactive cash-flow simulator), Livewire 4's "island mode" + Vue/React bridges let you escape-hatch on a per-component basis without a full stack rewrite.

**Why NOT Blade + HTMX:**
- HTMX is great, but there is no first-class Laravel integration, no community of Laravel devs to crib patterns from, and you lose Livewire's component model. Livewire is essentially HTMX-for-Laravel with batteries.

**Why NOT Filament v5 (admin-panel framework):**
- Filament v5 (released Jan 16, 2026, requires Livewire 4) is the obvious "I want a dashboard fast" answer and there are even public tutorials for personal-finance dashboards built on it. **Strongly consider it if the user wants table-driven CRUD with minimal custom UI.** It's built on the same Livewire + Tailwind stack so the underlying tech is identical.
- Reason to hesitate: the project brief explicitly asks for a "calm, content-first" Linear/Notion aesthetic. Filament's default look is an admin panel — dense, sidebar-heavy, table-first. Reskinning Filament to look like Linear is more work than building a few custom Livewire pages on the Livewire starter kit. Recommend Livewire + Volt + Flux first; revisit Filament only if custom UI velocity becomes a blocker.

Confidence: HIGH on Livewire+Volt+Flux as the recommendation. MEDIUM on "don't use Filament" — this is an aesthetic judgement the user can flip.

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| **genkgo/camt** | ^2.10 (2.10.3, Aug 2025) | CAMT.052/053/054 ISO20022 parser | **Primary bank-statement parser.** Actively maintained, 1.2M installs, requires PHP 8.1+. Handles all CAMT.053 sub-versions ASN exports (001.02, 001.03, 001.08). Already depends on `moneyphp/money`, which informs the currency choice below. |
| **kingsquare/php-mt940** | ^2.0 (only the 2.0.0 release, Nov 2020) | MT940 (legacy SWIFT) parser | **Use only if ASN's MT940 export is required.** Ships parsers for ASN, ING, Rabobank, Triodos, SNS, Bunq out of the box. ⚠ Codebase last updated 2020 — Packagist auto-updates only the index. 821K installs, 106 stars, only 14 open issues — stable but stagnant. Prefer CAMT.053 from ASN when both are available; keep MT940 as a fallback. |
| **league/csv** | ^9.28 (Dec 2025) | CSV reader/writer | All CSV ingestion paths (ASN CSV, ICS CSV, PayPal CSV, ICS Excel-converted CSV). Memory-efficient streaming reader, header mapping, character-set conversion (PayPal CSV is UTF-8 with BOM; some ICS exports are Windows-1252). Far superior to PHP's native `fgetcsv()`. 173M installs. |
| **phpoffice/phpspreadsheet** | ^3.x | Excel (.xlsx) parser | Only if ICS Cards export is `.xlsx` and the user prefers it over their CSV export. Otherwise skip — adds significant memory footprint. |
| **webklex/laravel-imap** | ^6.2 (Apr 2025) | IMAP integration | Wraps `webklex/php-imap`, which speaks IMAP **natively in PHP — does not require the `ext-imap` extension**. This is the critical reason to pick this library: PHP 8.4 unbundled `ext-imap` (it now lives in PECL, based on a 20-year-unmaintained c-client). Webklex sidesteps that entirely. Supports IDLE, OAuth (for Gmail later), incremental sync via `->since($date)`, chunked fetching, and a Laravel facade. 4.5M installs. |
| **brick/money** | ^0.13 (Mar 2026) | Multi-currency arithmetic | Immutable Money objects, exact arithmetic via brick/math (no BCMath required), explicit rounding, currency conversion via pluggable rate providers, MoneyBag for multi-currency totals. PHP 8.2+. 39.6M installs, actively developed. **Note:** Because `genkgo/camt` depends on `moneyphp/money`, you will have both in the lock file. Use brick/money in your own code (better arithmetic semantics) and convert at the CAMT boundary. |
| **nesbot/carbon** | ^3.x (ships with Laravel 12) | Date/time | Built-in. No alternative needed. |
| **spatie/laravel-data** | ^4.x | Typed DTOs for ingestion | Wrap parsed CSV rows / CAMT entries / IMAP message metadata in immutable DTOs before they hit Eloquent. Cleaner than arrays, supports validation, makes the "many input formats → one canonical Transaction" funnel obvious. |
| **spatie/laravel-query-builder** | ^6.x | Filtered/sortable list endpoints | Powers the transaction-search UI cleanly. Optional but saves hand-rolled query scopes. |
| **laravel/scout** + **typesense-php** | optional | Full-text search over transactions | Only if the user wants merchant-name search across 5+ years of history. Defer to v2. |
| **filament/notifications** | optional | Pre-built toast/notification UI | Pull this single Filament package in even if you don't adopt the full panel — its in-app notifications layer is well-designed and works with plain Livewire. |

### Charting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| **ApexCharts** (via `asantibanez/livewire-charts` or direct Alpine binding) | ApexCharts 3.x | Cash-flow line chart, monthly bar charts, donut for category breakdown | Standard choice for Tailwind/Livewire dashboards in 2026. Renders SVG, nice animations, calm defaults, easy theming. Free + MIT. |
| **Chart.js** (alternative) | 4.x | Same as above | Smaller bundle than Apex, but less polished defaults. Filament's chart widget is built on Chart.js — useful to know if you adopt Filament later. |

**Recommendation:** ApexCharts. Confidence: MEDIUM (this is taste, both work fine).

### Queue / Scheduling / Background Workers

| Component | Choice | Why |
|-----------|--------|-----|
| Queue driver | `redis` | Required for `ShouldBeUniqueUntilProcessing` semantics around the chain-resolver job and for `/horizon` dashboard observability. Backed by a loopback-bound Docker `redis:7-alpine` container — see "Stack additions (Phase 5)". |
| Scheduler | Laravel scheduler via `php artisan schedule:work` (foreground) | The user wants no cron setup. `schedule:work` is a long-running daemon that fires the scheduler every minute — wrap in `launchd` (macOS native) for auto-start on login. |
| Worker | `php artisan horizon` | Horizon supervises Redis-backed queue workers and ships the `/horizon` dashboard. In dev runs manually in a second terminal; a launchd plist will wrap it in a later phase. |
| Horizon | **Yes** | Installed in the chain-resolution phase for queue observability via `/horizon` dashboard plus `ShouldBeUniqueUntilProcessing` lock semantics. Docker-bound Redis carve-out documented under "Stack additions (Phase 5)". |
| Reverb / Octane | **No** | Reverb (WebSockets) and Octane (long-lived workers) solve scale problems this project doesn't have. |
| IMAP background worker | Custom artisan command extending `Webklex\IMAP\Commands\ImapIdleCommand`, run via launchd, with a fallback `imap:scan --since=now-15min` cron-style fallback every 15 minutes | IDLE is "real-time" but flaky over long-lived TCP. The fallback ensures missed messages get picked up. |

Confidence: HIGH on Redis + Horizon + launchd. MEDIUM on the IDLE+fallback pattern — it's the right shape but the exact retry/backoff numbers will need tuning.

### Stack additions (Phase 5)

When the chain-resolution phase landed, the stack picked up two new Composer dependencies and one out-of-process service. The original "no Horizon, no Redis, no Docker" posture is preserved everywhere it can be — the carve-outs below are narrow and documented.

| Addition | Version | Posture | Rationale |
|----------|---------|---------|-----------|
| `laravel/horizon` | `^5.46` | recommended | Queue dashboard, supervisor, `ShouldBeUniqueUntilProcessing` enforcement against the per-user chain-resolver lock. |
| `predis/predis` | `^3.4` | recommended | Pure-PHP Redis client; mirrors the project's anti-PECL posture (`webklex/php-imap` over `ddeboer/imap`). |
| Docker Engine (Redis only) | any recent | recommended (carve-out) | One named container `diederik-redis` (`redis:7-alpine`), loopback-bound on `127.0.0.1:6379`. Named-volume persistence (no bind mounts) means the Sail-on-Mac bind-mount performance trap from the "What NOT to Use" table never applies. |

**Loopback-bind invariant.** The `docker run` invocation MUST pass `-p 127.0.0.1:6379:6379` (NOT the default `-p 6379:6379` which binds `0.0.0.0`). The README "Setup" section commits to this form; `config/database.php` defaults to `127.0.0.1` for the same reason.

**Worker execution.** `php artisan horizon` is the canonical worker. In dev, it runs manually in a second terminal alongside `php artisan serve` / Herd. A `launchd` plist that auto-starts Horizon on login will ship in a later phase alongside the existing scheduler / queue-worker plist plan.

**Failed job storage.** Horizon's retry semantics require a `failed_jobs` table; the phase ships a migration that provisions it inside the existing SQLite store. Failed-job inspection happens through the `/horizon` dashboard, not through a Laravel-Notifications-style in-app drawer.

### Secrets / Credentials

| Need | Choice | Why |
|------|--------|-----|
| IMAP credentials, OAuth tokens, API keys | Encrypted file at `~/.diederik/config.enc` decrypted with a passphrase the user types once at app launch, OR plain `storage/app/secrets/imap.json` with `chmod 600` | The user explicitly requested "local config file, filesystem-permission protected." Keep it simple. **Don't** use `.env` for IMAP passwords — `.env` ends up in git diffs and editor recent-file lists. |
| Encryption key (`APP_KEY`) | `.env` (Laravel standard) | Standard Laravel. |
| Optional upgrade path: macOS Keychain | shell out to `security find-generic-password -w -s diederik-imap -a <account>` from PHP via `exec()` | Adds a 100ms cost per credential read but gives true OS-level secret storage. **Defer to v2**; chmod-600 JSON is fine for v1. |
| Database backups | SQLite file copy to an external location is the user's responsibility (they own the box) | Out of scope for the app. |

Confidence: HIGH on the file-with-chmod approach. LOW on Keychain — that's a "if you want to" path, not a recommended one.

### Testing

| Tool | Choice | Why |
|------|--------|-----|
| Test runner | **Pest 3.x** (built on PHPUnit 11) | Functional-style tests read better for a solo dev; community momentum is clearly Pest in 2026 (Spatie, Livewire, Filament all on Pest); the dataset feature is a perfect fit for "given this PayPal CSV row, expect this Transaction" table-driven tests. PHPUnit is the engine underneath so escape hatches exist. |
| Architecture tests | Pest's `arch()` plugin | Enforce "DTOs are immutable", "no Eloquent in parsers", "no Carbon in domain layer" rules without inventing custom static-analysis. |
| Snapshot tests | `spatie/pest-plugin-snapshots` | Excellent for "given this MT940 file, normalized output matches snapshot" — exactly the ingestion-parity tests this project needs. |
| Static analysis | **PHPStan level 8** with `larastan` extension | Catches Eloquent magic, mixed-type leaks. Critical for a money-handling app. |
| Code style | **Laravel Pint** (default Laravel preset) | Ships with Laravel, no config bikeshedding. |
| Browser tests | **Dusk** (only if a critical UI flow needs it) | Livewire tests cover most flows without a browser. Add Dusk only for the import-wizard happy path. |

Confidence: HIGH.

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| Laravel Herd (free) | PHP + nginx + dnsmasq + `*.test` HTTPS | Use the bundled PHP 8.3 binary. Don't install Homebrew PHP. |
| TablePlus / DBNGIN / `sqlite3` CLI | SQLite GUI | DBNGIN is by the Herd team and free; TablePlus is the polished option. Either is fine. |
| Laravel Telescope | In-app request/query/job inspector | Local-only debugging. Disable in any future prod build. |
| Laravel Debugbar | Per-page query/timer overlay | Same as Telescope, more inline. Pick one. |
| `php artisan tinker` | REPL for poking at ingestion results | Standard. |
| launchd (macOS) | Run `schedule:work`, `queue:work`, `imap:idle` on login | Avoids needing the user to `cd ~/code/diederik && php artisan ...` every morning. Plist files live in `~/Library/LaunchAgents/`. |

## Installation

```bash
# After installing Laravel Herd (https://herd.laravel.com) and dropping the project in ~/Herd/diederik:

# Create the project from the Livewire starter kit
laravel new diederik --using=laravel/livewire-starter-kit
cd diederik

# Core domain libraries
composer require \
    genkgo/camt \
    kingsquare/php-mt940 \
    league/csv \
    webklex/laravel-imap \
    brick/money \
    spatie/laravel-data

# Charts (Livewire wrapper around ApexCharts)
composer require asantibanez/livewire-charts

# Dev dependencies
composer require --dev \
    pestphp/pest \
    pestphp/pest-plugin-laravel \
    pestphp/pest-plugin-arch \
    spatie/pest-plugin-snapshots \
    larastan/larastan \
    laravel/pint

# Optional: enable WAL mode on SQLite
php artisan tinker
>>> DB::statement('PRAGMA journal_mode=WAL;')

# Configure queue + scheduler + IMAP worker via launchd
# (template plist files committed under deploy/launchd/)
```

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Livewire 4 + Volt + Flux | Filament v5 | If you accept Filament's admin-panel aesthetic and want CRUD + charts in literal hours. Trade flexibility for velocity. |
| Livewire 4 | Inertia 2 + React (Laravel starter kit) | If the user already knows React well and plans heavy client-side state (e.g. live what-if forecasting with thousands of points). |
| SQLite | PostgreSQL 16 | If multi-user partner sharing arrives sooner than planned — Postgres handles concurrent writes properly. Migration path: SQLite-to-Postgres is straightforward in Laravel via dump/load. |
| `database` queue driver | Redis + Horizon | If IMAP backfill of multi-year history becomes job-rate-limited. Unlikely for a single user. |
| brick/money | moneyphp/money | If you'd rather minimize transitive dependencies — genkgo/camt already pulls in moneyphp/money, so using it directly avoids having two money libraries. Acceptable trade-off; brick is still recommended for its better immutable API. |
| Pest | PHPUnit | If the user has strong existing PHPUnit muscle memory. Same engine, different surface. |
| Laravel Herd | Laravel Valet | If you need to install custom PHP extensions (Herd Pro hides that workflow behind a paywall, free Herd makes it awkward). For this project, no custom extensions are needed. |
| Laravel Herd | Laravel Sail (Docker) | Only on Linux, or if you want full containerization. Adds Docker daemon overhead, slower file I/O on macOS, more moving parts. Don't. |
| ApexCharts | Chart.js | If you adopt Filament later (Filament's chart widget is Chart.js-based). |
| Webklex/laravel-imap | ddeboer/imap | Don't — `ddeboer/imap` requires the native `ext-imap` extension, which was unbundled in PHP 8.4. Hard pass for any 2026 greenfield project. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| **Native PHP `ext-imap` extension** | Unbundled from PHP core in PHP 8.4 (moved to PECL). The underlying c-client library is **20 years unmaintained**. Even the PHP project itself recommends migrating. | `webklex/php-imap` — speaks IMAP in pure PHP, no native extension. |
| **`ddeboer/imap`** | Requires the deprecated `ext-imap` extension; PHP 8.4 will require manual PECL install. Maintained, but stack-poisoned. | `webklex/laravel-imap` |
| **PHP `fgetcsv()` directly** | Encoding handling is fragile (BOMs, Windows-1252 in ICS exports), header mapping is manual, no streaming abstraction. | `league/csv` |
| **Plain floats / cents-as-int for money** | Floating-point silently corrupts FX conversions; manual integer cents is OK for EUR-only but breaks when ICS settles a USD Google Play charge to EUR. The project explicitly requires multi-currency. | `brick/money` |
| **`abandoned/older sepa-xml libs` for parsing** | `digitick/sepa-xml`, `php-sepa-xml/php-sepa-xml` generate SEPA payment-initiation XML; they don't parse statements. | `genkgo/camt` for parsing CAMT.053 |
| **Laravel Sail (full Docker dev stack on macOS)** | Bind-mount file I/O on Docker Desktop for Mac is the well-known performance trap; you'll wait 2s for hot-reloads when PHP + DB + assets all run in containers. | Laravel Herd (native binaries). **Carve-out:** one network-only Redis container is acceptable (see "Stack additions (Phase 5)") because it persists via a named volume — no source-tree bind mount, so the trap does not apply. |
| **Laravel Jetstream / Breeze (legacy starter kits)** | Officially superseded by the Laravel 12 starter kits; no longer receiving updates. | Laravel 12 Livewire Starter Kit (Volt + Flux) |
| **kingsquare/php-mt940 as the *primary* ingestion path** | Codebase last touched in 2020 — works, but won't gain new bank engines. | Prefer CAMT.053 via genkgo/camt where ASN offers both; use MT940 as a fallback for older statements. |
| **Storing IMAP passwords in `.env`** | `.env` files leak into git history, editor "recent files," cloud sync clipboards. | A separate `storage/app/secrets/imap.json` with chmod 600, or macOS Keychain via `security` CLI. |
| **PHP 8.4 right now** | `ext-imap` removed; even though we use `webklex/php-imap`, some transitive deps (`ddeboer` style) might pull it in unexpectedly. Stay on 8.3 until the ecosystem catches up. | PHP 8.3.x |

## Stack Patterns by Variant

**If the user prefers Filament's velocity over custom UI:**
- Swap Livewire-starter-kit for `filament/filament` v5
- Keep every other choice (genkgo/camt, brick/money, webklex/laravel-imap, Pest, SQLite, Herd)
- Use Filament's chart widgets (Chart.js-based) instead of ApexCharts
- Accept admin-panel aesthetic until v2

**If the user decides to share with a partner sooner than planned:**
- Migrate SQLite → PostgreSQL 16 (Laravel makes this a one-config change plus a dump/load)
- Add Laravel's built-in `auth:scaffold` or upgrade to the WorkOS AuthKit variant of the starter kit
- Queue driver stays `database` until job rate forces Redis

**If IMAP scanning becomes slow (years of backfill):**
- Move queue driver `database` → `redis`
- Install Horizon for visibility
- Keep everything else

**If ICS only provides Excel and not CSV:**
- Add `phpoffice/phpspreadsheet`
- Otherwise skip — it's a heavy dependency.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| Laravel 12 | PHP 8.2 – 8.5 | Recommend 8.3 to dodge ext-imap PECL issues. |
| Filament v5 | Livewire 4 + Laravel 11/12 | Pinned tight; upgrades have a known cadence. |
| Livewire 4 | Laravel 11+, PHP 8.2+ | Major version jump from Livewire 3 introduced SFCs — read the migration guide if you ever start from Livewire 3 examples. |
| genkgo/camt 2.10 | PHP 8.1+, depends on `moneyphp/money` and `jschaedl/iban-validation` | Brings moneyphp into your lock file — coexists fine with brick/money. |
| webklex/laravel-imap 6.2 | PHP 8.0.2+, Laravel 6+ | Requires `ext-mbstring`. Does NOT require `ext-imap`. |
| kingsquare/php-mt940 2.0 | PHP 7+ (still works on 8.x) | Last released Nov 2020. Stable. Don't expect new features. |
| brick/money 0.13 | PHP 8.2+, brick/math ~0.15-0.17 | 0.x version numbering despite production-grade maturity (39M installs). |
| Pest 3 | PHPUnit 11, PHP 8.2+ | Drop-in for new projects; existing PHPUnit tests run as-is. |

## Sources

- [Laravel 12 Release Notes (laravel.com)](https://laravel.com/docs/12.x/releases) — HIGH (official). Verified PHP 8.2 minimum, starter kits, release date.
- [PHP 8.4 IMAP Unbundled (php.watch)](https://php.watch/versions/8.4/imap-unbundled) — HIGH. Confirms ext-imap moved to PECL in PHP 8.4.
- [Webklex/laravel-imap on Packagist](https://packagist.org/packages/webklex/laravel-imap) — HIGH. 6.2.0 (Apr 25, 2025), 4.5M installs, PHP ^8.0.2.
- [Webklex/php-imap on Packagist](https://packagist.org/packages/webklex/php-imap) — HIGH. 6.2.0 (Apr 25, 2025), native PHP IMAP wrapper, no ext-imap required, IDLE + OAuth support.
- [genkgo/camt on Packagist](https://packagist.org/packages/genkgo/camt) — HIGH. 2.10.3 (Aug 26, 2025), 1.2M installs, supports CAMT.052/053/054, requires PHP 8.1+.
- [kingsquare/php-mt940 on Packagist](https://packagist.org/packages/kingsquare/php-mt940) — HIGH. Latest release 2.0.0 (Nov 5, 2020), 821K installs, 106 stars. Stable but stagnant; OK as fallback.
- [brick/money on Packagist](https://packagist.org/packages/brick/money) — HIGH. 0.13.0 (Mar 28, 2026), 39.6M installs, PHP 8.2+.
- [league/csv on Packagist](https://packagist.org/packages/league/csv) — HIGH. 9.28.0 (Dec 27, 2025), 173M installs, PHP 8.1.2+.
- [Livewire 4 release announcement (laravel.com)](https://laravel.com/blog/livewire-4-is-here-the-artisan-of-the-day-is-caleb-porzio) — HIGH (official). Confirms Jan 2026 release, SFC, batched requests, wire:transition.
- [Filament v5 / Personal Finance Dashboard tutorials (dev.to)](https://dev.to/maiobarbero/setting-up-laravel-and-filament-for-personal-finance-5om) — MEDIUM. Demonstrates Filament v5 used for the same domain.
- [Laravel Herd Pricing & Features (herd.laravel.com)](https://herd.laravel.com/) — HIGH (official). Free tier confirmed sufficient; Pro features not needed for this stack.
- [Pest documentation (pestphp.com)](https://pestphp.com/) — HIGH (official). Community-default for new Laravel projects in 2026.
- [Laravel Queues 12.x (laravel.com)](https://laravel.com/docs/12.x/queues) — HIGH (official). Database driver suitability for low-volume apps.
- [Using SQLite in production with Laravel (Laravel News)](https://laravel-news.com/using-sqlite-in-production-with-laravel) — MEDIUM. WAL mode guidance; single-writer caveats discussed.
- [macOS Keychain via `security` CLI (scriptingosx.com)](https://scriptingosx.com/2021/04/get-password-from-keychain-in-shell-scripts/) — MEDIUM. Documented pattern for shelling out from a CLI tool; PHP `exec()` makes this trivial.

---
*Stack research for: local-only personal finance / transaction reconciliation tool*
*Researched: 2026-05-12*
