<!-- GSD:project-start source:PROJECT.md -->
## Project

**diederik**

A local-only personal finance dashboard that pulls together transactions from ASN Bank, ICS Cards, PayPal, and Google Play into a single calm "this month at a glance" view. It resolves the routing chains between these accounts (PayPal → ASN or ICS, ICS → ASN via bulk iDEAL settlement) so that fixed monthly payments, real underlying funding sources, and upcoming cash flow are visible in one place instead of buried across statements.

**Core Value:** **Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.**

If everything else fails, the system must surface the complete picture of monthly fixed payments and the funding chain that connects them.

### Constraints

- **Tech stack**: PHP 8.5 + Laravel 13 (latest released March 2026) — User preference, mature ecosystem; pin to current versions to stay supported and avoid legacy deprecation cycles
- **Email integration**: Provider APIs only (Gmail API, Microsoft Graph) — Avoids any dependency on `ext-imap` (removed from PHP 8.4 core) and the IMAP library churn. iCloud Mail is explicitly out of scope
- **Modular architecture**: Code is organized into bounded modules via `nwidart/laravel-modules` — Enforces clean boundaries between Ingestion, Ledger, Categorization, Recurring, Chains, Forecasting, EmailScan, etc. Each module's cross-module surface is its `Public\` namespace (contracts, DTOs, events, services) **plus** its `Models\` namespace — Eloquent models are a deliberate shared read-seam other modules MAY use directly (see ADR 0002 and `.docs/architecture/module-boundaries.md`). Only the `Internal\` namespace is private and must never be imported from another module. Enforced by `App\PhpStan\Rules\BoundaryRule` (`PUBLIC_PREFIXES = ['Public','Models']`) and the per-module `Internal`-boundary arch rules in `tests/Contracts/BoundaryArchTest.php`
- **Code quality gates (CI-enforced)**: Larastan at level 10 (max) with strict mode + Laravel Pint formatting + Pest unit/feature tests — Every PR must pass all three before merge. No frontend tests are required (the UI is server-rendered + thin; investment goes into backend correctness)
- **Project slicing**: Vertical MVP per phase — Each phase ends with an end-to-end demoable capability, not an isolated layer. Phase 1 must produce a working "see my ASN month" experience before Phase 2 begins
- **Hosting**: Local only (localhost) — Privacy requirement; financial data must never leave the machine
- **Idempotency**: All ingestion paths (CSV upload, IMAP scan, .eml import) must be safe to re-run — Same source + same transaction must never duplicate
- **History**: Full history retained forever — Long-term subscription-drift analysis requires it; pruning is a non-goal
- **Multi-user readiness**: Single-user v1 but schema must permit a second user later without migration pain — User intends to share with a partner once the product is proven
- **Currency**: Multi-currency tracking required from v1 — Google Play (USD) and some ICS merchants charge non-EUR; preserving both currencies prevents losing FX information that can't be recovered later
- **Secrets**: IMAP credentials live in a local config file, not the DB — Keeps secrets cleanly separable and out of any DB backups
<!-- GSD:project-end -->

<!-- GSD:stack-start source:research/STACK.md -->
## Technology Stack

