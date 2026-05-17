# Phase 9: Subscription Drift Detection + Alerts — Pattern Map

**Mapped:** 2026-05-17
**Files analyzed:** ~30 new files + 4 modify-in-place
**Analogs found:** 30 / 30 (every new file mirrors an existing Phase 5/6/7/8 analog)
**Read-only:** All analog access was via `Read`/`Grep` only; no source files modified.

---

## How to read this document

Each section below maps **one new (or modified) file** to its closest existing analog and shows the exact code excerpt the executor should reproduce. Conventions:

- "Analog" = closest existing file by role + data flow.
- "Match" = `exact` (same role + same data flow), `role-match` (same role, slightly different data flow), `partial` (different role, reusable pattern).
- File paths are absolute under the repo root.
- Line numbers reference the analog at the time of mapping.
- **GSD-agnostic invariant:** Excerpts strip any D-numbers / REQ-IDs before pasting into runtime code. Runtime PHPDocs describe what the code does, not why it was added.
- **DI-only invariant:** Every excerpt uses constructor DI (services / actions / queries / state machines / mappers) or method-parameter DI (Livewire `Component` subclasses, where constructor injection is banned by phpstan-strict-rules).

---

## File Classification

### New files in `Modules/DriftAlerts/`

| New File | Role | Data Flow | Closest Analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/DriftAlerts/composer.json` | module-config | n/a | `Modules/Recurring/composer.json` | exact |
| `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` | provider | wiring | `Modules/Recurring/Providers/RecurringServiceProvider.php` | exact |
| `Modules/DriftAlerts/Routes/web.php` | route file | request-response | `Modules/Recurring/Routes/web.php` | exact |
| `Modules/DriftAlerts/Database/Migrations/*_create_drift_alerts_table.php` | migration | DDL + trigger | `Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php` | exact |
| `Modules/DriftAlerts/Database/Migrations/*_create_drift_alert_transitions_table.php` | migration | DDL | `Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php` | exact |
| `Modules/DriftAlerts/Models/DriftAlert.php` | Eloquent model | row ↔ column map | `Modules/Recurring/Models/RecurringSeries.php` | role-match |
| `Modules/DriftAlerts/Models/DriftAlertTransition.php` | Eloquent model | row ↔ column map | `Modules/Recurring/Models/RecurringSeriesTransition.php` | exact |
| `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php` | state machine | CRUD + audit | `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` | exact |
| `Modules/DriftAlerts/Internal/StateMachines/InvalidStateTransitionException.php` | exception | n/a | `Modules/Recurring/Internal/StateMachines/InvalidStateTransitionException.php` | exact |
| `Modules/DriftAlerts/Internal/DriftEvaluator.php` | service | read + write (analytical) | `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` (compute-then-insert block, lines 330-402) | role-match |
| `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` | queued job | event-driven | `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` | exact |
| `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` | listener | event-driven | (none exists yet — closest precedent is Phase 5/6 listener shape; see § Shared Patterns) | partial |
| `Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php` | mapper | row → DTO | `Modules/Recurring/Internal/Mapping/RecurringSeriesDtoMapper.php` | exact |
| `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` | Livewire SFC | request-response | `Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php` | exact |
| `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php` | Livewire SFC | request-response | `Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php` | exact |
| `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php` | DTO | data carrier | `Modules/Recurring/Public/Dto/RecurringSeriesDto.php` | exact |
| `Modules/DriftAlerts/Public/Dto/CancellationImpactDto.php` | DTO | data carrier | `Modules/Recurring/Public/Dto/RecurringSeriesDto.php` (smaller subset) | role-match |
| `Modules/DriftAlerts/Public/Events/DriftAlertOpened.php` | event | pub-sub | `Modules/Recurring/Public/Events/RecurringSeriesCadenceFlipped.php` | exact |
| `Modules/DriftAlerts/Public/Events/DriftAlertAcknowledged.php` | event | pub-sub | `Modules/Recurring/Public/Events/RecurringSeriesApproved.php` | exact |
| `Modules/DriftAlerts/Public/Events/DriftAlertSnoozed.php` | event | pub-sub | `Modules/Recurring/Public/Events/RecurringSeriesApproved.php` | exact |
| `Modules/DriftAlerts/Public/Events/DriftAlertDismissedCancelled.php` | event | pub-sub | `Modules/Recurring/Public/Events/RecurringSeriesApproved.php` | exact |
| `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` | action | command | `Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` | exact |
| `Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php` | action | command | `Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php` | exact |
| `Modules/DriftAlerts/Public/Actions/DismissDriftAlertAsCancelled.php` | action | command | `Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` | exact |
| `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` | query service | read-only | `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` | exact |
| `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php` | query service | read-only | `Modules/Recurring/Public/Services/RecurringSeriesQuery::forSeries` (lines 91-104) | role-match |
| `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` | Blade view | rendering | `Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php` | exact |
| `Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php` | Blade view | rendering | `Modules/Recurring/Resources/views/livewire/fixed-payments-card.blade.php` | exact |
| `Modules/DriftAlerts/tests/TestCase.php` | test bootstrap | n/a | `Modules/Recurring/tests/TestCase.php` | exact |
| `Modules/DriftAlerts/tests/Pest.php` | test bootstrap (inert) | n/a | `Modules/Recurring/tests/Pest.php` | exact |
| `Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php` | unit test | n/a | `Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php` | role-match |
| `Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php` | feature test | n/a | `Modules/Recurring/tests/Feature/ApproveRecurringSeriesTest.php` | exact |
| `Modules/DriftAlerts/tests/Feature/SnoozeDriftAlertTest.php` | feature test | n/a | `Modules/Recurring/tests/Feature/SnoozeRecurringSeriesTest.php` | exact |
| `Modules/DriftAlerts/tests/Feature/DismissDriftAlertAsCancelledTest.php` | feature test | n/a | `Modules/Recurring/tests/Feature/RejectRecurringSeriesTest.php` | exact |
| `Modules/DriftAlerts/tests/Feature/DriftPageTest.php` | feature test | n/a | `Modules/Recurring/tests/Feature/RecurringReviewPageTest.php` | exact |
| `Modules/DriftAlerts/tests/Feature/DashboardDriftBadgeTest.php` | feature test | n/a | `Modules/Recurring/tests/Feature/FixedPaymentsCardTest.php` | exact |
| `Modules/DriftAlerts/tests/Feature/DriftAlertCrossUser404Test.php` | feature test | n/a | `Modules/Recurring/tests/Feature/CrossUserRecurringSeriesIsolationTest.php` | exact |
| `Modules/DriftAlerts/tests/Feature/TopNavDriftBadgeTest.php` | feature test | n/a | `Modules/Recurring/tests/Feature/TopNavBadgeComposerTest.php` | exact |
| `Modules/DriftAlerts/tests/fixtures/drift-corpus/*.php` | fixture | n/a | `Modules/Recurring/tests/fixtures/synthesised/drifting-monthly-spotify.php` | role-match |

### New files outside `Modules/DriftAlerts/`

| New File | Role | Data Flow | Closest Analog | Match |
|----------|------|-----------|----------------|-------|
| `Modules/Recurring/Database/Migrations/2026_05_19_010002_add_drift_threshold_percent_to_recurring_series.php` | migration | ALTER TABLE | `Modules/Recurring/Database/Migrations/2026_05_19_010001_add_cluster_counterparty_key_to_recurring_series.php` | exact |
| `Modules/Recurring/Database/Migrations/2026_05_19_010003_add_drift_alert_threshold_percent_to_users.php` | migration | ALTER TABLE on `users` | `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` | exact |
| `Modules/Recurring/Public/Events/RecurringSeriesMetricsRefreshed.php` | event | pub-sub | `Modules/Recurring/Public/Events/RecurringSeriesCadenceFlipped.php` | exact |
| `tests/Contracts/DriftDetectionContractTest.php` | contract test | n/a | `tests/Contracts/RecurringDetectionContractTest.php` | exact |

### Files modified (not created)

| File | Modification | Analog Pattern |
|------|--------------|----------------|
| `composer.json` (project root) | Add `Modules\\DriftAlerts\\` PSR-4 autoload + `Modules\\DriftAlerts\\Tests\\` autoload-dev | Mirrors the Recurring entries already present |
| `phpunit.xml` | Add a new testsuite pointing at `Modules/DriftAlerts/tests/` | Mirrors `Modules/Recurring/tests/` |
| `tests/Pest.php` | Add a new row to the per-module map: `'Modules/DriftAlerts' => Modules\DriftAlerts\Tests\TestCase::class` | See excerpt below |
| `tests/Contracts/BoundaryArchTest.php` | Append four (planner may decide five) new arch tests for the DriftAlerts module + extend `ignoring(...)` of the `Illuminate\\Support\\Facades` facade rule with `Modules\\DriftAlerts\\Internal\\Jobs\\DetectDriftAlertsJob` | See § Shared Patterns / BoundaryArchTest invariants |
| `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` | Add a single `$this->events->dispatch(new RecurringSeriesMetricsRefreshed(...))` call at the end of `refreshExistingSeries()` and at the end of `insertNewSeries()` | See excerpt below ("Detector insertion point") |
| `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php` | Same two-call-site addition as `ExpenseSeriesDetector` | Same pattern |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` | Add a `$driftAlertThresholdPercent` public property + a select option in the Blade view | Mirrors the `$recurringDetectionWindowMonths` / `$recurringIncomeMinAmountMinor` fields already on the page (lines 92-93, 127-128) |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` (or wherever the settings Blade lives) | New `<select>` row for `drift_alert_threshold_percent` | Mirrors the recurring detection-window select on the same page |
| `resources/views/dashboard.blade.php` | Add `@livewire('drift-alerts.dashboard-drift-badge')` tile | Mirrors `@livewire('recurring.fixed-payments-card')` |
| `resources/views/components/core/livewire/top-nav.blade.php` (or the existing top-nav partial) | Render the compound `[pending] [drift↗]` pill when `$driftOpenCount > 0` | See § Shared Patterns / Top-nav badge |
| `routes/console.php` | Add `Schedule::call(fn (DetectDriftAlertsJob $j) => ...)` or `Schedule::job(...)->name('drift-alerts.revive-snoozes')->hourly()` | Mirrors the existing `recurring.detect` schedule entry |

---

## Pattern Assignments

> Below: one section per file or file group, with the exact excerpt the executor should mirror.

---

### `Modules/DriftAlerts/composer.json`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/composer.json` (verbatim — lines 1-16)

**Excerpt to mirror:**

```json
{
    "name": "diederik/drift-alerts",
    "description": "Drift Alerts module — subscription drift detection, /drift page, cancellation-impact query.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\DriftAlerts\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\DriftAlerts\\Tests\\": "tests/"
        }
    }
}
```

Planner note: the project-root `composer.json` autoload-dev block must also gain `"Modules\\DriftAlerts\\Tests\\": "Modules/DriftAlerts/tests/"`. Mirrors the Recurring entry already present in the root.

---

### `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Providers/RecurringServiceProvider.php` (full file — lines 1-127)

**Excerpt to mirror — imports + singleton bindings + Livewire registration (lines 5-98):**

```php
namespace Modules\DriftAlerts\Providers;

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Internal\Http\Livewire\DashboardDriftBadge;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\DriftAlerts\Internal\Listeners\EvaluateDriftOnMetricsRefreshed;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

final class DriftAlertsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DriftAlertStateMachine::class);
        $this->app->singleton(DriftEvaluator::class);
        $this->app->singleton(DetectDriftAlertsJob::class);
        $this->app->singleton(DriftAlertQuery::class);
        $this->app->singleton(CancellationImpactQuery::class);
        $this->app->singleton(AcknowledgeDriftAlert::class);
        $this->app->singleton(SnoozeDriftAlert::class);
        $this->app->singleton(DismissDriftAlertAsCancelled::class);
        $this->app->singleton(DashboardDriftBadge::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'drift-alerts');
        }

        $livewire->component('drift-alerts.drift-page', DriftPage::class);
        $livewire->component('drift-alerts.dashboard-drift-badge', DashboardDriftBadge::class);

        $this->registerListener();
        $this->registerTopNavBadgeComposer();
    }

    private function registerListener(): void
    {
        $events = $this->app['events'];
        $events->listen(
            RecurringSeriesMetricsRefreshed::class,
            EvaluateDriftOnMetricsRefreshed::class,
        );
    }

    private function registerTopNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
            $currentUser = $app->make(CurrentUser::class);
            if (! $currentUser->isAuthenticated()) {
                $compose->with('driftOpenCount', 0);

                return;
            }
            $query = $app->make(DriftAlertQuery::class);
            $compose->with('driftOpenCount', $query->openCountForUser($currentUser->user()));
        });
    }
}
```

**Divergence to call out in the plan:**
- The provider both registers its event listener (a new behaviour — Recurring's provider has no listener registration) AND injects a second top-nav composer.
- The top-nav composer key is `driftOpenCount` (new); Recurring's composer remains as-is and continues to inject `recurringPendingCount`. The Blade partial reads both (see § Top-nav badge below).

---

### `Modules/DriftAlerts/Routes/web.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Routes/web.php` (full file — lines 1-37)

**Excerpt to mirror:**

```php
declare(strict_types=1);

/*
 * DriftAlerts module routes.
 *
 * `/drift` — the dedicated subscription-drift surface. Lists open
 * alerts grouped by series with three actions (Acknowledge / Snooze /
 * I cancelled this) plus History and Dismissed tabs. Renders the
 * `DriftPage` Livewire SFC.
 *
 * Sits behind `auth` + `web` middleware. Cross-user isolation is
 * enforced by the Public services + Actions (every read / write
 * scopes by `user_id`).
 */

