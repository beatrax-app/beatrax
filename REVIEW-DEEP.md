# Deep Modules Review

**Generated:** 2026-05-28
**Scope:** Last quality-gate sweep before public release — all 18 production modules audited for boundary hygiene, DI compliance, dead code, and performance smells.
**Status:** REPORT PHASE — findings recorded; cleanup edits await orchestrator triage before they land.

This document is the per-module deep audit produced by Plan 17-11. The audit walks each module across four dimensions, records every finding with a severity tag and a recommended fix, and runs `composer-require-checker` against the full codebase. Cleanup commits will follow once the orchestrator triages this report.

## Audit categories

| Severity    | Meaning                                                                                                                                                                                                                                                                  |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **BLOCKER** | Must be fixed before the public v1.0.0 ship. Either a correctness issue, a security issue, or a hygiene issue that erodes contributor confidence on day 1 of the public repo (e.g. a known false-clean signal from CI, a missing dependency declaration, a leaky boundary). |
| **WARNING** | Should be fixed. Quality issue that does not block the ship but materially degrades the codebase if left. Defer to v1.1 ONLY if fixing it would expand the plan's scope beyond closeout intent. Per the user's `feedback_fix_all_severities` rule: address everything together. |
| **INFO**    | Noted observation. Either intentional design that warrants documentation, a stylistic note, or a "could be cleaner" comment that does not require action. INFO entries are recorded so future contributors can see what was reviewed but consciously left alone.          |

The auditor counts 18 modules under `Modules/` (the plan said 17; the additional one is `Transfers/`, which split out from Ledger during Phase 3 follow-up). All 18 are audited.

## composer-require-checker outcome

### Tool installed

`maglnet/composer-require-checker:^4` added to `require-dev` (resolved version: `4.24.0`). No PHP version conflict; the project's `^8.4` constraint is compatible. No config file was needed — the default `data/config.dist.json` shipped with the tool covers our shape.

### Raw output (79 unknown symbols)

The default configuration emits 79 unknown symbols. Triage below splits them into four categories: **PHP extension symbols**, **Pest/PHPUnit test-API globals**, **transitive-but-canonical Laravel/Composer/PSR symbols** (whitelist candidates), and **genuine missing-direct-require** candidates that should either be promoted to `require` or have a `symbol-whitelist` entry with rationale.

#### Category A — PHP extension symbols (declare as `ext-*` in `require`)

| Symbol                                | Extension      | Disposition                                                                                                              |
| ------------------------------------- | -------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `ctype_digit`                         | `ext-ctype`    | Add `"ext-ctype": "*"` to `require`. Ships with PHP core but Composer recommends declaring it.                            |
| `FILTER_VALIDATE_URL`, `filter_var`   | `ext-filter`   | Add `"ext-filter": "*"`. Same rationale.                                                                                  |
| `iconv`                               | `ext-iconv`    | Add `"ext-iconv": "*"`. Used by ASN CSV charset conversion + PayPal CSV BOM stripping.                                    |
| `libxml_set_external_entity_loader`, `libxml_use_internal_errors` | `ext-libxml`   | Add `"ext-libxml": "*"`. Used by genkgo/camt XML parsing (we set the loader for XXE defence).                            |
| `mb_*` (7 calls)                      | `ext-mbstring` | Add `"ext-mbstring": "*"`. Already implicitly required by Laravel; should be explicit.                                    |
| `Normalizer`                          | `ext-intl`     | Add `"ext-intl": "*"`. Used by counterparty slug normalization.                                                           |
| `PDO`, `PDOException`                 | `ext-pdo`      | Add `"ext-pdo": "*"`. SQLite driver.                                                                                      |
| `posix_*` (3 calls)                   | `ext-posix`    | Used by DevMode log-tailer PID liveness check. Add `"ext-posix": "*"` — but note this excludes Windows. **See finding INFO-CR-01 below.** |
| `SIGKILL`, `SIGTERM`                  | `ext-pcntl`    | Used by DevMode log-tailer. Same Windows-portability caveat. **See INFO-CR-01.**                                          |
| `simplexml_load_string`               | `ext-simplexml`| Add `"ext-simplexml": "*"`. Used by camt parser.                                                                          |

#### Category B — Pest/PHPUnit test-API globals (whitelist with rationale)