## Recommended Stack
### Core Technologies
| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP | 8.3.x (LTS-style) | Language runtime | Laravel 12 requires PHP 8.2+ and supports 8.2–8.5. PHP 8.3 is the sweet spot: typed class constants, readonly classes, `json_validate()`, and avoids the PHP 8.4 IMAP-extension fallout (see PITFALLS.md). Pin to 8.3 so the `ext-imap` removal in 8.4 is a deliberate future migration, not a surprise. (Note: the project now runs PHP 8.5 in a Docker dev image; see the Development Tools section.) |
| Laravel | 12.x | Web framework | Released Feb 24, 2025; bug-fix support to Aug 2026, security to Feb 2027. Officially packaged Inertia 2 + shadcn/ui + Tailwind starter kits and a Livewire/Volt starter kit ship out of the box — exactly the calm-dashboard primitives this project wants. |
| SQLite | 3.45+ (bundled in the Docker dev image) | Local data store | Single file on disk, zero setup, perfect for a single-machine, single-user app. Laravel 11+ made SQLite the default driver. Enable WAL mode (`PRAGMA journal_mode=WAL`) to allow background IMAP/queue workers to read while the web request writes. Single-writer is fine for one human. |
| Docker (Compose) | latest | Local dev environment | The toolchain runs entirely in a Docker container (`docker-compose.yml` → `docker/php8.5/Dockerfile`); the host needs only Docker. The repo is bind-mounted so host edits are picked up immediately and `vendor/` written in the container lands back on the host. All Composer / Pest / Pint / PHPStan commands run via `docker compose run --rm php …`. No host PHP install is required. |
| Tailwind CSS | 4.x | Styling | Bundled in Laravel 12 starter kits; oxide-engine (Rust-based) build is fast; v4's CSS-first config matches the "calm, content-first" aesthetic without a heavy design system. |
### Frontend Stack Decision (this is the single biggest call)
| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Livewire | 4.x | Reactive UI without SPA | Livewire 4 shipped Jan 2026 with single-file components (SFC), batched requests, `wire:transition`, and a much faster diffing engine. For a solo PHP developer building a dashboard that is mostly tables + forms + a couple of charts, Livewire keeps everything in PHP/Blade — no Vite component graph, no TypeScript build, no Inertia adapter layer. |
| Volt | 1.x (ships with Livewire 4 starter) | Functional Livewire syntax | Lets each page live as one `.blade.php` file with inline PHP class — perfect for the project's "vertical MVP per phase, not a six-month architecture exercise" constraint. |
| Flux UI | latest (Livewire-native component library, ships with starter) | Headless components | Official Livewire team components: data tables, modals, dropdowns, charts wrappers. Matches the Linear/Notion aesthetic out of the box with sensible defaults. |
| Alpine.js | bundled | Sprinkle interactivity | Comes with Livewire; used for purely-client widgets (collapse, popover) without a roundtrip. |
- Adds a build pipeline, a Node toolchain, a typed component layer, and a serialization protocol to learn — all for a single-user dashboard.
- Inertia shines when the UI is genuinely SPA-shaped (drag-and-drop editors, complex client state). A finance dashboard is form-heavy and table-heavy, where Livewire is faster to ship.
- If a specific page later needs heavier client interactivity (e.g. an interactive cash-flow simulator), Livewire 4's "island mode" + Vue/React bridges let you escape-hatch on a per-component basis without a full stack rewrite.
- HTMX is great, but there is no first-class Laravel integration, no community of Laravel devs to crib patterns from, and you lose Livewire's component model. Livewire is essentially HTMX-for-Laravel with batteries.
- Filament v5 (released Jan 16, 2026, requires Livewire 4) is the obvious "I want a dashboard fast" answer and there are even public tutorials for personal-finance dashboards built on it. **Strongly consider it if the user wants table-driven CRUD with minimal custom UI.** It's built on the same Livewire + Tailwind stack so the underlying tech is identical.
- Reason to hesitate: the project brief explicitly asks for a "calm, content-first" Linear/Notion aesthetic. Filament's default look is an admin panel — dense, sidebar-heavy, table-first. Reskinning Filament to look like Linear is more work than building a few custom Livewire pages on the Livewire starter kit. Recommend Livewire + Volt + Flux first; revisit Filament only if custom UI velocity becomes a blocker.
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
### Queue / Scheduling / Background Workers
| Component | Choice | Why |
|-----------|--------|-----|
| Queue driver | `database` | One human, one machine, low job rate. Avoids Redis dependency entirely. SQLite handles a queue table fine in WAL mode. Promote to `redis` only if IMAP backfill jobs ever bottleneck. |
| Scheduler | Laravel scheduler via `php artisan schedule:work` (foreground) | The user wants no cron setup. `schedule:work` is a long-running daemon that fires the scheduler every minute — wrap in `launchd` (macOS native) for auto-start on login. |
| Worker | `php artisan queue:work --tries=3 --backoff=60` | Standard. Wrap in launchd alongside the scheduler. |
| Horizon | **No** | Horizon needs Redis. Not worth the dependency for a single-user app. The `database` driver + a Filament-notifications-style minimal "failed jobs" view in the app is enough. |
| Reverb / Octane | **No** | Reverb (WebSockets) and Octane (long-lived workers) solve scale problems this project doesn't have. |
| IMAP background worker | Custom artisan command extending `Webklex\IMAP\Commands\ImapIdleCommand`, run via launchd, with a fallback `imap:scan --since=now-15min` cron-style fallback every 15 minutes | IDLE is "real-time" but flaky over long-lived TCP. The fallback ensures missed messages get picked up. |
### Secrets / Credentials
| Need | Choice | Why |
|------|--------|-----|
| IMAP credentials, OAuth tokens, API keys | Encrypted file at `~/.diederik/config.enc` decrypted with a passphrase the user types once at app launch, OR plain `storage/app/secrets/imap.json` with `chmod 600` | The user explicitly requested "local config file, filesystem-permission protected." Keep it simple. **Don't** use `.env` for IMAP passwords — `.env` ends up in git diffs and editor recent-file lists. |
| Encryption key (`APP_KEY`) | `.env` (Laravel standard) | Standard Laravel. |
| Optional upgrade path: macOS Keychain | shell out to `security find-generic-password -w -s diederik-imap -a <account>` from PHP via `exec()` | Adds a 100ms cost per credential read but gives true OS-level secret storage. **Defer to v2**; chmod-600 JSON is fine for v1. |
| Database backups | SQLite file copy to an external location is the user's responsibility (they own the box) | Out of scope for the app. |
### Testing
| Tool | Choice | Why |
|------|--------|-----|
| Test runner | **Pest 3.x** (built on PHPUnit 11) | Functional-style tests read better for a solo dev; community momentum is clearly Pest in 2026 (Spatie, Livewire, Filament all on Pest); the dataset feature is a perfect fit for "given this PayPal CSV row, expect this Transaction" table-driven tests. PHPUnit is the engine underneath so escape hatches exist. |
| Architecture tests | Pest's `arch()` plugin | Enforce "DTOs are immutable", "no Eloquent in parsers", "no Carbon in domain layer" rules without inventing custom static-analysis. |
| Snapshot tests | `spatie/pest-plugin-snapshots` | Excellent for "given this MT940 file, normalized output matches snapshot" — exactly the ingestion-parity tests this project needs. |
| Static analysis | **PHPStan level 8** with `larastan` extension | Catches Eloquent magic, mixed-type leaks. Critical for a money-handling app. |
| Code style | **Laravel Pint** (default Laravel preset) | Ships with Laravel, no config bikeshedding. |
| Browser tests | **Dusk** (only if a critical UI flow needs it) | Livewire tests cover most flows without a browser. Add Dusk only for the import-wizard happy path. |
### Development Tools
| Tool | Purpose | Notes |
|------|---------|-------|
| Docker + Docker Compose | Containerised PHP 8.5 toolchain | Canonical dev/test runtime. Run everything through `docker compose run --rm php …`; no host PHP runtime is needed. |
| TablePlus / `sqlite3` CLI | SQLite GUI | TablePlus is the polished option; the `sqlite3` CLI inside the container works too. Either is fine. |
| Laravel Telescope | In-app request/query/job inspector | Local-only debugging. Disable in any future prod build. |
| Laravel Debugbar | Per-page query/timer overlay | Same as Telescope, more inline. Pick one. |
| `php artisan tinker` | REPL for poking at ingestion results | Standard. |
| launchd (macOS) | Run `schedule:work`, `queue:work`, `imap:idle` on login | Avoids needing the user to `cd ~/code/diederik && php artisan ...` every morning. Plist files live in `~/Library/LaunchAgents/`. |
## Installation
# With Docker installed, from the project root (toolchain runs in the container — no host PHP needed):
#   docker compose build
#   docker compose run --rm php composer install
# Create the project from the Livewire starter kit
# Core domain libraries
# Charts (Livewire wrapper around ApexCharts)
# Dev dependencies
# Optional: enable WAL mode on SQLite
# Configure queue + scheduler + IMAP worker via launchd
# (template plist files committed under deploy/launchd/)
## Alternatives Considered
| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Livewire 4 + Volt + Flux | Filament v5 | If you accept Filament's admin-panel aesthetic and want CRUD + charts in literal hours. Trade flexibility for velocity. |
| Livewire 4 | Inertia 2 + React (Laravel starter kit) | If the user already knows React well and plans heavy client-side state (e.g. live what-if forecasting with thousands of points). |
| SQLite | PostgreSQL 16 | If multi-user partner sharing arrives sooner than planned — Postgres handles concurrent writes properly. Migration path: SQLite-to-Postgres is straightforward in Laravel via dump/load. |
| `database` queue driver | Redis + Horizon | If IMAP backfill of multi-year history becomes job-rate-limited. Unlikely for a single user. |
| brick/money | moneyphp/money | If you'd rather minimize transitive dependencies — genkgo/camt already pulls in moneyphp/money, so using it directly avoids having two money libraries. Acceptable trade-off; brick is still recommended for its better immutable API. |
| Pest | PHPUnit | If the user has strong existing PHPUnit muscle memory. Same engine, different surface. |
| Docker (Compose) | A host PHP install | Don't. The project standardises on the containerised toolchain so PHP version + extensions are identical across machines and CI. A host PHP install is explicitly out of scope. |
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
| **Laravel Horizon** for this project | Pulls in Redis. For one user, the cost/benefit is negative. | `database` queue driver, plain `queue:work` daemon |
| **A host PHP install** | Drifts from the PHP 8.5 version + extension set used in CI; "works on my machine" bugs follow. | The committed Docker Compose toolchain (`docker compose run --rm php …`) |
| **Laravel Jetstream / Breeze (legacy starter kits)** | Officially superseded by the Laravel 12 starter kits; no longer receiving updates. | Laravel 12 Livewire Starter Kit (Volt + Flux) |
| **kingsquare/php-mt940 as the *primary* ingestion path** | Codebase last touched in 2020 — works, but won't gain new bank engines. | Prefer CAMT.053 via genkgo/camt where ASN offers both; use MT940 as a fallback for older statements. |
| **Storing IMAP passwords in `.env`** | `.env` files leak into git history, editor "recent files," cloud sync clipboards. | A separate `storage/app/secrets/imap.json` with chmod 600, or macOS Keychain via `security` CLI. |
| **PHP 8.4 right now** | `ext-imap` removed; even though we use `webklex/php-imap`, some transitive deps (`ddeboer` style) might pull it in unexpectedly. Stay on 8.3 until the ecosystem catches up. | PHP 8.3.x |
## Stack Patterns by Variant
- Swap Livewire-starter-kit for `filament/filament` v5
- Keep every other choice (genkgo/camt, brick/money, webklex/laravel-imap, Pest, SQLite, Docker)
- Use Filament's chart widgets (Chart.js-based) instead of ApexCharts
- Accept admin-panel aesthetic until v2
- Migrate SQLite → PostgreSQL 16 (Laravel makes this a one-config change plus a dump/load)
- Add Laravel's built-in `auth:scaffold` or upgrade to the WorkOS AuthKit variant of the starter kit
- Queue driver stays `database` until job rate forces Redis
- Move queue driver `database` → `redis`
- Install Horizon for visibility
- Keep everything else
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
- [Pest documentation (pestphp.com)](https://pestphp.com/) — HIGH (official). Community-default for new Laravel projects in 2026.
- [Laravel Queues 12.x (laravel.com)](https://laravel.com/docs/12.x/queues) — HIGH (official). Database driver suitability for low-volume apps.
- [Using SQLite in production with Laravel (Laravel News)](https://laravel-news.com/using-sqlite-in-production-with-laravel) — MEDIUM. WAL mode guidance; single-writer caveats discussed.
- [macOS Keychain via `security` CLI (scriptingosx.com)](https://scriptingosx.com/2021/04/get-password-from-keychain-in-shell-scripts/) — MEDIUM. Documented pattern for shelling out from a CLI tool; PHP `exec()` makes this trivial.
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

Conventions not yet established. Will populate as patterns emerge during development.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

Architecture not yet mapped. Follow existing patterns found in the codebase.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

- **Sketch findings for beatrax** (design decisions, CSS patterns, visual direction from sketch experiments) → `Skill("sketch-findings-beatrax")`. Auto-load when implementing Phase 16+ UI surfaces, adding new authenticated app pages, extending the ⌘K palette / Dev Console, building any wizard / onboarding / setup flow, touching the import preview table or `/triage` row, building any settings section that exposes a corpus toggle, OR building any Phase 17 counterparty surface (`/counterparties` index, `/counterparties/{slug}` profile, `/counterparties/triage` queue, type-chip / type-color language).
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