use Illuminate\Support\Facades\Route;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/drift', DriftPage::class)->name('drift.index');
});
```

**Divergence:** No `recurring.review` style sub-routes — `DriftPage` carries Open / History / Dismissed inside one SFC via Livewire `#[Url]` tab state.

---

### `Modules/DriftAlerts/Database/Migrations/*_create_drift_alerts_table.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Database/Migrations/2026_05_18_010001_create_recurring_series_table.php` (full file — lines 1-119)

**Excerpt to mirror — anonymous-Migration class shape + trigger pair (lines 44-119):**

```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('drift_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->string('state', 24)->default('open');
            $table->enum('direction', ['expense', 'income']);
            $table->bigInteger('baseline_amount_minor');
            $table->bigInteger('latest_amount_minor');
            $table->string('currency', 3);
            $table->bigInteger('delta_minor');
            $table->bigInteger('annualized_impact_minor');
            $table->unsignedTinyInteger('threshold_percent_used');
            $table->string('threshold_source', 24);
            $table->foreignId('latest_occurrence_id')
                ->constrained('recurring_series_occurrences')->cascadeOnDelete();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['recurring_series_id', 'latest_occurrence_id'], 'drift_alerts_uniq');
            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'state', 'detected_at']);
            $table->index(['user_id', 'recurring_series_id', 'state']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedStates = "'open','acknowledged','snoozed','dismissed_cancelled'";

        $connection->statement(sprintf(
            "CREATE TRIGGER drift_alerts_state_check_insert BEFORE INSERT ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER drift_alerts_state_check_update BEFORE UPDATE OF state ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS drift_alerts_state_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS drift_alerts_state_check_update');

        $this->schema()->dropIfExists('drift_alerts');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
```

**Divergences:**
- `drift_alerts` adds a `direction` column (denormalised from `recurring_series.direction`) that the parent `recurring_series` table has on the same column name — the migration copies the enum literal.
- `drift_alerts` adds `latest_occurrence_id` as a FK to `recurring_series_occurrences`. This column is the idempotency seam — the UNIQUE `(recurring_series_id, latest_occurrence_id)` guarantees re-running `DetectDriftAlertsJob` against the same `(series, occurrence)` pair never double-writes.
- `threshold_source` is the new `'global' | 'series_override'` audit column — Recurring has no precedent for this. Keep the string-enum + length 24 to mirror the `state` column shape (no `enum()` type — Phase 8 chose `string(24)` for forward-compat with new states; do the same here).
- The `drift_alerts.state` trigger pair mirrors `recurring_series_state_check_insert/update` verbatim, only swapping table + column names.

---

### `Modules/DriftAlerts/Database/Migrations/*_create_drift_alert_transitions_table.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Database/Migrations/2026_05_18_010003_create_recurring_series_transitions_table.php` (full file — lines 1-73)

**Excerpt to mirror — verbatim, swapping table + FK names (lines 35-50):**

```php
public function up(): void
{
    $this->schema()->create('drift_alert_transitions', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('drift_alert_id')->constrained('drift_alerts')->cascadeOnDelete();
        $table->string('from_state', 24);
        $table->string('to_state', 24);
        $table->string('transition_reason', 64);
        $table->string('actor', 16);
        $table->timestamp('transitioned_at');
        $table->text('notes')->nullable();
        $table->timestamps();

        $table->index(['drift_alert_id', 'transitioned_at']);
    });
}
```

**Divergences:** None. The transitions table shape is verbatim identical to the recurring-series transitions table modulo `drift_alert_id` vs `recurring_series_id`.

---

### `Modules/Recurring/Database/Migrations/*_add_drift_threshold_percent_to_recurring_series.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Database/Migrations/2026_05_19_010001_add_cluster_counterparty_key_to_recurring_series.php` (use the file already in the tree as the ALTER TABLE shape; the same anonymous-Migration class + `db()` resolver + `schema()` accessor pattern as the other Recurring migrations).