| Symbol                                                                                                                                                                                                                                                                                                                                | Disposition                                                                                                                                                                                |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `it`, `test`, `expect`, `arch`, `dataset`, `uses`, `pest`, `beforeEach`, `afterEach`                                                                                                                                                                                                                                                  | Pest framework DSL — shipped by `pestphp/pest` (require-dev). The default require-checker only walks `require` (not `require-dev`), which is why these surface. Whitelist with rationale "Pest 4 DSL — pestphp/pest is in require-dev". |
| `PHPUnit\Framework\assertArrayHasKey`, `assertContains`, `assertGreaterThan`, `assertIsArray`, `assertIsList`, `assertLessThanOrEqual`                                                                                                                                                                                                | PHPUnit functional assertion API — shipped by `phpunit/phpunit` (transitive of `pestphp/pest`). Whitelist with rationale "PHPUnit 11 functional assertions — transitive of pestphp/pest".  |
| `Tests\TestCase`, `Tests\Helpers\RealSqliteFixture`, `makeCommunityTestUser`, `writeMt940Temp`, `injectPersistentTray`                                                                                                                                                                                                                | Project-local test helpers — autoloaded via `composer.json#autoload-dev` (PSR-4 `Tests\\`). Default scan doesn't see `autoload-dev`. Whitelist as a path-rooted block.                      |

#### Category C — Transitive-but-canonical (whitelist with rationale)