**Excerpt to mirror (skeletal shape based on Recurring's existing ALTER migrations):**

```php
public function up(): void
{
    $this->schema()->table('recurring_series', static function (Blueprint $table): void {
        $table->unsignedTinyInteger('drift_threshold_percent')
            ->nullable()
            ->after('variance_tolerance_percent');
    });
}

public function down(): void
{
    $this->schema()->table('recurring_series', static function (Blueprint $table): void {
        $table->dropColumn('drift_threshold_percent');
    });
}
```

**Divergence:** Column is nullable (null = use user / global default). Sits next to `variance_tolerance_percent` so both editor surfaces (`/recurring/series/{id}` + `/drift` row) see them together.

---

### `Modules/Recurring/Database/Migrations/*_add_drift_alert_threshold_percent_to_users.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` (full file — lines 1-62)

**Excerpt to mirror — lines 28-45:**

```php
public function up(): void
{
    $this->schema()->table('users', static function (Blueprint $table): void {
        $table->unsignedTinyInteger('drift_alert_threshold_percent')
            ->default(5)
            ->after('recurring_income_min_amount_minor');
    });
}

public function down(): void
{
    $this->schema()->table('users', static function (Blueprint $table): void {
        $table->dropColumn('drift_alert_threshold_percent');
    });
}
```

**Divergence:** Single column (Recurring's analog added two columns). The default of `5` matches the documented global drift threshold. The `after('recurring_income_min_amount_minor')` clause anchors the new column adjacent to the existing recurring-related per-user prefs so `/settings` renders them in one section.

**Planner note:** Per RESEARCH.md the file LIVES IN `Modules/Recurring/Database/Migrations/` (mirrors the `add_recurring_settings_to_users` precedent) — even though the column is conceptually a DriftAlerts setting, keeping users-table migrations together is the existing convention.

---

### `Modules/Recurring/Public/Events/RecurringSeriesMetricsRefreshed.php` (NEW)

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Events/RecurringSeriesCadenceFlipped.php` (full file — lines 1-23)

**Excerpt to mirror — verbatim shape:**

```php
namespace Modules\Recurring\Public\Events;

/**
 * Dispatched after a recurring_series row's metric columns
 * (latest_amount_minor, latest_currency, monthly_equivalent_minor,
 * next_expected_at, cadence) are refreshed by the sweep detector.
 * Fires once per refreshed series per sweep — not per occurrence.
 *
 * Carries the post-refresh metric snapshot inline so listeners can
 * decide whether to do additional work without re-reading the row.
 */
final readonly class RecurringSeriesMetricsRefreshed
{
    public function __construct(
        public int $userId,
        public int $recurringSeriesId,
        public string $direction,
        public string $cadence,
        public int $latestAmountMinor,
        public string $latestCurrency,
    ) {}
}
```

**Divergence:** Six fields vs the three-or-four on existing events — the listener needs `direction` + `cadence` + `latestCurrency` to avoid round-tripping back to the DB for the no-op-skip path.

---

### Detector insertion point (modify `ExpenseSeriesDetector` + `IncomeSeriesDetector`)

**Analog call site:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` lines 330-402 (specifically the end of `refreshExistingSeries()` at line 401 and the end of `insertNewSeries()` at line 339).

**Excerpt — append at the end of `refreshExistingSeries()`, immediately after the existing cadence-flip block (drop in after current line 401):**

```php
$this->events->dispatch(new RecurringSeriesMetricsRefreshed(
    userId: $user->id,
    recurringSeriesId: $seriesId,
    direction: 'expense',
    cadence: $cadence,
    latestAmountMinor: $latestAmountMinor,
    latestCurrency: $currency,
));
```

**Excerpt — append at the end of `insertNewSeries()`, immediately after the existing `RecurringSeriesDetected` dispatch (drop in after current line 339):**

```php
$this->events->dispatch(new RecurringSeriesMetricsRefreshed(
    userId: $user->id,
    recurringSeriesId: $newId,
    direction: 'expense',
    cadence: $cadence,
    latestAmountMinor: $latestAmountMinor,
    latestCurrency: $currency,
));
```

**Divergence for `IncomeSeriesDetector`:** Same two insertions; replace `'expense'` with `'income'` and use the variable names already present in `IncomeSeriesDetector`.

**Use the existing `$this->events` collaborator** — both detectors already inject `Dispatcher`; no constructor change needed.

---

### `Modules/DriftAlerts/Models/DriftAlert.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Models/RecurringSeries.php` (lines 1-125, drop the chain-link relation + cluster-counterparty default which are Recurring-specific)

**Excerpt to mirror — class shape + `BelongsToUser` trait + `fillable` + `casts` + transitions HasMany (lines 52-118):**

```php
namespace Modules\DriftAlerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

/**
 * Eloquent model for the drift_alerts table.
 *
 * One row models one detected drift event for a single approved /
 * cadence-changed recurring series. The `state` column is mutated
 * exclusively by `DriftAlertStateMachine`; the schema-level trigger
 * pair plus the BoundaryArchTest invariant enforce the sole-mutator
 * contract.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $recurring_series_id
 * @property string $state
 * @property string $direction
 * @property int $baseline_amount_minor
 * @property int $latest_amount_minor
 * @property string $currency
 * @property int $delta_minor
 * @property int $annualized_impact_minor
 * @property int $threshold_percent_used
 * @property string $threshold_source
 * @property int $latest_occurrence_id
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $actioned_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DriftAlert extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'recurring_series_id', 'state', 'direction',
        'baseline_amount_minor', 'latest_amount_minor', 'currency',
        'delta_minor', 'annualized_impact_minor',
        'threshold_percent_used', 'threshold_source',
        'latest_occurrence_id', 'snoozed_until', 'detected_at',
        'actioned_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'baseline_amount_minor' => 'integer',
            'latest_amount_minor' => 'integer',
            'delta_minor' => 'integer',
            'annualized_impact_minor' => 'integer',
            'threshold_percent_used' => 'integer',
            'snoozed_until' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'actioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RecurringSeries, $this> */
    public function recurringSeries(): BelongsTo
    {
        return $this->belongsTo(RecurringSeries::class, 'recurring_series_id');
    }

    /** @return BelongsTo<RecurringSeriesOccurrence, $this> */
    public function latestOccurrence(): BelongsTo
    {
        return $this->belongsTo(RecurringSeriesOccurrence::class, 'latest_occurrence_id');
    }

    /** @return HasMany<DriftAlertTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(DriftAlertTransition::class);
    }
}
```

**Divergence:** No `booted()` saving hook (Recurring's analog has one to default `cluster_counterparty_key`; DriftAlerts has no equivalent column). Drops the `latestFundingChainLink` BelongsTo (not in scope). Adds two new relations specific to DriftAlerts (`recurringSeries`, `latestOccurrence`).

**Boundary note:** Importing `Modules\Recurring\Models\RecurringSeries` directly from a model relation is fine — Eloquent relations are considered Public surface in Recurring's `Models/` directory (the `Internal/` namespace is the boundary, not `Models/`). The four arch tests target writes-to-recurring_series, not reads-via-model-association.

---

### `Modules/DriftAlerts/Models/DriftAlertTransition.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Models/RecurringSeriesTransition.php` (full file — lines 1-65)

**Excerpt to mirror — verbatim shape:**

```php
namespace Modules\DriftAlerts\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

final class DriftAlertTransition extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'drift_alert_id', 'from_state', 'to_state',
        'transition_reason', 'actor', 'transitioned_at', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transitioned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DriftAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(DriftAlert::class, 'drift_alert_id');
    }
}
```

**Divergences:** None — only the FK column name and relation method name change.

---

### `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` (full file — lines 1-172)

**Excerpt to mirror — class skeleton + ALLOWED_TRANSITIONS + `transition()` envelope (lines 42-136):**

```php
namespace Modules\DriftAlerts\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Models\DriftAlert;
use RuntimeException;

/**
 * The single legal mutator of `drift_alerts.state` and the sole
 * inserter into `drift_alert_transitions`. Other module code reads
 * the row and may UPDATE non-state columns; the schema-level trigger
 * pair plus the BoundaryArchTest invariant enforce the sole-mutator
 * contract.
 *
 * Public surface mirrors RecurringSeriesStateMachine: a single
 * `transition()` method that opens a transaction, sets PRAGMA
 * busy_timeout = 5000, takes a row lock, validates against
 * ALLOWED_TRANSITIONS, writes the state + updated_at, and inserts
 * one audit row inside the same transaction.
 */
final class DriftAlertStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'open' => ['acknowledged', 'snoozed', 'dismissed_cancelled'],
        'acknowledged' => [],
        'snoozed' => ['open', 'acknowledged', 'dismissed_cancelled'],
        'dismissed_cancelled' => [],
    ];

    /** @var list<string> */
    private const ALLOWED_ACTORS = ['user', 'detector'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, scalar|null>  $extraColumns
     */
    public function transition(
        DriftAlert $alert,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes = null,
        array $extraColumns = [],
    ): void {
        if (! in_array($actor, self::ALLOWED_ACTORS, strict: true)) {
            throw new InvalidArgumentException(
                "DriftAlertStateMachine: unknown actor '{$actor}'; expected one of: ".implode(', ', self::ALLOWED_ACTORS).'.',
            );
        }

        $alertId = self::toInt($alert->id);

        $this->db->connection()->transaction(function () use ($alertId, $toState, $reason, $actor, $notes, $extraColumns): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('drift_alerts')
                ->where('id', $alertId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "DriftAlertStateMachine: drift_alerts row {$alertId} not found.",
                );
            }

            $currentState = self::toString($row->state);
            $this->guardTransition($alertId, $currentState, $toState);

            $now = $this->clock->now()->toDateTimeString();

            $update = array_merge($extraColumns, [
                'state' => $toState,
                'updated_at' => $now,
            ]);

            $connection->table('drift_alerts')
                ->where('id', $alertId)
                ->update($update);

            $userId = self::toIntOrNull($row->user_id);

            $connection->table('drift_alert_transitions')->insert([
                'user_id' => $userId,
                'drift_alert_id' => $alertId,
                'from_state' => $currentState,
                'to_state' => $toState,
                'transition_reason' => $reason,
                'actor' => $actor,
                'transitioned_at' => $now,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    // ... guardTransition() + toInt() + toIntOrNull() + toString() helpers verbatim from analog ...
}
```

**Divergences:**
- Different `ALLOWED_TRANSITIONS` map (drift-alert states, not recurring-series states). `acknowledged` and `dismissed_cancelled` are terminal — empty target arrays — same posture as a no-cycle Phase 8 idiom would allow.
- Different table names (`drift_alerts` + `drift_alert_transitions`).
- Audit row uses `drift_alert_id` FK column instead of `recurring_series_id`.

**Helper methods (`toInt`, `toIntOrNull`, `toString`, `guardTransition`):** Copy verbatim from analog lines 138-171.

---

### `Modules/DriftAlerts/Internal/StateMachines/InvalidStateTransitionException.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/StateMachines/InvalidStateTransitionException.php` (full file — lines 1-29)

**Excerpt to mirror — verbatim, renaming type:**

```php
namespace Modules\DriftAlerts\Internal\StateMachines;

use RuntimeException;

final class InvalidStateTransitionException extends RuntimeException
{
    public static function forTransition(int $alertId, string $from, string $to): self
    {
        return new self(
            "Illegal drift_alerts transition for id={$alertId}: {$from} -> {$to}",
        );
    }
}
```

---

### `Modules/DriftAlerts/Internal/DriftEvaluator.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` (focus lines 330-402: the compute-then-insert envelope that fires events). Read-side draws on `RecurringSeriesQuery::occurrencesForSeries` (lines 112-146).

**Excerpt to mirror — DI shape + read-via-Public-Query + compute + insert + event dispatch:**

```php
namespace Modules\DriftAlerts\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

final readonly class DriftEvaluator
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
        private Dispatcher $events,
    ) {}

    public function evaluateForSeries(int $seriesId, User $user): void
    {
        // 1. Read the series row (state must be approved | cadence_changed).
        $series = $this->recurringQuery->forSeries($seriesId, $user);
        if ($series === null) {
            return; // cross-user / missing — silent no-op
        }
        if (! in_array($series->state, ['approved', 'cadence_changed'], true)) {
            return;
        }

        // 2. Pull the last two occurrences (DESC order); skip when fewer than two.
        $occurrences = $this->recurringQuery->occurrencesForSeries($seriesId, $user);
        if (count($occurrences) < 2) {
            return;
        }
        $latest = $occurrences[0];
        $prior = $occurrences[1];

        // 3. Guard prior=0 / prior=null (Pitfall 4).
        $priorMinor = $prior->observedAmount->minorUnits();
        if ($priorMinor === 0) {
            return;
        }

        // 4. Compute signed delta in original currency.
        $latestMinor = $latest->observedAmount->minorUnits();
        $deltaMinor = $latestMinor - $priorMinor;
        $ratio = abs($deltaMinor) * 100 / abs($priorMinor);

        // 5. Effective threshold lookup.
        $threshold = $this->effectiveThresholdPercent($seriesId, $user);
        if ($ratio <= $threshold['percent']) {
            return;
        }

        // 6. Annualized impact = signed delta × cadence multiplier (×52 / 12 / 4 / 1).
        $annualized = $deltaMinor * $this->cadenceMultiplierForYear($series->cadence);

        // 7. Insert the drift_alerts row inside a transaction; the unique index on
        // (recurring_series_id, latest_occurrence_id) is the idempotency seam.
        $now = $this->clock->now()->toDateTimeString();
        $alertId = $this->db->connection()->table('drift_alerts')->insertGetId([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'state' => 'open',
            'direction' => $series->direction,
            'baseline_amount_minor' => $priorMinor,
            'latest_amount_minor' => $latestMinor,
            'currency' => $series->latestAmount->currency()->getCurrencyCode(),
            'delta_minor' => $deltaMinor,
            'annualized_impact_minor' => $annualized,
            'threshold_percent_used' => $threshold['percent'],
            'threshold_source' => $threshold['source'],
            'latest_occurrence_id' => $latest->occurrenceId,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 8. Dispatch the Public event.
        $this->events->dispatch(new DriftAlertOpened(
            userId: $user->id,
            driftAlertId: (int) $alertId,
            recurringSeriesId: $seriesId,
            direction: $series->direction,
            deltaMinor: $deltaMinor,
            annualizedImpactMinor: $annualized,
            currency: $series->latestAmount->currency()->getCurrencyCode(),
        ));
    }

    /** @return array{percent: int, source: string} */
    private function effectiveThresholdPercent(int $seriesId, User $user): array
    {
        // See § Shared Patterns / Effective threshold lookup
        // ...
    }

    private function cadenceMultiplierForYear(string $cadence): int
    {
        // See § Shared Patterns / Cadence-to-year multiplier
        // ...
    }
}
```

**Divergences from `ExpenseSeriesDetector`:**
- DriftEvaluator **reads** Recurring through `RecurringSeriesQuery` (Public surface) only — it never imports `Modules\Recurring\Internal\*` and never imports `Modules\Recurring\Models\RecurringSeries`. The boundary arch test (D-902 invariant 3) enforces this.
- DriftEvaluator **writes** only to `drift_alerts` (via `DB::table()` through the injected `DatabaseManager`). Never to `recurring_series` (D-902 invariant 2).
- No row-locking on the insert path because the UNIQUE(recurring_series_id, latest_occurrence_id) index does the idempotency work — duplicate inserts raise an integrity exception that the job catches as a no-op. Mirrors the Phase 8 `recurring_series_occurrences` `insertOrIgnore` posture (analog line 430).
- Reads the series row via the Public Query's DTO (`$series->latestAmount`, `$series->direction`, `$series->state`, `$series->cadence`) — never via raw column access.

**Helper methods:** See § Shared Patterns / Effective threshold lookup AND § Shared Patterns / Cadence-to-year multiplier below.

---

### `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` (full file — lines 1-143)

**Excerpt to mirror — class skeleton + `uniqueId` + `uniqueVia` (lines 53-80):**

```php
namespace Modules\DriftAlerts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;

/**
 * Per-(user, series) drift evaluation. Dispatched by
 * EvaluateDriftOnMetricsRefreshed after each Recurring sweep refreshes
 * a series's metric columns.
 *
 * Concurrency contract:
 *  - ShouldBeUniqueUntilProcessing keyed on "{userId}:{seriesId}".
 *  - tries = 3 + backoff = [60, 300, 900].
 *
 * Single permitted facade exception: the Cache::driver('redis') call
 * inside uniqueVia(). Laravel resolves the lock store at queue-push
 * time before constructor DI completes — a constructor-injected
 * Repository is not an option. BoundaryArchTest carve-out names this
 * FQN explicitly.
 */
final class DetectDriftAlertsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
        public readonly int $recurringSeriesId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->recurringSeriesId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return Cache::driver('redis');
    }

    public function handle(DriftEvaluator $evaluator): void
    {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $evaluator->evaluateForSeries($this->recurringSeriesId, $user);
    }
}
```

**Divergences from the analog:**
- Constructor takes **two** ids (`userId`, `recurringSeriesId`) — analog takes one (`userId`).
- `uniqueId()` is a composite string `"{$userId}:{$seriesId}"` — analog returns `(string) $userId`.
- `handle()` takes only `DriftEvaluator` — analog takes `DatabaseManager`, `Clock`, an iterable of detectors, the state machine, plus an optional logger.
- No snooze-expiry pass in `handle()` (DriftAlerts has its own scheduled `RevivedExpiredDriftSnoozesJob` per RESEARCH.md Pitfall 5).
- `firstOrFail` posture verbatim — mirrors the analog (line 98) for the loaded `User`.

**The class-level docblock MUST name the facade carve-out** — mirrors the analog comment at lines 39-44. Without that comment, the executor risks the BoundaryArchTest interpreter flagging the Cache facade.

---

### `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` (NEW)

**Analog:** No exact precedent — the closest pattern is the queue-dispatching listener idiom described in `RESEARCH.md` § "Pattern 2". Use the listener shape suggested below:

**Excerpt to mirror:**

```php
namespace Modules\DriftAlerts\Internal\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\DriftAlerts\Internal\Jobs\DetectDriftAlertsJob;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

/**
 * Subscribes to the Recurring-side per-series metric-refresh event
 * and dispatches a queued DetectDriftAlertsJob for the affected
 * series.
 *
 * The listener stays synchronous (no `ShouldQueue` on the listener
 * itself — the JOB is queued; double-queueing would defeat the
 * unique-job key on (userId, seriesId)).
 *
 * Cross-module: imports `Modules\Recurring\Public\Events\*` only —
 * never `Modules\Recurring\Internal\*`. The boundary arch test
 * enforces this contract.
 */
final readonly class EvaluateDriftOnMetricsRefreshed
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(RecurringSeriesMetricsRefreshed $event): void
    {
        $this->bus->dispatch(new DetectDriftAlertsJob(
            userId: $event->userId,
            recurringSeriesId: $event->recurringSeriesId,
        ));
    }
}
```

**Divergences:** None — this is a greenfield listener; the shape above is the canonical "dispatch a queued job from a synchronous listener" pattern.

**Wiring:** The `DriftAlertsServiceProvider::registerListener()` method (already shown above) wires the listener via `Illuminate\Events\Dispatcher::listen(EventClass, ListenerClass)`. Auto-discovery is NOT used — the registration is explicit so the listener-class FQN appears in the provider's import list and stays Larastan-typed.

---

### `Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Mapping/RecurringSeriesDtoMapper.php` (full file — lines 1-98)

**Excerpt to mirror — static-only hydrator pattern + Money construction (lines 27-83):**

```php
namespace Modules\DriftAlerts\Internal\Mapping;

use Carbon\CarbonImmutable;
use Modules\DriftAlerts\Public\Dto\DriftAlertDto;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

/**
 * Shared hydrator for drift_alerts rows → DriftAlertDto. Static-only;
 * no DI; pure-data transformation. Mirrors RecurringSeriesDtoMapper.
 */
final class DriftAlertDtoMapper
{
    public static function hydrate(stdClass $row, ?string $seriesDisplayName = null, ?int $eurEquivalentMinor = null): DriftAlertDto
    {
        $currency = self::toString($row->currency);
        $baselineAmount = Money::ofMinor(self::toInt($row->baseline_amount_minor), $currency);
        $latestAmount = Money::ofMinor(self::toInt($row->latest_amount_minor), $currency);
        $delta = Money::ofMinor(self::toInt($row->delta_minor), $currency);
        $annualizedImpact = Money::ofMinor(self::toInt($row->annualized_impact_minor), $currency);
        $eurEquivalent = null;
        if ($eurEquivalentMinor !== null && $currency !== 'EUR') {
            $eurEquivalent = Money::ofMinor($eurEquivalentMinor, 'EUR');
        }

        $detectedAt = CarbonImmutable::parse(self::toString($row->detected_at));
        $snoozedUntil = null;
        $rawSnooze = $row->snoozed_until ?? null;
        if (is_string($rawSnooze) && $rawSnooze !== '') {
            $snoozedUntil = CarbonImmutable::parse($rawSnooze);
        }
        $actionedAt = null;
        $rawActioned = $row->actioned_at ?? null;
        if (is_string($rawActioned) && $rawActioned !== '') {
            $actionedAt = CarbonImmutable::parse($rawActioned);
        }

        return new DriftAlertDto(
            driftAlertId: self::toInt($row->id),
            recurringSeriesId: self::toInt($row->recurring_series_id),
            direction: self::toString($row->direction),
            displayName: $seriesDisplayName ?? '',
            state: self::toString($row->state),
            baselineAmount: $baselineAmount,
            latestAmount: $latestAmount,
            delta: $delta,
            annualizedImpact: $annualizedImpact,
            eurEquivalent: $eurEquivalent,
            thresholdPercentUsed: self::toInt($row->threshold_percent_used),
            thresholdSource: self::toString($row->threshold_source),
            detectedAt: $detectedAt,
            actionedAt: $actionedAt,
            snoozedUntil: $snoozedUntil,
        );
    }

    // ... toInt() + toString() helpers verbatim from analog lines 85-97 ...
}
```

**Divergence:** Takes the series's display name + EUR-equivalent as separate arguments (resolved at the query layer) rather than relying on a chain-link lookup. Cleaner separation than the analog because DriftAlerts queries aren't doing fallback walks against chains.

---

### `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php` (full file — lines 1-183)

**Excerpt to mirror — method-parameter DI on every action + `render()` (lines 33-183):**

```php
namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DriftPage extends Component
{
    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    public ?int $cursorId = null;

    public function acknowledge(int $alertId, CurrentUser $currentUser, AcknowledgeDriftAlert $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Acknowledged');
    }

    public function snooze(int $alertId, string $untilIso, CurrentUser $currentUser, SnoozeDriftAlert $action): void
    {
        $until = CarbonImmutable::parse($untilIso);
        ($action)($alertId, $currentUser->user(), $until);
        $this->dispatch('toast', message: 'Snoozed');
    }

    public function dismissAsCancelled(int $alertId, CurrentUser $currentUser, DismissDriftAlertAsCancelled $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Dismissed as cancelled');
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', 'history', 'dismissed'], true)) {
            return;
        }
        $this->tab = $tab;
        $this->cursorId = null;
    }

    public function render(
        CurrentUser $currentUser,
        DriftAlertQuery $query,
        CancellationImpactQuery $impact,
        ViewFactory $views,
        Clock $clock,
    ): View {
        $user = $currentUser->user();

        $rows = match ($this->tab) {
            'history' => $query->historyForUser($user, $this->cursorId),
            'dismissed' => $query->dismissedForUser($user, $this->cursorId),
            default => $query->openForUser($user, $this->cursorId),
        };

        $now = $clock->now();
        $snoozeTargets = [
            '1w' => $now->addWeek()->toIso8601String(),
            '1m' => $now->addMonth()->toIso8601String(),
            '3m' => $now->addMonths(3)->toIso8601String(),
        ];

        $view = $views->make('drift-alerts::livewire.drift-page', [
            'rows' => $rows,
            'tab' => $this->tab,
            'snoozeTargets' => $snoozeTargets,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Drift alerts · diederik']);

        return $view;
    }
}
```

**Critical divergences:**
- **No constructor.** Livewire `Component` subclasses cannot use constructor DI under phpstan-strict-rules. All collaborators arrive as method parameters (acknowledged across the project — `RecurringReviewPage`, `RecurringPage`, `FixedPaymentsCard` all use this pattern; see the analog file lines 28-32 docblock).
- The tab list is `['open', 'history', 'dismissed']` instead of `['pending', 'rejected', 'cadence_changed']`.
- Three action methods (`acknowledge`, `snooze`, `dismissAsCancelled`) replace the analog's five.
- `snoozeTargets` are computed via the injected `Clock` — matches the analog (lines 165-170) exactly so `CarbonImmutable::setTestNow()` remains deterministic.
- **Toast dispatch:** analog uses `undoAction:` and `undoPayload:` named arguments (lines 69, 75, 82, 88, 95); DriftAlerts MAY include these if Wave 3 wants Undo toasts (per Phase 4/5/8 precedent). Plan-discretion: per UI-SPEC § 7, the toast surface is an Undo toast — include `undoAction:` keys.

---

### `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php` (full file — lines 1-72)

**Excerpt to mirror — method-parameter DI on `render()` + a single optional `#[Url]` filter (lines 27-71):**

```php
namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;

final class DashboardDriftBadge extends Component
{
    public function render(
        CurrentUser $currentUser,
        DriftAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $openCount = $query->openCountForUser($user);
        $totalAnnualizedImpact = $query->totalOpenAnnualizedImpactForUser($user);

        return $views->make('drift-alerts::livewire.dashboard-drift-badge', [
            'openCount' => $openCount,
            'totalAnnualizedImpact' => $totalAnnualizedImpact,
        ]);
    }
}
```

**Divergences:** No `#[Url]` filter — the dashboard tile is read-only count + helper line. Mirrors `FixedPaymentsCard`'s single-method `render()` posture but drops the `Clock` injection (no date filter here).

---

### `Modules/DriftAlerts/Public/Dto/DriftAlertDto.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Dto/RecurringSeriesDto.php` (full file — lines 1-51)

**Excerpt to mirror — Spatie-Data class + readonly props + Money fields:**

```php
namespace Modules\DriftAlerts\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class DriftAlertDto extends Data
{
    public function __construct(
        public readonly int $driftAlertId,
        public readonly int $recurringSeriesId,
        public readonly string $direction,
        public readonly string $displayName,
        public readonly string $state,
        public readonly Money $baselineAmount,
        public readonly Money $latestAmount,
        public readonly Money $delta,
        public readonly Money $annualizedImpact,
        public readonly ?Money $eurEquivalent,
        public readonly int $thresholdPercentUsed,
        public readonly string $thresholdSource,
        public readonly CarbonImmutable $detectedAt,
        public readonly ?CarbonImmutable $actionedAt,
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}
}
```

**Divergence:** No `displayName()` method (Recurring's analog has one to fall back from `displayNameOverride` to `detectedName`). DriftAlerts resolves the display name at the query layer (see `DriftAlertDtoMapper`) — the DTO carries the resolved name directly. Simpler than the analog because alerts don't have their own override field.

---

### `Modules/DriftAlerts/Public/Dto/CancellationImpactDto.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Dto/RecurringSeriesDto.php` (similar Spatie-Data + Money shape, simpler)

**Excerpt:**

```php
namespace Modules\DriftAlerts\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Phase 10 hand-off contract — projected savings if the user cancels
 * a recurring series. Read-only; computed from
 * `recurring_series.monthly_equivalent_minor`.
 */
final class CancellationImpactDto extends Data
{
    public function __construct(
        public readonly int $recurringSeriesId,
        public readonly Money $monthlySavings,
        public readonly Money $annualSavings,
        public readonly string $currency,
    ) {}
}
```

---

### `Modules/DriftAlerts/Public/Events/DriftAlertOpened.php` (and three siblings)

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Events/RecurringSeriesApproved.php` (full file — lines 1-19) for the minimal shape; `RecurringSeriesCadenceFlipped.php` (lines 1-23) for the more detailed shape.

**Excerpt to mirror — `final readonly class` with public-property constructor:**

```php
namespace Modules\DriftAlerts\Public\Events;

final readonly class DriftAlertOpened
{
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public int $recurringSeriesId,
        public string $direction,
        public int $deltaMinor,
        public int $annualizedImpactMinor,
        public string $currency,
    ) {}
}
```

**Sibling events:**

```php
// DriftAlertAcknowledged.php
final readonly class DriftAlertAcknowledged {
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $acknowledgedAt,
    ) {}
}

// DriftAlertSnoozed.php
final readonly class DriftAlertSnoozed {
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public CarbonImmutable $snoozedUntil,
    ) {}
}

// DriftAlertDismissedCancelled.php
final readonly class DriftAlertDismissedCancelled {
    public function __construct(
        public int $userId,
        public int $driftAlertId,
        public int $recurringSeriesId,
    ) {}
}
```

**Divergence:** All four events declare `final readonly` + public-property constructor — verbatim shape from Recurring's events. No methods, no behaviour.

---

### `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` (full file — lines 1-56)

**Excerpt to mirror — constructor DI + invocable + cross-user 404 + state-machine call + event dispatch (lines 25-55):**

```php
namespace Modules\DriftAlerts\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertAcknowledged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AcknowledgeDriftAlert
{
    public function __construct(
        private readonly DriftAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user): void
    {
        /** @var DriftAlert|null $alert */
        $alert = DriftAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Drift alert not found.');
        }

        if ($alert->state === 'acknowledged') {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            'acknowledged',
            'user_action',
            'user',
            null,
            ['actioned_at' => $now->toDateTimeString()],
        );

        $this->events->dispatch(new DriftAlertAcknowledged(
            userId: $user->id,
            driftAlertId: $alertId,
            acknowledgedAt: $now,
        ));
    }
}
```

**Critical divergences from `ApproveRecurringSeries`:**
- Adds `Clock` as a constructor collaborator (analog doesn't need it because Recurring's state machine internally stamps `updated_at`). DriftAlerts uses the actioned_at column as the audit timestamp — passed in via `$extraColumns` to ride the same row-locked transaction as the state flip.
- The idempotent no-op guard checks `state === 'acknowledged'` (analog checks `state === 'approved'`).
- The state-machine call passes `$extraColumns = ['actioned_at' => ...]` — same pattern as `SnoozeRecurringSeries` (analog lines 56-63).

---

### `Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php` (full file — lines 1-65)

**Excerpt to mirror — verbatim shape (lines 28-64), with table-name + state-target swaps:**

```php
namespace Modules\DriftAlerts\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertSnoozed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SnoozeDriftAlert
{
    public function __construct(
        private readonly DriftAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $alertId, User $user, CarbonImmutable $until): void
    {
        /** @var DriftAlert|null $alert */
        $alert = DriftAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Drift alert not found.');
        }

        if (
            $alert->state === 'snoozed'
            && $alert->snoozed_until !== null
            && $alert->snoozed_until->toDateTimeString() === $until->toDateTimeString()
        ) {
            return;
        }

        $untilString = $until->toDateTimeString();

        $this->stateMachine->transition(
            $alert,
            'snoozed',
            'user_action',
            'user',
            'snoozed_until='.$untilString,
            ['snoozed_until' => $untilString],
        );

        $this->events->dispatch(new DriftAlertSnoozed(
            userId: $user->id,
            driftAlertId: $alertId,
            snoozedUntil: $until,
        ));
    }
}
```

**Divergence from analog:** Analog has NO event dispatch (snooze on a series is a UI-only deferral that downstream surfaces ignore — analog docblock lines 25-27). DriftAlerts DOES dispatch `DriftAlertSnoozed` because Phase 10 forecasting may subscribe to it. Per RESEARCH.md § Component Responsibilities table.

---

### `Modules/DriftAlerts/Public/Actions/DismissDriftAlertAsCancelled.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` (same approve-then-event shape, line 25-55)

**Excerpt — verbatim structure with state target `dismissed_cancelled` and event `DriftAlertDismissedCancelled`:**

```php
final class DismissDriftAlertAsCancelled
{
    public function __construct(
        private readonly DriftAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user): void
    {
        /** @var DriftAlert|null $alert */
        $alert = DriftAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Drift alert not found.');
        }