| Symbol                                              | Shipped by                                                                | Disposition                                                                                                                                                                                                                                                              |
| --------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `App\Models\User`                                   | Project-local — aliased to `Modules\Core\Models\User` at runtime by `CoreServiceProvider::register()` | Intentional Laravel-convention alias so `auth.providers.users.model` + Laravel notification routing resolve. Whitelist with rationale "Runtime class-alias to Modules\Core\Models\User — see CoreServiceProvider docblock". The User model exists; the namespace is bridged. |
| `Brick\Math\BigDecimal`, `Brick\Math\Exception\NumberFormatException`, `Brick\Math\RoundingMode` | `brick/math` (transitive of `brick/money`) | We use brick/math directly in money arithmetic. **FINDING WARN-CR-02: promote `brick/math` to direct `require`.**                                                                                                                                                          |
| `Carbon\*` (4 symbols)                              | `nesbot/carbon` (transitive of `laravel/framework`)                       | Carbon is the canonical Laravel date type. Whitelist with rationale "Carbon — direct API via laravel/framework". Optionally promote — Carbon may already be `require` directly via the framework's metapackage; treat as INFO if so.                                       |
| `Composer\InstalledVersions`                        | `composer-runtime-api`                                                    | Standard Composer runtime API. **FINDING WARN-CR-03: add `"composer-runtime-api": "^2.0"` to `require`.**                                                                                                                                                                  |
| `DB`                                                | Project-local — `Illuminate\Support\Facades\DB` (via Laravel's facade alias) | Surfaces because some module file imports `use DB;` (bare). **FINDING WARN-CR-04 below.**                                                                                                                                                                                  |
| `Google\Service\Gmail*` (3 symbols)                 | `google/apiclient-services` (transitive of `google/apiclient`)            | We already have `google/apiclient` in `require`. **FINDING WARN-CR-05: add `"google/apiclient-services": "*"` (or pin a version) as a direct require so it cannot drop silently on an upstream split.** |
| `GuzzleHttp\Client`, `GuzzleHttp\Exception\*`       | `guzzlehttp/guzzle` (transitive of multiple deps)                         | We use Guzzle directly in Microsoft Graph + Google clients. **FINDING WARN-CR-06: add `"guzzlehttp/guzzle": "^7.8"` to `require`.**                                                                                                                                        |
| `injectPersistentTray`                              | Project-local — `scripts/nativephp_inject_persistent_tray.php`           | Local script function reference. Whitelist as path-rooted.                                                                                                                                                                                                                |
| `Laravel\Horizon\Horizon`, `Laravel\Horizon\HorizonServiceProvider` | `laravel/horizon` (already in `require`)                | Horizon IS in `require`. The require-checker is flagging because the `App\Providers\HorizonServiceProvider` extends a guarded base — `bootstrap/providers.php` uses `class_exists()` to drop the provider when Horizon is absent. Whitelist with rationale "Optional production-only dependency; guarded by class_exists() in bootstrap/providers.php". |
| `Money\Money`                                       | `moneyphp/money` (transitive of `genkgo/camt`)                            | We touch `Money\Money` ONLY at the CAMT adapter boundary (per CLAUDE.md tech-stack note). **FINDING INFO-CR-07: confirmed the BoundaryArchTest already enforces "Money\Money stays inside Modules\Ingestion\Internal\Adapters\Asn". Whitelist with rationale "ASN CAMT adapter boundary only — boundary enforced by tests/Contracts/BoundaryArchTest.php".** |
| `Monolog\*` (5 symbols)                             | `monolog/monolog` (transitive of `laravel/framework`)                     | We extend Monolog directly in custom log channels. **FINDING WARN-CR-08: add `"monolog/monolog": "^3.0"` to `require`.**                                                                                                                                                   |
| `PhpParser\Node`, `PhpParser\Node\UseItem`          | `nikic/php-parser` (transitive of `larastan/larastan`)                    | Used only by the custom PHPStan boundary rule (`tests/Unit/PhpStanBoundaryRuleTest.php` and the rule it tests). This is a dev-time symbol. Whitelist with rationale "Custom PHPStan rule — nikic/php-parser is transitive of larastan/larastan (require-dev)". |
| `PHPStan\Analyser\Scope`, `PHPStan\Rules\Rule`, `PHPStan\Rules\RuleErrorBuilder` | `phpstan/phpstan` (transitive of `larastan/larastan`) | Same — custom PHPStan rule extension points. Whitelist with rationale "Custom PHPStan rule extends phpstan/phpstan APIs (require-dev transitive)".                                                                                                                       |
| `Psr\Log\AbstractLogger`, `Psr\Log\LoggerInterface` | `psr/log` (transitive of `monolog/monolog`)                               | Standard PSR-3 logger interface. **FINDING WARN-CR-09: add `"psr/log": "^3.0"` to `require`.**                                                                                                                                                                            |
| `Symfony\Component\HttpFoundation\Response`, `StreamedResponse` | `symfony/http-foundation` (transitive of `laravel/framework`) | Standard Laravel HTTP types. Whitelist with rationale "symfony/http-foundation — transitive of laravel/framework, direct API surface".                                                                                                                                  |
| `Symfony\Component\HttpKernel\Exception\*` (3 symbols) | `symfony/http-kernel` (transitive of `laravel/framework`)                 | Same — direct exception API. Whitelist with rationale "symfony/http-kernel — transitive of laravel/framework, direct exception API".                                                                                                                                       |
| `Symfony\Component\Process\Process`                 | `symfony/process` (already in `require-dev`)                              | **FINDING WARN-CR-10: `symfony/process` is in `require-dev` but is used in production code (e.g. DevMode log-tailer). Promote to `require`.**                                                                                                                              |

#### Category D — `DB` bare imports (FINDING WARN-CR-04)

The `DB` bare-name finding above is a flag for a real cleanup opportunity. Let me grep to be precise — search shows zero offenders in production code (the references are all `use Illuminate\Support\Facades\DB;` aliased to `DB` which the require-checker resolves to the alias). This is a non-finding once the whitelist gets the Symfony+Illuminate canonical-facade rationale entry. Tracked as resolved during require-checker config.

### Summary of require-checker actions

| Action                                                                       | Count |
| ---------------------------------------------------------------------------- | ----- |
| Add to `require` (`ext-*`)                                                   | 10 ext entries (ctype, filter, iconv, libxml, mbstring, intl, pdo, posix, pcntl, simplexml) |
| Add to `require` (canonical transitive promoted to direct)                   | 6 (brick/math, composer-runtime-api, google/apiclient-services, guzzlehttp/guzzle, monolog/monolog, psr/log) |
| Promote from `require-dev` → `require`                                        | 1 (symfony/process)                                                                |
| Whitelist with rationale (Laravel/PSR/PhpStan ecosystem canonical surface) | ~12 symbol families                                                                |
| Whitelist with rationale (Pest DSL + autoload-dev test helpers)              | ~15 symbols                                                                        |
| Whitelist with rationale (intentional class-alias / Horizon guard)           | 3                                                                                  |

Cleanup commits land after orchestrator triage. The `composer-require-checker.json` config file will live at repo root with whitelist entries grouped + commented.

## Module: Auth

**Files:** 64 (Public 6, Internal 18, tests 21, migrations 7).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| **WARNING (B-Auth-01)** | `Modules\Auth\Internal` namespace is NOT covered by `BoundaryArchTest` (no `arch('Modules\\Auth\\Internal is only used inside Modules\\Auth')` rule). Auth has a Fortify provider + 6 Livewire pages + RecoveryCode generator under Internal; a future contributor could import them from a sibling module without an arch test catching it. Fix: add the standard Internal-isolation arch invariant to `tests/Contracts/BoundaryArchTest.php`. |
| INFO     | The Auth module legitimately uses the Auth facade and `auth()` helper across 8 Public Action classes + 2 Internal Fortify glue classes — these are on the documented allow-list in `BoundaryArchTest::noAuthFacadeOrHelper` with rationale. No action needed. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Auth\Internal\Http\Livewire\LoginPage` uses one global helper (`route()`) for redirect, which is the documented Livewire navigation idiom. Acceptable. |
| INFO     | No facade leakage outside the allow-list. `noLaravelFacadeUsageInModule` arch test passes. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | No dead public methods detected via spot-check. The 6 Public Actions (LoginAction, SignupAction, LogoutAction, ResetPasswordAction, RegenerateRecoveryCodesAction, AddUserAction) are all consumed by the Internal Livewire pages. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | RecoveryCode regeneration computes 10 hashes per call. Acceptable — invoked rarely (account setup, reset). |

## Module: Categorization

**Files:** 68 (Public 18, Internal 12, tests 19, migrations 4).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Categorization\Internal` is covered by BoundaryArchTest (3 references). |
| INFO     | `Modules\Categorization\Internal\Http\Livewire\RulesPage` and `CorrectionDivergenceToast` use Laravel global helpers in route closures; covered by the documented Routes/web.php carve-out. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No production facade usage outside allow-list. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | The Public surface (18 files) is wide because Categorization exposes RuleEvaluator + 6 cluster types + 4 Public Actions. All consumed by ImportPipeline + the RulesPage Livewire UI. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | Rule evaluation walks the rules table per transaction. Acceptable for the import-pipeline batch shape (one query per import-batch, not per transaction). |

## Module: Chains

**Files:** 76 (Public 16, Internal 12, tests 32, migrations 6).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Chains\Internal` covered by BoundaryArchTest (2 references). `noResolverWritesTransactions` and `noOtherCardStatementStateMutator` invariants both apply. |
| INFO     | `Modules\Chains\Providers\ChainsServiceProvider` uses one allowed facade (Route) inside service-provider registration — standard Laravel surface, implicit allow-list. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Chains has the highest test:source ratio of any module (32 tests for ~56 production files) — a good signal for a load-bearing routing module. No dead code spotted. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | `CardStatementStateMachine` does N queries per state transition, but transitions are bounded (the state graph has 8 nodes max per card_statement) and happen at chain-resolution time (batch job). Acceptable. |

## Module: Community

**Files:** 33 (Public 5, Internal 8, tests 10, migrations 1).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Community\Internal` covered by BoundaryArchTest (2 references). |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Smallest module by file count. No dead code; one migration covers the `community_settings` user-prefs column. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | None. |

## Module: Core

**Files:** 102 (Public 14, Internal 27, tests 38, migrations 10).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | Core is the cross-module substrate. `Modules\Core\Internal` covered by BoundaryArchTest (3 references). The `LockStore` facade exception is documented inline. The `noFacadeCallsFromCoreConsoleCommands` + `noLaravelGlobalHelpersInCoreConsoleCommands` invariants both pass. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| **WARNING (B-Core-01)** | `Modules\Core\Public\Support\LockStore` uses the `Cache` facade + `config()` helper. This is documented and intentional (queue-side `uniqueVia()` runs before constructor DI completes), with both the arch test allow-list and the phpstan.neon ignoreErrors list carving it out. **No fix needed** — but a Plan 17-12 (or v1.1) item could refactor by injecting the Cache repository through a static accessor pattern. Tracked as INFO-level; promoted to WARNING because it's the single facade-use in production code and the rationale should be visible in REVIEW-DEEP.md so future contributors see it documented in one place. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | 10 migrations span Phase 1 (`create_users_table`) through Phase 16 (`create_user_preferences_table`). All point to live columns; no superseded migrations. |
| INFO     | `Modules\Core\Internal\Providers\HealthCheckServiceProvider` exists — confirmed used by the `/health` route registered in Plan 17-04a. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | `SystemAlertQuery` is singleton-scoped (correct — it carries an in-process cache for the dashboard banner). |

## Module: Counterparties

**Files:** 14 (Public 3, Internal 1, tests 5, migrations 2).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| **BLOCKER (B-CP-01)** | `Modules\Counterparties\Internal` namespace is NOT covered by `BoundaryArchTest`. Counterparties shipped in Plans 17-06a / 17-06b / 17-06c and the standard `arch('Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties')` invariant was not added alongside the module. Fix: add the standard Internal-isolation arch invariant. Per the established pattern in BoundaryArchTest (every other Internal namespace has one), this is a coverage-gap blocker for the public release. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No facade leakage detected. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | The Public surface is minimal (CounterpartyResolver contract + CounterpartyResolved event + CounterpartyResolutionDto). All three are consumed inside the module by `CounterpartyResolverService`. Cross-module consumption was planned for D-47 (Ledger/Recurring/Chains read-only consumption) — this lands in 17-06c, which is in-flight; absence of cross-module imports today is not dead code, it is "not yet wired". |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | Profile-page rendering performance budget (D-48: ≤200ms for 10k transactions) — verify via the 17-06b smoke test outcome. Out of scope for this review. |

## Module: Desktop

**Files:** 48 (Public 4, Internal 21, tests 17, migrations 0).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | Desktop is the most facade-heavy module by design — NativePHP's API is facade-only and reaches outside container lifecycle. 9 BoundaryArchTest references explicitly carve out the 7 Desktop classes that touch `App`, `Window`, `Notification`, `System`, `MenuBar`. Allow-list rationale is inline in BoundaryArchTest. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | All allowed facade-using classes carry inline allow-list rationale. No new offenders. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Zero migrations (Desktop is stateless — close-behavior + theme + auto-import drop-folder are columns on `users`, owned by Core). Correct shape. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | OS-theme polling + window-focus events run on event-driven hooks, not polling loops. Good. |

## Module: DevMode

**Files:** 118 (Public 11, Internal 53, tests 36, migrations 1).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| **BLOCKER (B-DM-01)** | `Modules\DevMode\Internal` namespace is NOT covered by `BoundaryArchTest`. DevMode is the second-largest module by file count (118 files; 53 in Internal) and has zero boundary protection. A future contributor reaching into `Modules\DevMode\Internal\Queue\QueueActions` or `Modules\DevMode\Internal\Sql\ReadOnlySqliteConnection` from a sibling module would not be caught. Fix: add the standard Internal-isolation arch invariant. **Highest-priority blocker** because the surface is the largest unprotected one in the codebase. |
| **WARNING (B-DM-02)** | `Modules\DevMode\Resources\views\layouts\dev-shell.blade.php` uses the `config()` helper at Blade level. Per CLAUDE.md DI-only rule + `feedback_laravel_di_only.md`, Blade-level helpers should be avoided where possible. The view is dev-mode-only (never shipped to end users in production), so this is WARNING not BLOCKER. Fix: surface the config values from a Livewire/Volt component data array instead of inline `config()` calls. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| **WARNING (B-DM-03)** | `Modules\DevMode\Providers\DevModeServiceProvider`, `Internal\Http\Livewire\HorizonFramePage`, `Internal\Queue\QueueActions`, `Public\Models\JobBatch` all use Laravel global helpers (`base_path`, `config`, etc.) or facades (`DB`, `Cache`). These are dev-mode-only surfaces (never wired in production builds) so they don't violate the production DI invariant — but the established pattern across the codebase is constructor injection even for dev code. Fix: inject the relevant repositories/services through the container. Reasonable to defer to v1.1 if scope is tight — recommend converting at minimum the `JobBatch` Public model (which leaks through any cross-module DI consumption). |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | The 53 Internal classes are largely event-handlers + log-tailers + 4 Livewire pages (Doctor, Queue, Logs, Telescope). All confirmed wired through `DevModeServiceProvider` boot(). |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | Log tailer uses `posix_kill(0)` for PID liveness — portable PID check, good. (Recent commit `1808336` corrects this away from a posix-only dependency.) |

## Module: DriftAlerts

**Files:** 85 (Public 11, Internal 10, tests 52, migrations 2).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\DriftAlerts\Internal` covered by BoundaryArchTest (5 references). `noRecurringSeriesWritesFromDriftAlerts` + `noOtherDriftAlertStateMutator` + `noSynchronousDriftDetectionInRequestLifecycle` all apply. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | High test:source ratio (52:21) — strong signal of a well-tested analytical module. No dead code spotted. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | DriftEvaluator is explicitly not allowed in request-lifecycle paths (arch invariant `noSynchronousDriftDetectionInRequestLifecycle`). Correct shape. |

## Module: EmailScan

**Files:** 124 (Public 19, Internal 32, tests 55, migrations 5).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\EmailScan\Internal` covered by BoundaryArchTest (2 references). Plus `noTransactionWritesFromEmailScan` + `noOtherInboxScanStateMutator` + `noOtherBackfillProgressMutator` + `noOAuthTokensInEmailScanSchema` invariants. The OAuth secrets boundary is the most tightly enforced in the codebase. |
| INFO     | `Modules\EmailScan\Public\Services\InboxQuery` uses one Laravel facade (`Date`) — documented and acceptable. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No facade leakage outside the allow-list. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Largest module by file count after DevMode (124 files). The Public surface (19 files) is wide because EmailScan exposes the full provider-client + OAuth boundary; all confirmed consumed by Receipts + Onboarding. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | InboxScan uses `since($date)` incremental sync (per Webklex IDLE pattern). Correct shape. |

## Module: Forecasting

**Files:** 126 (Public 33, Internal 25, tests 41, migrations 6).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Forecasting\Internal` covered by BoundaryArchTest (5 references). The load-bearing `noScenarioMutationsJoinedToTransactionQueries` invariant (which walks the entire `Modules/` tree, not just Forecasting) is in place. Plus `noTransactionWritesFromForecasting` + `noSynchronousForecastingInRequestLifecycle`. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Largest Public surface in the codebase (33 files) because Forecasting exposes the scenario + projection + shortfall types broadly. Spot-check confirms all consumed by the dashboard + chain-of-events page. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | `ProjectionPipeline` is explicitly forbidden from the request lifecycle (arch invariant). Correct shape. |

## Module: Import

**Files:** 149 (Public 45, Internal 27, tests 59, migrations 3).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Import\Internal` covered by BoundaryArchTest (4 references). |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Largest module by file count overall (149 files). The Public surface (45 files) is the widest because Import exposes the full ImportPipeline + per-source ImportRun shapes + 8 stages + DTOs. All consumed by the Onboarding wizard + the /imports Livewire pages. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | ImportPipeline stages run per-row in a streaming fashion (league/csv reader). No N+1 risk on a single import. Cross-import history queries paginate (verified via the `/imports` page Livewire pagination wiring). |

## Module: Ingestion

**Files:** 79 (Public 19, Internal 27, tests 30, migrations 0).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Ingestion\Internal` covered by BoundaryArchTest (4 references). The `Money\Money` boundary (only inside `Modules\Ingestion\Internal\Adapters\Asn`) is explicitly enforced. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053Adapter` is the documented `Money\Money` boundary class — uses moneyphp/money at the parser boundary and converts to brick/money before crossing the module seam. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Zero migrations (Ingestion is a pure-parser module — no persisted state of its own). Correct shape. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | CAMT.053 parsing is streaming (genkgo/camt SAX-style). MT940 parsing is whole-file (kingsquare lib limitation; acceptable because MT940 files are small). |

## Module: Ledger

**Files:** 87 (Public 23, Internal 7, tests 27, migrations 17).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Ledger\Internal` covered by BoundaryArchTest (4 references). The `RederiveFingerprintsCommand` is explicitly forbidden from HTTP/routing namespaces. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Ledger\Internal\Http\Livewire\TransactionDetail` uses one global helper — documented Livewire idiom. No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| **WARNING (B-Led-01)** | 17 migrations on Ledger (highest count in the codebase). Two pairs look like consolidation candidates: `2026_05_13_010001_rederive_fingerprints_to_v3.php` + `2026_05_13_010004_replace_transactions_fingerprint_unique_index.php` are tightly coupled (same fingerprint-version reshape). `2026_05_27_000001_add_starting_balance_to_accounts_table.php` + `2026_05_27_000002_backfill_starting_balance_from_statement_summaries.php` are also paired (schema + backfill). **The "fresh-install runs all 17" migration cost is non-trivial.** Recommendation: do NOT consolidate — splitting schema-add from data-backfill is the correct Laravel pattern. Tracked as INFO. Promoting to WARNING here only to flag that the migration count is high and the project should adopt a "squash-on-major-version" practice at v2.0 (not v1.0). |
| INFO     | `Modules\Ledger\Internal\Console\RederiveFingerprintsCommand` is alive — referenced by the V3 fingerprint reshape migration. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | `transactions` table has the right composite indexes (`(user_id, value_date)`, `(user_id, account_id)`, `(user_id, fingerprint)` unique). Verified in migrations. The `recreate_transactions_type_triggers` migration shows the team has been actively tuning the trigger surface. |
| INFO     | `enriched_from`, `enriched_count`, `raw_payload`, `pair_transaction_id` columns added across multiple migrations — each is indexed where queried. |

## Module: Onboarding

**Files:** 67 (Public 2, Internal 13, tests 27, migrations 1).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Onboarding\Internal` covered by BoundaryArchTest (2 references). |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Very narrow Public surface (2 files — the OnboardingState contract + the OnboardingComplete event). Correct shape for a wizard module that doesn't broadcast its internals. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | None. |

## Module: Receipts

**Files:** 56 (Public 20, Internal 10, tests 19, migrations 3).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Receipts\Internal` covered by BoundaryArchTest (2 references). The Phase 7 invariant `noEmailFetchFromReceipts` explicitly forbids Receipts from importing EmailScan OAuth/client symbols. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Wide Public surface (20 files) because Receipts exposes the matcher pipeline + ParsedReceiptDto bridge + SourceTransactionDto adapters. All consumed by the import + chains modules. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | None. |

## Module: Recurring

**Files:** 88 (Public 20, Internal 13, tests 38, migrations 8).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Recurring\Internal` covered by BoundaryArchTest (6 references). `noTransactionWritesFromRecurring`, `noOtherRecurringSeriesStateMutator`, `noSynchronousDetectionInRequestLifecycle`, and the cross-module `crossModuleAccessGoesThroughPublic` all apply. |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | 8 migrations — second-highest after Ledger. All add columns or seed default settings; no superseded shapes. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | `lower_recurring_detection_window_default_to_two_months` migration tightens the detection window default — performance win. |

## Module: Transfers

**Files:** 11 (Public 2, Internal 2, tests 6, migrations 0).

### Boundary hygiene

| Severity | Finding |
| -------- | ------- |
| INFO     | `Modules\Transfers\Internal` covered by BoundaryArchTest (2 references). |

### DI compliance

| Severity | Finding |
| -------- | ------- |
| INFO     | No findings. |

### Dead code

| Severity | Finding |
| -------- | ------- |
| INFO     | Smallest module by file count (11 files). Zero migrations because Transfers reuses the Ledger `transactions.pair_transaction_id` column. Correct shape — module is a pure "pair-finder" service. |

### Perf smells

| Severity | Finding |
| -------- | ------- |
| INFO     | `TransferPairer` is queue-bounded (per `PairTransferCandidates` listener). Correct. |

## Triage of pre-existing test failures (from `deferred-items.md`)

The deferred-items.md file (logged during Plan 17-08) records the following pre-existing test failures that surfaced in the wave-1 baseline. Plan 17-11 is the natural place to triage them.

| # | Test file | Failing case(s) | Root cause | Recommended disposition |
| - | --------- | --------------- | ---------- | ----------------------- |
| 1 | `tests/Snapshot/SidebarTest.php` | "vite manifest not found at: public/build/manifest.json" | `npm run build` not executed in fresh worktree. Test renders a Blade view that calls `@vite(...)`. | **DEFER to CI** — Plan 17-04a's release.yml workflow runs `npm install && npm run build` before the test step. Resolution lands when CI runs, not in this plan. Tracked as INFO-T-01. The pre-existing condition is environmental, not a code defect. |
| 2 | `tests/Unit/PhpStanBoundaryRuleTest.php` | "emits a BoundaryRule error on the bad fixture" + "passes against empty module skeletons at level max" (2 of 3) | The test spawns `vendor/bin/phpstan` via Symfony Process; the child inherits `memory_limit = 128M` (php.ini default), which PHPStan exhausts before its own `--memory-limit=1G` CLI flag is parsed. | **FIX in this plan** as part of cleanup — Patch the test to spawn phpstan as `['php', '-d', 'memory_limit=2G', 'vendor/bin/phpstan', ...]` (per the existing deferred-items.md note). This is a 3-line test fix, no infrastructure change. Tracked as WARN-T-02. |

The orchestrator note mentions "6 pre-existing test failures" — `deferred-items.md` documents only 3 cases (1 in SidebarTest, 2 in PhpStanBoundaryRuleTest). A re-run on this baseline confirms 3 failures (not 6). If the orchestrator's "6" count came from a different baseline (e.g. including Modules/Auth, Modules/EmailScan integration suites that need fixtures), they were not present in the worktree snapshot at start of Plan 17-11. **Recommendation**: orchestrator clarifies the 6-count source before cleanup commits land; this report tracks the 3 that are reproducible.

## Cross-cutting findings (not module-specific)

| ID | Severity | Finding |
| -- | -------- | ------- |
| **X-01** | **WARNING** | `phpunit.xml` declares `Modules/DriftAlerts/tests/Unit` and `Modules/Auth/tests/Unit` directories inside BOTH the `Unit` testsuite AND dedicated `DriftAlerts` / `AuthUnit` testsuites. Result: per-run "Cannot add file ... as it was already added to test suite X" WARN noise on every pest run. Fix: either remove the per-module unit-directory entries from the catch-all `Unit` testsuite, or remove the dedicated suites (the dedicated suites also include Feature directories so they cover more than Unit — recommend keeping the dedicated suites and trimming the catch-all). Tracked as `X-01`. |
| **X-02** | **INFO**    | `composer outdated --direct` shows 6 packages have upstream patch/minor updates available (laravel/fortify 1.37.0→1.37.2, laravel/framework 13.11.2→13.12.0, laravel/horizon 5.47.0→5.47.1, microsoft/microsoft-graph 3.1.0→3.2.0, symfony/process 7.4.11→8.0.13, brick/money 0.11.2→0.13.0). None are security advisories per a quick scan. Disposition: do a `composer update` pass as part of v1.0.0 release prep (out of scope for this review — recommend dedicated `chore(17-closeout): refresh dependency lockfile` commit). |
| **X-03** | **INFO**    | `composer.json` requires `brick/money: ^0.11`; current available is `0.13`. Note that the project's CLAUDE.md tech-stack section actually suggests `^0.13`. The minor mismatch should be reconciled. Tracked under X-02. |

## Summary

| Severity    | Count | Status            |
| ----------- | ----- | ----------------- |
| **BLOCKER** | 2     | PENDING — Counterparties + DevMode missing Internal-isolation arch invariants. Fix: add 2 lines to `tests/Contracts/BoundaryArchTest.php`. |
| **WARNING** | 14    | PENDING TRIAGE — 10 composer-require-checker actions (add ext-* + canonical-transitive promotions), 1 PHPStan-spawn memory fix, 1 phpunit.xml duplicate-suite cleanup, 1 DevMode Blade `config()` cleanup, 1 DevMode DI cleanup, 1 Ledger migration squash-policy note. |
| **INFO**    | ~50   | NO ACTION — observations documented for future contributors. |

**Total fixed:** 0 (no cleanup edits applied yet — REVIEW PHASE only).
**Total deferred to v1.1:** 0 yet (orchestrator decides during triage).
**Total accepted:** ~50 INFO items.

### Recommended cleanup wave (orchestrator: triage + scope this)

1. **Wave 2a (BLOCKERs — mandatory):** Add `Modules\Counterparties\Internal` + `Modules\DevMode\Internal` arch invariants to `BoundaryArchTest`. ~5 minutes. (Resolves B-CP-01, B-DM-01.)
2. **Wave 2b (composer-require-checker config + edits — mandatory):** Land `composer-require-checker.json` with grouped whitelists + add 10 `ext-*` entries to `require` + promote 6 canonical transitives + promote `symfony/process` to `require`. Re-run until clean. ~30 minutes. (Resolves all WARN-CR-* + INFO-CR-* findings.)
3. **Wave 2c (phpunit.xml + PHPStan memory cleanup — mandatory):** Trim duplicate testsuite entries in phpunit.xml + patch PHPStanBoundaryRuleTest to spawn phpstan with `-d memory_limit=2G`. ~15 minutes. (Resolves X-01 + WARN-T-02.)
4. **Wave 2d (DevMode DI cleanup — optional, can defer to v1.1):** Refactor DevMode service provider + JobBatch model to use constructor DI instead of helpers/facades. ~30-60 minutes. (Resolves B-DM-02, B-DM-03.)

Estimated total cleanup time if all four waves land: ~80-110 minutes. Wave 2a + 2b + 2c are the must-haves for the public ship.

---

*Generated 2026-05-28 — Plan 17-11 Task 1 (REPORT phase).*
*Cleanup edits land in subsequent commits after orchestrator triage.*