        if ($alert->state === 'dismissed_cancelled') {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            'dismissed_cancelled',
            'user_dismissed_cancelled',
            'user',
            null,
            ['actioned_at' => $now->toDateTimeString()],
        );

        $this->events->dispatch(new DriftAlertDismissedCancelled(
            userId: $user->id,
            driftAlertId: $alertId,
            recurringSeriesId: $alert->recurring_series_id,
        ));
    }
}
```

**Divergences from `ApproveRecurringSeries`:**
- Different `transition_reason` (`'user_dismissed_cancelled'` — Recurring's analog uses `'user_action'`). The audit row will carry this reason verbatim.
- The event carries `recurringSeriesId` (Phase 10 will use it to exclude the series from forecasts) — mirrors how `RecurringSeriesCadenceFlipped` carries the seriesId.
- Does NOT mutate `recurring_series.state` — the action explicitly contains no call into `Modules\Recurring\Public\Actions\*`. Boundary arch test invariant 2 enforces this.

---

### `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Services/RecurringSeriesQuery.php` (full file — lines 1-318)

**Excerpt to mirror — DI + cursor-paginated scoped methods + `forSeries` lookup + cross-user empty (lines 36-104):**

```php
namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Mapping\DriftAlertDtoMapper;
use Modules\DriftAlerts\Public\Dto\DriftAlertDto;
use stdClass;

final readonly class DriftAlertQuery
{
    public function __construct(private DatabaseManager $db) {}

    /** @return list<DriftAlertDto> */
    public function openForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['open'], $cursorId, $limit);
    }

    /** @return list<DriftAlertDto> */
    public function historyForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['acknowledged'], $cursorId, $limit);
    }

    /** @return list<DriftAlertDto> */
    public function dismissedForUser(User $user, ?int $cursorId = null, int $limit = 26): array
    {
        return $this->scoped($user, ['dismissed_cancelled'], $cursorId, $limit);
    }

    public function openCountForUser(User $user): int
    {
        return $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('state', 'open')
            ->count();
    }

    public function totalOpenAnnualizedImpactForUser(User $user): int
    {
        return (int) $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('state', 'open')
            ->sum('annualized_impact_minor');
    }

    /** @return list<DriftAlertDto> */
    private function scoped(User $user, array $states, ?int $cursorId, int $limit): array
    {
        $query = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($cursorId !== null) {
            $query->where('id', '<', $cursorId);
        }

        $rows = $query->get();

        // Batch-decorate display names + EUR shadows here. Mirrors
        // FixedPaymentsViewQuery's batch-merchant-memory shape (analog
        // lines 80-93).
        // ...

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = DriftAlertDtoMapper::hydrate($row, $seriesDisplayNames[$row->recurring_series_id] ?? '');
        }

        return $result;
    }
}
```

**Critical divergences from `RecurringSeriesQuery`:**
- DriftAlertQuery's secondary sort is `(detected_at DESC, id DESC)` whereas Recurring's `approvedForUser` uses `(monthly_equivalent_minor DESC, id DESC)`. The composite-cursor approach for Recurring isn't needed because `detected_at` is rarely a tie-breaker.
- The query reads the underlying `drift_alerts` table directly via injected `DatabaseManager` (DI invariant + no Eloquent for batched reads — same posture as the analog at lines 60-65, 110-127). Eloquent is reserved for single-row reads in the Public Actions.
- `totalOpenAnnualizedImpactForUser` is new — no analog. Returns a SUM aggregate in original-currency-minor units (the dashboard tile copy "open · ↗ €54/yr" reads from this).
- The `scoped()` cursor path is simpler than the analog's `(monthly_equivalent_minor, id)` composite — `detected_at` is essentially monotonic so an id-only cursor is sufficient.

---

### `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Services/RecurringSeriesQuery.php` `forSeries()` method (lines 91-104)

**Excerpt — single-method shape:**

```php
namespace Modules\DriftAlerts\Public\Services;

use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Phase 10 hand-off contract — projected savings if the user cancels
 * a given recurring series. Read-only.
 *
 * Math: monthly_savings = recurring_series.monthly_equivalent_minor;
 *       annual_savings  = monthly_savings × 12;
 *       currency        = recurring_series.latest_currency.
 *
 * The output DTO's `currency` field is the recurring series's original
 * currency, NOT necessarily EUR. Phase 8's monthly_equivalent_minor is
 * denominated in latest_currency (the original transaction currency),
 * not EUR — RESEARCH.md Pitfall 1.
 */
final readonly class CancellationImpactQuery
{
    public function __construct(private RecurringSeriesQuery $recurringQuery) {}

    public function forSeries(int $seriesId, User $user): ?CancellationImpactDto
    {
        $series = $this->recurringQuery->forSeries($seriesId, $user);
        if ($series === null) {
            return null;
        }

        $currency = $series->monthlyEquivalent->currency()->getCurrencyCode();
        $monthlyMinor = $series->monthlyEquivalent->minorUnits();
        $annualMinor = $monthlyMinor * 12;

        return new CancellationImpactDto(
            recurringSeriesId: $seriesId,
            monthlySavings: Money::ofMinor($monthlyMinor, $currency),
            annualSavings: Money::ofMinor($annualMinor, $currency),
            currency: $currency,
        );
    }
}
```

**Critical divergences:**
- Returns `?CancellationImpactDto` (nullable) on cross-user — mirrors `RecurringSeriesQuery::forSeries` posture (lines 95-100). Callers throw 404 themselves; the query stays silent.
- Reads via injected `RecurringSeriesQuery` (boundary invariant 3) — never reads `recurring_series` directly. Larastan would flag a direct table touch.

---

### `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php` — read this file when implementing (page heading + tab bar + cursor-paginated table + per-row action chips + snooze popover at lines 130-160, sticky bulk-action bar at lines 51-67).

**Pattern note:** UI-SPEC § Spacing Scale and § Typography give the exact chrome (`rounded-lg border border-slate-200 bg-white p-4`, `text-2xl font-semibold tracking-tight text-slate-900` for the page heading, `flux:card` + `Alpine x-data="{ open: false }"` for grouped-by-series headers since Flux has no accordion primitive). The snooze popover chrome (analog lines 130-160) is REUSED verbatim — DriftAlerts does NOT duplicate the markup.

---

### `Modules/DriftAlerts/Resources/views/livewire/dashboard-drift-badge.blade.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Resources/views/livewire/fixed-payments-card.blade.php` — calm tile chrome (`rounded-lg border border-slate-200 bg-white p-6`) per UI-SPEC § Spacing Scale.

**Hidden when `$openCount === 0`** per UI-SPEC § Phase scope.

---

### `Modules/DriftAlerts/tests/TestCase.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/tests/TestCase.php` — verbatim shape, namespace-swapped. The per-module `TestCase` typically extends `Tests\TestCase` and adds module-specific setup helpers.

---

### `Modules/DriftAlerts/tests/Pest.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/tests/Pest.php` — documented inert per root `tests/Pest.php` comment block.

---

### Unit + Feature tests (DriftEvaluatorTest, AcknowledgeDriftAlertTest, etc.)

**Analogs:** Listed in the file-classification table. Each new test mirrors its corresponding analog point-for-point. The Recurring test files at:
- `Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php` (state-machine transition table + invalid-transition exception coverage)
- `Modules/Recurring/tests/Feature/ApproveRecurringSeriesTest.php` (Public Action happy path + idempotency + cross-user 404)
- `Modules/Recurring/tests/Feature/SnoozeRecurringSeriesTest.php` (snooze + audit row count + idempotency)
- `Modules/Recurring/tests/Feature/RecurringReviewPageTest.php` (Livewire SFC mount + tab switching + action dispatch)
- `Modules/Recurring/tests/Feature/CrossUserRecurringSeriesIsolationTest.php` (cross-user 404 across every action)
- `Modules/Recurring/tests/Feature/TopNavBadgeComposerTest.php` (View Factory composer fires; injected count is read by the Blade)
- `Modules/Recurring/tests/Feature/FixedPaymentsCardTest.php` (Livewire SFC mounts + reads through injected Query)

are read as the structural blueprint. Each DriftAlerts test renames the table + state transitions + DTO + event symbols and otherwise mirrors the analog's `it()` block structure verbatim.

---

### `Modules/DriftAlerts/tests/fixtures/drift-corpus/*.php`

**Analog:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/tests/fixtures/synthesised/drifting-monthly-spotify.php` (full file — lines 1-40+)

**Excerpt to mirror — returning a structured `{transactions, expected}` array (analog lines 33-40):**

```php
return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -999,
                'latest_amount_minor' => -1149,
                'delta_minor' => -150,
                'annualized_impact_minor' => -1800,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
```

**Scenarios to mirror** (from RESEARCH.md § Wave 0 Fixture Corpus):
- `stable-monthly` (0 alerts) — model after `stable-monthly-spotify`
- `large-drift-above-threshold` — modify `drifting-monthly-spotify` for a 15% drift
- `fx-only-swing` (0 alerts) — model after `mixed-currency-netflix-usd`
- `prior-zero` / `prior-null` (0 alerts each) — Wave 0 guard cases
- `per-series-override` (0 alerts when override = 50%) — new scenario; no exact analog, but the transactions block is identical to a normal drift fixture
- `multi-drift` (2 alerts) — 3-step price escalation; new shape, but cells follow the array-fill pattern from the analog
- `weekly-cadence` / `quarterly-cadence` / `yearly-cadence` — model after `weekly-streaming` / `quarterly-insurance` / `yearly-domain`

---

### `tests/Contracts/DriftDetectionContractTest.php`

**Analog:** `/Users/wesselverheij/Development/diederik/tests/Contracts/RecurringDetectionContractTest.php` (full file — lines 1-228)

**Excerpt to mirror — fixture-expectations table + per-fixture seeding + assertion (analog lines 30-110):**

```php
function ddctFixtureExpectations(): array
{
    return [
        'stable-monthly' => ['stable-monthly', 0],
        'large-drift-above-threshold' => ['large-drift-above-threshold', 1],
        'fx-only-swing' => ['fx-only-swing', 0],
        'per-series-override' => ['per-series-override', 0],
        'multi-drift' => ['multi-drift', 2],
        // ... rest of the corpus
    ];
}

// Per-fixture: seed the recurring_series row + occurrences, dispatch
// DetectDriftAlertsJob synchronously, assert drift_alerts.count() matches.
```

**Divergences:** Tests the DriftAlerts evaluator output, not the recurring detectors. Per fixture: seed `recurring_series` (state='approved'), seed N `recurring_series_occurrences`, run `DetectDriftAlertsJob` via direct `handle()` call, assert `DriftAlert::count()` matches `expected['alerts']` array length.

---

## Shared Patterns

### Top-nav badge (compound chip on existing Recurring slot)

**Source:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Providers/RecurringServiceProvider.php` lines 111-126 + UI-SPEC § Navigation Decision (compound-badge chrome).

**Pattern:** Each module's `ServiceProvider::boot()` registers an independent composer on `core::livewire.top-nav` that injects a count. The Blade partial reads ALL injected counts (`recurringPendingCount`, `driftOpenCount`) and renders either a single pill (one count non-zero) or two side-by-side pills (compound). Per UI-SPEC the second pill uses rose-50 background.

**Apply to:** `DriftAlertsServiceProvider::registerTopNavBadgeComposer()` (excerpt already shown above) + the Blade partial for the top nav (modified, not new). Mirrors the existing analog at lines 111-126 verbatim.

**Larastan note:** The composer closure is `static` (analog line 116). DI happens via `$app->make(...)` inside the closure — the `$app` capture is by-value (closure capture) and the closure is invoked at composer-fire-time, after DI is wired.

---

### Cross-user 404 invariant

**Source:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` lines 32-45 + `SnoozeRecurringSeries.php` lines 36-44.

**Apply to:** All three DriftAlerts Public Actions + `DriftPage::mount()` (if mount loads any alert by id, which it doesn't in this design — the page lists, not loads-by-id) + every method of `DriftAlertQuery` (returns empty list / null on cross-user).

**Pattern excerpt:**

```php
$alert = DriftAlert::query()
    ->where('id', $alertId)
    ->where('user_id', $user->id)
    ->first();

if ($alert === null) {
    throw new NotFoundHttpException('Drift alert not found.');
}
```

---

### `ShouldBeUniqueUntilProcessing` queued-job idiom

**Source:** `/Users/wesselverheij/Development/diederik/Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` lines 53-80.

**Apply to:** `DetectDriftAlertsJob` (per-user, per-series key) + the new `RevivedExpiredDriftSnoozesJob` (if planner picks the per-job approach for the hourly snooze-revival sweep — likely yes per RESEARCH.md Pitfall 5).

**Carve-out:** The `Cache::driver('redis')` facade call inside `uniqueVia()` is the SINGLE permitted facade use in module code. The BoundaryArchTest's `ignoring(...)` list (project root `tests/Contracts/BoundaryArchTest.php` lines 51-87) MUST gain the new FQN `Modules\\DriftAlerts\\Internal\\Jobs\\DetectDriftAlertsJob` and (if applicable) `Modules\\DriftAlerts\\Internal\\Jobs\\RevivedExpiredDriftSnoozesJob`. Mirrors the existing carve-out for `DetectRecurringSeriesJob` at line 86.

---

### BoundaryArchTest invariants (4 new, possibly 5)

**Source:** `/Users/wesselverheij/Development/diederik/tests/Contracts/BoundaryArchTest.php` lines 522-627 (the two filesystem-walk `it()` blocks that police `noTransactionWritesFromRecurring` and `noOtherRecurringSeriesStateMutator`) + lines 44-46 (the namespace-`->toOnlyBeUsedIn(...)` form) + lines 48-87 (the facade-ignore list).

**Apply — four new invariants for DriftAlerts:**

1. `noFacadeCallsFromDriftAlerts` — extension of the existing facade rule. Add `Modules\\DriftAlerts\\Internal\\Jobs\\DetectDriftAlertsJob` to the `ignoring(...)` list (lines 51-87) so the Cache facade carve-out is the only permitted use. Mirror the comment block (analog lines 79-86) for the new entry.

2. `noRecurringSeriesWritesFromDriftAlerts` — filesystem-walk arch test mirroring `noTransactionWritesFromRecurring` (analog lines 522-569). Walks every file under `Modules/DriftAlerts/` (skipping `tests/`), strips block + line comments, and fails if `RecurringSeries::query|RecurringSeries::where|RecurringSeries::create` or `->table('recurring_series')->update|insert|delete` patterns appear. Excerpt:

   ```php
   it('does not allow any file under Modules/DriftAlerts/ to mutate the recurring_series table (noRecurringSeriesWritesFromDriftAlerts)', function (): void {
       $hits = [];
       $driftDir = base_path('Modules/DriftAlerts');
       if (! is_dir($driftDir)) { expect(true)->toBeTrue(); return; }
       // ... mirror the filesystem walk + comment-strip + regex match from analog lines 538-564 ...
   });
   ```

3. `crossModuleAccessGoesThroughPublic` — namespace arch test ensuring DriftAlerts never imports `Modules\Recurring\Internal\*`. Use the `->toOnlyBeUsedIn(...)` shape (analog lines 44-46) or `->not->toBeUsedIn(...)` (analog lines 16-22). Excerpt:

   ```php
   arch('Modules\\Recurring\\Internal is never imported from Modules\\DriftAlerts (crossModuleAccessGoesThroughPublic)')
       ->expect('Modules\\Recurring\\Internal')
       ->not->toBeUsedIn('Modules\\DriftAlerts');
   ```

4. `noSynchronousDriftDetectionInRequestLifecycle` — namespace arch test mirroring the existing `SeriesDetector` invariant at lines 109-114. Excerpt:

   ```php
   arch('DriftEvaluator is never imported by Modules\\DriftAlerts\\Internal\\Http (noSynchronousDriftDetectionInRequestLifecycle)')
       ->expect('Modules\\DriftAlerts\\Internal\\DriftEvaluator')
       ->not->toBeUsedIn([
           'Modules\\DriftAlerts\\Internal\\Http',
           'Modules\\DriftAlerts\\Resources',
       ]);
   ```

**Optional fifth invariant** (RESEARCH.md § Anti-Patterns flags this):

5. `noOtherDriftAlertStateMutator` — filesystem-walk arch test mirroring `noOtherRecurringSeriesStateMutator` (analog lines 571-627). Only `DriftAlertStateMachine.php` may UPDATE `drift_alerts.state`. Same skip-list (skip `tests/`, skip Migrations dir, skip the state-machine file itself). Excerpt:

   ```php
   it('does not allow any file other than DriftAlertStateMachine to mutate drift_alerts.state (noOtherDriftAlertStateMutator)', function (): void {
       $allowedFile = base_path('Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php');
       // ... mirror analog lines 588-625 verbatim ...
   });
   ```

**Add the module's namespace to the internal-only-used-in arch test at line 44:**

```php
arch('Modules\\DriftAlerts\\Internal is only used inside Modules\\DriftAlerts')
    ->expect('Modules\\DriftAlerts\\Internal')
    ->toOnlyBeUsedIn('Modules\\DriftAlerts');
```

---

### Effective threshold lookup (DriftEvaluator helper)

**Source:** RESEARCH.md § Code Examples → Effective Threshold Lookup (lines 600-625).

**Pattern excerpt (paste into `DriftEvaluator`):**

```php
/** @return array{percent: int, source: string} */
private function effectiveThresholdPercent(int $recurringSeriesId, User $user): array
{
    $seriesRow = $this->db->connection()->table('recurring_series')
        ->where('id', $recurringSeriesId)
        ->where('user_id', $user->id)
        ->first(['drift_threshold_percent']);

    $seriesOverride = $seriesRow !== null && is_numeric($seriesRow->drift_threshold_percent)
        ? (int) $seriesRow->drift_threshold_percent
        : null;

    if ($seriesOverride !== null) {
        return ['percent' => $seriesOverride, 'source' => 'series_override'];
    }

    $userValue = $user->drift_alert_threshold_percent;
    if (is_int($userValue) && $userValue > 0) {
        return ['percent' => $userValue, 'source' => 'global'];
    }

    return ['percent' => 5, 'source' => 'global'];
}
```

**Boundary note:** This is the SINGLE permitted read of `recurring_series` directly from DriftAlerts (read of a non-state column). The arch test invariant 2 fires only on WRITES to `recurring_series`; reads of the `drift_threshold_percent` column are intentionally allowed. Document the carve-out in the method's PHPDoc so future readers don't refactor it through the Public Query (which would also be fine but adds an indirection).

Alternatively, planner may choose to expose `drift_threshold_percent` via a new `RecurringSeriesQuery::driftThresholdForSeries(int $seriesId, User $user): ?int` method to keep the boundary maximally clean — minor Recurring-side surgery, parallel to what RESEARCH.md says about `occurrencesForSeries` already being there.

---

### Cadence-to-year multiplier (DriftEvaluator helper)

**Source:** RESEARCH.md § Code Examples → Cadence-to-Year Multiplier (lines 644-653); aligns with the Phase 8 monthly-equivalent multiplier `× 52 / 12` (analog `ExpenseSeriesDetector::monthlyEquivalent` lines 433-445).

**Pattern excerpt:**

```php
private function cadenceMultiplierForYear(string $cadence): int
{
    return match ($cadence) {
        'weekly' => 52,
        'monthly' => 12,
        'quarterly' => 4,
        'yearly' => 1,
        default => 0, // cadence='irregular' produces zero impact — guard upstream
    };
}
```

**Divergence:** Phase 8 uses `× 52 / 12` for the weekly→monthly conversion (analog line 438-440 docblock); Phase 9 uses `× 52` directly for weekly→annual. Integer-level consistency: Phase 8's `latestAmount × 52 / 12 → monthly_equivalent_minor` then Phase 9's `monthly_equivalent_minor × 12 → annual` agrees with Phase 9's `delta × 52` at every fixture. Verified in RESEARCH.md.

---

### PSR-4 test wire-up (3-step pattern)

**Source:** `/Users/wesselverheij/Development/diederik/tests/Pest.php` lines 22-50 (the per-module map).

**Apply** — three coordinated edits:

1. Project-root `composer.json`'s `autoload-dev.psr-4`: add `"Modules\\DriftAlerts\\Tests\\": "Modules/DriftAlerts/tests/"`.

2. Project-root `phpunit.xml`: add a `<testsuite>` entry pointing at `Modules/DriftAlerts/tests/`. Mirror the Recurring testsuite block.

3. Project-root `tests/Pest.php`: add a row to the per-module map (after line 31 in the analog excerpt, inside the `foreach` array):

   ```php
   'Modules/DriftAlerts' => Modules\DriftAlerts\Tests\TestCase::class,
   ```

---

### Settings page extension (modify `Modules/Core/Internal/Http/Livewire/SettingsPage.php`)

**Analog source:** `Modules/Core/Internal/Http/Livewire/SettingsPage.php` lines 90-93 (read-bind) + lines 126-128 (write-bind).

**Apply** — single property + single input row:

```php
// Property declaration (mirrors recurringDetectionWindowMonths)
public int $driftAlertThresholdPercent = 5;

// Inside the load method (mirrors line 92-93):
$this->driftAlertThresholdPercent = $user->drift_alert_threshold_percent ?? 5;

// Inside the save method (mirrors line 127-128):
$user->drift_alert_threshold_percent = $this->driftAlertThresholdPercent;
```

**Divergence:** No analog for the per-series override editor — the inline editor on `/drift` rows is new UI. UI-SPEC § "Per-series threshold override editor (inline on /drift)" carries the full chrome contract; planner picks the popover Livewire wiring (likely an inline `wire:click` on a Flux popover trigger).

---

## No Analog Found

| File | Role | Data Flow | Reason | Mitigation |
|------|------|-----------|--------|------------|
| `Modules/DriftAlerts/Internal/Listeners/EvaluateDriftOnMetricsRefreshed.php` | event listener (synchronous, dispatches queued job) | event-driven | No existing in-repo listener uses this "synchronous listener dispatches a unique queued job" pattern — Phase 5/6/8 events fire jobs through other mechanisms (scheduled tasks, on-demand buttons). RESEARCH.md § Pattern 2 documents the intent. | Excerpt provided in this PATTERNS.md. The shape is canonical Laravel; no architectural risk. |
| Per-series threshold popover (`/drift` row inline editor) | UI component | request-response | The `/uncategorized` per-row category-picker popover is the closest UX precedent but not the same module. UI-SPEC § Per-series threshold override editor carries the full chrome contract. | The Blade view is a small popover with six radios; no Livewire state machinery needed beyond a `wire:click` handler on each radio. |
| `RevivedExpiredDriftSnoozesJob` (optional — D-925 / Pitfall 5) | scheduled job | batch | Phase 8 runs its snooze-expiry pass INSIDE the recurring detector job (analog `DetectRecurringSeriesJob::expireSnoozes`); DriftAlerts splits the equivalent into its own scheduled job per RESEARCH.md. | The shape is the same `ShouldBeUniqueUntilProcessing` job posture as `DetectDriftAlertsJob`; the difference is the unique key (per-user, not per-(user,series)). |

---

## Metadata

**Analog search scope:**
- `Modules/Recurring/` (primary — all roles)
- `Modules/Core/` (CurrentUser contract, Clock contract, SettingsPage)
- `tests/Contracts/` (BoundaryArchTest + RecurringDetectionContractTest)
- `tests/Pest.php` (per-module PSR-4 map)

**Files scanned:** ~40 module files + ~10 test files; targeted reads only — no full-tree dumps.

**Pattern extraction date:** 2026-05-17

**GSD-agnostic invariant verification:** Every excerpt above strips D-numbers, REQ-IDs, and `.planning/`-style references. PHPDocs describe what the code does, not why or when it was added. The planner should preserve this invariant when transferring excerpts into PLAN.md → runtime code.
