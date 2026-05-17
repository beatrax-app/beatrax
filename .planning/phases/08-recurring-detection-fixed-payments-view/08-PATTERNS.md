# Phase 8: Recurring Detection + Fixed Payments View — Pattern Map

**Mapped:** 2026-05-17
**Files analyzed:** 49 new files (module + Public + Internal + Database + Resources + Routes + tests + cross-module touches) + 4 modified root files
**Analogs found:** 49 / 49 (all roles have a working in-repo analog)

> Phase 8 is composition over invention. Every new file in `Modules/Recurring/` mirrors a working analog under `Modules/Chains/`, `Modules/EmailScan/`, `Modules/Receipts/`, or `Modules/Categorization/`. The planner MUST instruct executors to **Read the analog first** and follow its shape verbatim — namespaces, class qualifiers (`final`, `final readonly`), constructor-DI shape, docblock invariants, and column naming.

---

## Forbidden Surface (apply to every Phase 8 file)

Plans MUST NOT permit any of the following inside `Modules/Recurring/`. Every BLOCKER/WARNING/INFO under CLAUDE.md "Laravel DI-only — no helpers or facades" applies. Executors who use any of these will fail `tests/Contracts/BoundaryArchTest.php`:

| Forbidden | Use instead |
|-----------|-------------|
| `Auth::`, `auth()`, `request()->user()` | Constructor-inject `Modules\Core\Public\Contracts\CurrentUser` (matches `ChainReviewQueue` action-method signature) |
| `DB::table(...)`, `DB::connection()` | Constructor-inject `Illuminate\Database\DatabaseManager` (matches `MerchantMemoryQuery` line 32 + `InboxScanStateMachine` line 118) |
| `config()`, `Config::` | Constructor-inject `Illuminate\Contracts\Config\Repository` |
| `view()`, `View::` | Resolve `Illuminate\Contracts\View\Factory` via `$this->app->make(ViewFactoryContract::class)` (Chains issue #12 fix — `ChainsServiceProvider` line 141) |
| `Cache::`, `Queue::`, any other Laravel facade | None — except the **single carve-out** `Cache::driver('redis')` inside `DetectRecurringSeriesJob::uniqueVia()` (mirrors `ResolveChainLinksJob` line 95). Planner MUST add the new job FQN to the `ignoring([...])` array on `tests/Contracts/BoundaryArchTest.php` lines 47–75 in the same wave that creates the job. |
| `view()->composer(...)` for the top-nav badge or dashboard card | View Factory contract resolved via `$this->app->make(ViewFactoryContract::class)->composer(...)` (Chains issue #12 — `ChainsServiceProvider::registerTopNavBadgeComposer` lines 139–154) |
| `Transaction::query()->update(...)` / `->table('transactions')->update(...)` | None — the `noTransactionWritesFromRecurring` arch test (Wave 0) blocks this. Recurring is analytical-only. |
| Constructor injection on a Livewire `Component` subclass | Inject collaborators as method parameters on `render()` and on each action method (matches `ChainReviewQueue` lines 57–84). phpstan-strict-rules blocks the constructor pattern. |

---

## File Classification

### Module skeleton + wiring (Wave 0)

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|--------------------|------|-----------|----------------|---------------|
| `Modules/Recurring/composer.json` | composer | n/a | `Modules/Chains/composer.json` | exact |
| `Modules/Recurring/Providers/RecurringServiceProvider.php` | service provider | n/a | `Modules/Chains/Providers/ChainsServiceProvider.php` | exact |
| `Modules/Recurring/tests/Pest.php` | test bootstrap | n/a | `Modules/Receipts/tests/Pest.php` | exact |
| `Modules/Recurring/tests/TestCase.php` | test base | n/a | `Modules/Receipts/tests/TestCase.php` | exact |
| `bootstrap/providers.php` (modify) | app config | n/a | `bootstrap/providers.php` lines 7–27 (add `RecurringServiceProvider::class` to the array, mirror existing pattern) | exact |
| `composer.json` (modify) | autoload-dev | n/a | `composer.json` autoload-dev block (add `"Modules\\Recurring\\Tests\\": "Modules/Recurring/tests/"` exactly like the seven existing module-test entries) | exact |
| `phpunit.xml` (modify) | test config | n/a | `phpunit.xml` Unit + Feature testsuites (add Recurring test dirs alongside the existing seven module entries) | exact |
| `tests/Pest.php` (modify) | per-module TestCase map | n/a | `tests/Pest.php` foreach map (Phase 4 D-80b 3-step pattern) | exact |

### Migrations (Wave 1)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/Database/Migrations/YYYY_MM_DD_create_recurring_series_table.php` | migration | DDL | `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` | exact (BIGINT minor units + state enum + trigger pattern) |
| `Modules/Recurring/Database/Migrations/YYYY_MM_DD_create_recurring_series_occurrences_table.php` | migration | DDL | `Modules/Chains/Database/Migrations/2026_05_16_010003_create_card_statement_credits_table.php` | exact (per-row link to parent series + `(parent_id, observed_at DESC)` composite index) |
| `Modules/Recurring/Database/Migrations/YYYY_MM_DD_create_recurring_series_transitions_table.php` | migration | DDL (audit) | `Modules/Chains/Database/Migrations/2026_05_16_010005_create_chain_resolution_runs_table.php` | role-match (audit/history table — append-only) |
| `Modules/Recurring/Database/Migrations/YYYY_MM_DD_add_recurring_settings_to_users.php` | migration | DDL (column add) | Phase 3 `default_currency_view` / Phase 6 `auto_import_drop_folder` precedent migrations on `users` (research line 1042) | role-match (additive column on `users`) |

### Eloquent models (Wave 1)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/Models/RecurringSeries.php` | model | CRUD | `Modules/Chains/Models/ChainLink.php` | exact (uses `BelongsToUser`, `final class extends Model`, `casts()` method, `@property` PHPDoc) |
| `Modules/Recurring/Models/RecurringSeriesOccurrence.php` | model | CRUD | `Modules/Chains/Models/ChainLink.php` | exact (same shape, plus a `BelongsTo<RecurringSeries>` relation) |
| `Modules/Recurring/Models/RecurringSeriesTransition.php` | model | append-only | `Modules/Chains/Models/ChainResolutionRun.php` | role-match (audit row; reads-mostly) |

### State machine + sole-mutator invariant (Wave 1)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` | state machine | transactional write | `Modules/EmailScan/Internal/InboxScanStateMachine.php` | exact (DatabaseManager + Clock DI, ALLOWED_TRANSITIONS const map, `transaction()`+`PRAGMA busy_timeout=5000`+`lockForUpdate()` shape, history-row insert atomic with state update) |
| `tests/Contracts/BoundaryArchTest.php` (modify) — `noOtherRecurringSeriesStateMutator` | arch test | n/a | `tests/Contracts/BoundaryArchTest.php` lines 288–343 (`noOtherInboxScanStateMutator`) | exact (one-file allowlist + migrations exemption + grep on `->table('recurring_series')->update(...)` shape) |

### Detectors + cadence math (Waves 2 + 3)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/Public/Contracts/SeriesDetector.php` | contract | n/a | `Modules/Chains/Public/Contracts/DispatchesChainResolution.php` | role-match (Public interface implemented by Internal services; docblock names the container tag) |
| `Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php` | detector service | read-heavy + write to recurring_series | `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` | role-match (DI-only `final` class, scans transactions for a user, emits chain_links-style rows; here writes `recurring_series` + `recurring_series_occurrences` via the state machine) |
| `Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php` | detector service | same as above | `Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php` | role-match (sibling resolver pattern — both detectors register as container-tagged singletons in the ServiceProvider) |
| `Modules/Recurring/Internal/CadenceInferrer.php` | stateless service | pure-function transform | `Modules/EmailScan/Internal/MimeHeaderParser.php` / `Modules/EmailScan/Internal/SafeMessage.php` | role-match (stateless `final class` with public `infer(array): array` returning a structured array; no DB, no facades) |

### Queued job + scheduled trigger (Wave 2)

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|--------------------|------|-----------|----------------|---------------|
| `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` | queued job | event-driven | `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | exact (`ShouldBeUniqueUntilProcessing` + `ShouldQueue`, `$tries=3`, `$backoff=[60,300,900]`, `uniqueId()=userId`, `uniqueFor()=600`, `uniqueVia()` returning `Cache::driver('redis')`, `handle()` receives collaborators by method DI) |
| `routes/console.php` (modify) | scheduled trigger | n/a | `routes/console.php` lines 71–76 (the `email-scan.discovery` per-user daily dispatch closure) | exact (per-user enumeration + `Schedule::call()->name(...)->daily()->withoutOverlapping(30)` shape) |
| `tests/Contracts/BoundaryArchTest.php` (modify) — facade carve-out | arch test allowlist | n/a | `tests/Contracts/BoundaryArchTest.php` lines 47–75 — add `'Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob'` to the `ignoring([...])` array | exact |

### Public surface — Actions / Events / DTOs / Queries (Waves 1 + 2 + 3 + 4)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` | action | request-response (write) | `Modules/Chains/Public/Actions/ConfirmChainLink.php` | exact (`final class` with constructor-promoted DI, `__invoke(int, User): void`, cross-user 404 guard, idempotent no-op when already in target state, transactional state-machine transition + event dispatch) |
| `Modules/Recurring/Public/Actions/RejectRecurringSeries.php` | action | same | `Modules/Chains/Public/Actions/RejectChainLink.php` | exact |
| `Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php` | action | same | `Modules/Chains/Public/Actions/ConfirmChainLink.php` | role-match (extra `?CarbonImmutable $until` parameter; otherwise identical shape) |
| `Modules/Recurring/Public/Actions/EditRecurringSeriesName.php` | action | same | `Modules/Chains/Public/Actions/ConfirmChainLink.php` | role-match (string parameter; writes `display_name_override` directly without a state transition) |
| `Modules/Recurring/Public/Actions/UnRejectRecurringSeries.php` | action | same | `Modules/Chains/Public/Actions/ConfirmChainLink.php` | exact (state-machine transition `rejected → pending`) |
| `Modules/Recurring/Public/Events/RecurringSeriesDetected.php` (+ `Approved` / `Rejected` / `CadenceFlipped`) | event | event-driven | `Modules/Chains/Public/Events/*` and `Modules/Receipts/Public/Events/ChainHintDetected` | exact (final readonly DTO-shaped event class with constructor-promoted props, dispatched via `event(new ...)` in the action body — line 895 of RESEARCH.md example) |
| `Modules/Recurring/Public/Dto/RecurringSeriesDto.php` | DTO | n/a | `Modules/Chains/Public/Dto/ChainLinkRow.php` | exact (`final class extends Data`, constructor-promoted `public readonly` props, uses `Modules\Ledger\Public\ValueObjects\Money`) |
| `Modules/Recurring/Public/Dto/RecurringOccurrenceDto.php` | DTO | n/a | `Modules/Chains/Public/Dto/ChainLinkRow.php` | exact |
| `Modules/Recurring/Public/Dto/NextExpectedChargeDto.php` | DTO | n/a | `Modules/Chains/Public/Dto/CardStatementForecastTile.php` | exact |
| `Modules/Recurring/Public/Dto/RecurringSeriesAmountTrendDto.php` | DTO | n/a | `Modules/Chains/Public/Dto/ChainTree.php` (collection-shaped DTO) | role-match |
| `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` | read service | request-response (read) | `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` + `Modules/Chains/Public/Services/ChainLinkQuery.php` | exact (`final readonly class` with `DatabaseManager` DI, every method scopes by `user_id`, raw query-builder over Eloquent for whereBetween/whereIn) |
| `Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php` | read service | same | `Modules/Chains/Public/Services/ChainLinkQuery.php` (`forTransaction()` + cursor pagination) | exact |

### Livewire SFCs + dashboard contributor (Waves 2 + 3 + 4)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php` | Livewire SFC | request-response | `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` | exact (action-method DI; `cursorId` + `cursorConfidence` pagination state; `render(CurrentUser, Query, ViewFactory): View` shape) |
| `Modules/Recurring/Internal/Http/Livewire/RecurringPage.php` | Livewire SFC | request-response | `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` | role-match (same DI patterns; different view — grouped expense + income + transfer sections) |
| `Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php` | Livewire SFC | request-response | `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` (referenced by `ChainsServiceProvider` line 107) | role-match (drill-in detail view that takes a route param and pulls a tree-shaped DTO) |
| `Modules/Recurring/Internal/Http/Livewire/FixedPaymentsCard.php` | Livewire SFC | request-response | `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` (the inline dashboard variant has no class analog yet, but the action-method DI shape is identical) | role-match |
| `Modules/Recurring/Resources/views/livewire/*.blade.php` | view template | n/a | `Modules/Chains/Resources/views/livewire/*.blade.php` (loaded via `loadViewsFrom(..., 'chains')` — see `ChainsServiceProvider` line 103) | role-match — namespace `recurring::` per `loadViewsFrom(..., 'recurring')` in the new ServiceProvider |
| `Modules/Recurring/Routes/web.php` | route file | n/a | `Modules/Chains/Routes/web.php` (loaded via `loadRoutesFrom` — `ChainsServiceProvider` line 100) | exact |

### Boundary arch tests + contract test (Wave 0)

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|--------------------|------|-----------|----------------|---------------|
| `tests/Contracts/BoundaryArchTest.php` (modify) — Recurring/Internal use scoping | arch test | n/a | `tests/Contracts/BoundaryArchTest.php` lines 32–34 (`Modules\\Chains\\Internal is only used inside Modules\\Chains`) | exact (one-line `arch('...')->expect(...)->toOnlyBeUsedIn(...)` rule added beside the seven existing entries) |
| `tests/Contracts/BoundaryArchTest.php` (modify) — `noTransactionWritesFromRecurring` | arch test | n/a | `tests/Contracts/BoundaryArchTest.php` lines 237–286 (`noTransactionWritesFromEmailScan`) | exact (identical grep regex + identical directory-scan pattern + identical `expect($hits)->toBe([])` shape) |
| `tests/Contracts/BoundaryArchTest.php` (modify) — `noSynchronousDetectionInRequestLifecycle` | arch test | n/a | `tests/Contracts/BoundaryArchTest.php` lines 81–95 (`RederiveFingerprintsCommand is never imported by any HTTP or routing namespace`) | role-match (same `->expect(SeriesDetectorImplementor)->not->toBeUsedIn(['Modules\\Recurring\\Internal\\Http'])` shape) |
| `tests/Contracts/BoundaryArchTest.php` (modify) — `noFacadeCallsFromRecurring` carve-out | arch test allowlist | n/a | `tests/Contracts/BoundaryArchTest.php` lines 47–75 (existing `no Laravel facade usage in module code`) | exact — just add `'Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob'` to the existing `ignoring([...])` array; do NOT create a separate arch rule (the existing rule already scopes to `Modules`) |
| `tests/Contracts/RecurringDetectionContractTest.php` | contract test | n/a | `tests/Contracts/BoundaryArchTest.php` + the existing `IdempotencyContractTest` (referenced in CONTEXT line 173) | role-match (end-to-end test that loads fixture corpus, dispatches the job synchronously, asserts the resulting series set / states / cadences / metrics) |

### Module unit + feature tests (Waves 1–4)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/Recurring/tests/Unit/CadenceInferenceTest.php` | Pest dataset test | n/a | `Modules/Chains/tests/Unit/*Test.php` (any pure-function test under Chains' tests dir — Pest `dataset()` table over interval-list / expected-class pairs) | role-match |
| `Modules/Recurring/tests/Unit/RecurringSeriesStateMachineTest.php` | state-machine test | n/a | `Modules/EmailScan/tests/Unit/InboxScanStateMachineTest.php` (referenced indirectly via the EmailScan testing dir) | exact |
| `Modules/Recurring/tests/Feature/Approve*Test.php`, `Reject*Test.php`, `Snooze*Test.php`, `EditRecurringSeriesNameTest.php`, `UnReject*Test.php`, `RecurringPageTest.php`, `RecurringReviewPageTest.php`, `RecurringSeriesDetailPageTest.php`, `FixedPaymentsCardTest.php`, `DetectRecurringSeriesJobTest.php`, `CrossUserRecurringSeriesIsolationTest.php` | feature tests | n/a | `Modules/Chains/tests/Feature/*Test.php` (the Chains feature tests use `RefreshDatabase` + the per-module `TestCase`) | exact |
| `Modules/Recurring/tests/fixtures/synthesised/*.php` + `real/anonymised-asn-ics-6mo.php` | fixture data | n/a | `Modules/Chains/tests/fixtures/*` (Phase 5 D-107 precedent — each fixture returns a `list<CanonicalTransaction>` via a PHP factory script) | role-match |

### Cross-module touches (Wave 0)

| Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---------------|------|-----------|----------------|---------------|
| `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` (extend with batch method) | Public read service | request-response | `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` lines 30–77 (the existing single-row `latestForCounterpartyNormalized` method) | exact (add a sibling method `forCounterpartiesNormalized(User, list<string>): array<string, MerchantMemoryDto>` — same DI shape, same `user_id` scope, same MerchantMemoryDto return shape) |

### Files with no direct in-repo analog (use RESEARCH.md patterns + escalate)

| File | Reason |
|------|--------|
| `Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php` ApexCharts wiring | ApexCharts is not installed in the repo despite CONTEXT.md asserting it is. RESEARCH.md Pitfall 10 + Open Question Q1 flag this as a Wave 0 blocker. **No code analog exists** for ApexCharts JSON injection from Blade. Planner MUST escalate to the user BEFORE Wave 0 locks. Three viable paths in RESEARCH.md Q1: (1) install ApexCharts via `npm install apexcharts` (default), (2) Chart.js, (3) Tailwind-only SVG sparkline. |
| `Modules/Recurring/Internal/CadenceInferrer.php` median-snap algorithm body | Locked by D-843 + D-844; no in-repo prior. Algorithm template in RESEARCH.md Pattern 4 lines 603–646. |

---

## Pattern Assignments

### `Modules/Recurring/composer.json` (composer)

**Analog:** `Modules/Chains/composer.json` (the entire 16-line file)

**Copy verbatim, swap names** (Chains composer lines 1–16):

```jsonc
{
    "name": "diederik/recurring",
    "description": "Recurring module — series detection, fixed-payments view, drill-in chart.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Recurring\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Recurring\\Tests\\": "tests/"
        }
    }
}
```

**Note:** Neither `Modules/Chains/` nor `Modules/Receipts/` carries a `module.json` file (verified — only `Modules/Categorization/`, `Modules/Ledger/`, `Modules/Ingestion/`, `Modules/Core/`, and `Modules/Import/` have one). Phase 4+ modules are registered exclusively via `bootstrap/providers.php`. Planner SHOULD follow the Phase-5-and-later convention: register the provider in `bootstrap/providers.php` and skip `module.json`. CONTEXT.md / RESEARCH.md references to a `module.json` are stale — Phase 5 dropped it.

---

### `Modules/Recurring/Providers/RecurringServiceProvider.php` (service provider)

**Analog:** `Modules/Chains/Providers/ChainsServiceProvider.php` (entire file, 236 lines)

**Register pattern** (Chains lines 69–92):
```php
public function register(): void
{
    $this->app->singleton(RecurringSeriesStateMachine::class);
    $this->app->singleton(CadenceInferrer::class);
    $this->app->singleton(ExpenseSeriesDetector::class);
    $this->app->singleton(IncomeSeriesDetector::class);
    $this->app->singleton(DetectRecurringSeriesJob::class);

    // Container-tagged detectors (D-849) — the job receives the set
    // via iterable injection on handle().
    $this->app->tag([
        ExpenseSeriesDetector::class,
        IncomeSeriesDetector::class,
    ], 'recurring.detector');

    // Public surface
    $this->app->singleton(RecurringSeriesQuery::class);
    $this->app->singleton(FixedPaymentsViewQuery::class);
    $this->app->singleton(ApproveRecurringSeries::class);
    $this->app->singleton(RejectRecurringSeries::class);
    $this->app->singleton(SnoozeRecurringSeries::class);
    $this->app->singleton(EditRecurringSeriesName::class);
    $this->app->singleton(UnRejectRecurringSeries::class);
}
```

**Boot pattern** (Chains lines 94–120):
```php
public function boot(LivewireManager $livewire, Dispatcher $events): void
{
    if (is_dir(__DIR__.'/../Database/Migrations')) {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
    if (is_file(__DIR__.'/../Routes/web.php')) {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    }
    if (is_dir(__DIR__.'/../Resources/views')) {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'recurring');
    }

    $livewire->component('recurring.recurring-page', RecurringPage::class);
    $livewire->component('recurring.recurring-review-page', RecurringReviewPage::class);
    $livewire->component('recurring.recurring-series-detail-page', RecurringSeriesDetailPage::class);
    $livewire->component('recurring.fixed-payments-card', FixedPaymentsCard::class);

    $this->registerTopNavBadgeComposer();
    $this->registerDashboardCardComposer();
}
```

**Top-nav badge composer pattern** (Chains lines 139–154) — pending-count badge:
```php
private function registerTopNavBadgeComposer(): void
{
    $app = $this->app;
    $factory = $app->make(ViewFactoryContract::class);

    $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
        $currentUser = $app->make(CurrentUser::class);
        if (! $currentUser->isAuthenticated()) {
            $compose->with('recurringPendingCount', 0);
            return;
        }
        $query = $app->make(RecurringSeriesQuery::class);
        $compose->with('recurringPendingCount', $query->pendingCountForUser($currentUser->user()));
    });
}
```

> Critical: **Never** use `view()->composer(...)`. The Chains class explicitly documents this carve-out (lines 122–138) — Phase 5 issue #12 fix.

---

### `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` (state machine)

**Analog:** `Modules/EmailScan/Internal/InboxScanStateMachine.php` (entire file, 347 lines)

**Class header + DI** (InboxScanStateMachine lines 70–120):
```php
final class RecurringSeriesStateMachine
{
    /**
     * Per-state allowed-target map. A transition not present in this
     * map raises InvalidStateTransitionException — there is no
     * "any state → any state" escape hatch.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'pending'         => ['approved', 'rejected', 'snoozed'],
        'approved'        => ['cadence_changed', 'rejected'],
        'cadence_changed' => ['approved', 'rejected'],
        'snoozed'         => ['pending', 'approved', 'rejected'],
        'rejected'        => ['pending'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}
```

**Sole-mutator transition shape** (InboxScanStateMachine lines 122–165):
```php
public function transition(
    RecurringSeries $series,
    string $toState,
    string $reason,
    string $actor,
    ?string $notes = null,
): void {
    $this->db->connection()->transaction(function () use ($series, $toState, $reason, $actor, $notes): void {
        $connection = $this->db->connection();
        $connection->statement('PRAGMA busy_timeout = 5000');

        $row = $connection->table('recurring_series')
            ->where('id', $series->id)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException(
                "RecurringSeriesStateMachine: recurring_series row {$series->id} not found.",
            );
        }

        $currentState = self::toString($row->state);
        $this->guardTransition($series->id, $currentState, $toState);

        $now = $this->clock->now()->toDateTimeString();

        // Atomic: update state on series + insert transition audit row
        $connection->table('recurring_series')
            ->where('id', $series->id)
            ->update(['state' => $toState, 'updated_at' => $now]);

        $connection->table('recurring_series_transitions')->insert([
            'user_id' => self::toInt($row->user_id),
            'recurring_series_id' => $series->id,
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
```

> **Critical pattern** (RESEARCH Pitfall 3): metric refresh (amount / monthly_equivalent / next_expected_at / latest_funding_chain_link_id) writes the series row **without** touching the `state` column **and without** inserting a transitions row. The `noOtherRecurringSeriesStateMutator` arch test only watches the `state` column, not the whole row — mirror `noOtherInboxScanStateMutator` (BoundaryArchTest lines 288–343) which already uses this column-targeted shape.

---

### `Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob.php` (queued job)

**Analog:** `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` (entire file, 143 lines)

**Class declaration + traits** (ResolveChainLinksJob lines 57–63):
```php
final class DetectRecurringSeriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
```

**Tries + backoff + unique config** (ResolveChainLinksJob lines 64–96):
```php
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        // The Cache facade is the single permitted facade use in
        // module code (BoundaryArchTest carve-out). Reason: Laravel
        // calls uniqueVia() before constructor DI completes — there
        // is no path to inject a Repository at this point.
        return Cache::driver('redis');
    }
```

**Handle with iterable DI for container-tagged detectors** (ResolveChainLinksJob lines 98–142, with D-849 adjustment):
```php
    /**
     * @param iterable<SeriesDetector> $detectors  Container-tagged 'recurring.detector'
     */
    public function handle(
        DatabaseManager $db,
        Clock $clock,
        iterable $detectors,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();

        // Snooze-expiry pass FIRST (Pitfall 8): flip snoozed → pending
        // where snoozed_until has elapsed. Goes through the state machine.
        // ...

        foreach ($detectors as $detector) {
            $detector->detectForUser($user);
        }
    }
}
```

> Planner MUST add `'Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob'` to the existing `ignoring([...])` array in `tests/Contracts/BoundaryArchTest.php` lines 47–75. This is the single permitted facade carve-out.

---

### `routes/console.php` (modify — scheduled trigger)

**Analog:** `routes/console.php` lines 71–76 (the `email-scan.discovery` daily entry)

**Add a new daily-per-user dispatch closure** (matching email-scan.discovery exactly):
```php
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $userIds = $db->connection()->table('users')->pluck('id');
    foreach ($userIds as $id) {
        $bus->dispatch(new DetectRecurringSeriesJob((int) $id));
    }
})->name('recurring.detect')->daily()->withoutOverlapping(30);
```

> Critical: method order is `.name()` BEFORE `.daily()->withoutOverlapping(30)` — Laravel `CallbackEvent::withoutOverlapping` throws when description is null. The existing entries (lines 57, 76, 93, 112) document this gotcha inline.

---

### `Modules/Recurring/Public/Actions/ApproveRecurringSeries.php` (Public action)

**Analog:** `Modules/Chains/Public/Actions/ConfirmChainLink.php` (entire file, 106 lines)

**Class declaration + DI** (ConfirmChainLink lines 43–50):
```php
final class ApproveRecurringSeries
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RecurringSeriesStateMachine $stateMachine,
        private readonly Clock $clock,
    ) {}
```

**Cross-user 404 guard + idempotency** (ConfirmChainLink lines 52–68):
```php
    public function __invoke(int $seriesId, User $user): void
    {
        /** @var RecurringSeries|null $series */
        $series = RecurringSeries::query()
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($series === null) {
            // Surface the canonical 404. Throwing it explicitly here
            // keeps the contract testable outside the HTTP boundary
            // (Eloquent ModelNotFoundException would not show up in
            // bare unit tests).
            throw new NotFoundHttpException('Recurring series not found.');
        }
        if ($series->state === 'approved') {
            return; // idempotent no-op
        }

        $this->stateMachine->transition(
            $series,
            toState: 'approved',
            reason: 'user_action',
            actor: 'user',
        );

        event(new RecurringSeriesApproved(seriesId: $series->id, userId: $user->id, /* ... */));
    }
}
```

> Apply this exact shape to `RejectRecurringSeries`, `SnoozeRecurringSeries` (extra `?CarbonImmutable $until` param), `EditRecurringSeriesName` (string param; no state transition — writes `display_name_override` directly via DatabaseManager), and `UnRejectRecurringSeries` (transitions `rejected → pending`).

---

### `Modules/Recurring/Public/Services/RecurringSeriesQuery.php` (Public read service)

**Analog:** `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` (entire file, 78 lines) + `Modules/Chains/Public/Services/ChainLinkQuery.php` lines 46–80 (the `final class` cursor-paginated read service variant)

**Class declaration** (MerchantMemoryQuery lines 30–32):
```php
final readonly class RecurringSeriesQuery
{
    public function __construct(private DatabaseManager $db) {}
```

**Read method shape with mandatory user_id scope** (MerchantMemoryQuery lines 34–71):
```php
    public function approvedForUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('state', 'approved')
            ->orderByDesc('monthly_equivalent_minor')
            ->get();

        // Map raw rows → RecurringSeriesDto via Money::ofMinor + careful scalar coercion
        return array_map(fn (stdClass $r) => $this->toDto($r), $rows->all());
    }

    public function pendingCountForUser(User $user): int
    {
        return $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('state', ['pending', 'cadence_changed'])
            ->count();
    }
```

> Critical: every public method takes `User $user` as the first parameter and filters on `user_id = $user->id`. Cross-user 404s land in the Actions; the Queries return empty collections for foreign-user reads.

---

### `Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php` (Livewire SFC)

**Analog:** `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` (entire file, 96 lines)

**Class declaration** (ChainReviewQueue lines 42–55):
```php
final class RecurringReviewPage extends Component
{
    public ?int $cursorId = null;
    public ?string $cursorAmount = null;   // optional secondary cursor

    /** @var array<int, int> selected series ids for bulk action bar (D-812) */
    public array $selectedIds = [];

    public string $tab = 'pending';   // 'pending' | 'rejected' | 'cadence_changed'
```

**Action-method DI shape** (ChainReviewQueue lines 57–65) — critical phpstan-strict-rules constraint:
```php
    public function approve(int $seriesId, CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->dispatch('toast', message: 'Approved', undoAction: 'reject', undoPayload: $seriesId);
    }

    public function reject(int $seriesId, CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->dispatch('toast', message: 'Rejected', undoAction: 'unReject', undoPayload: $seriesId);
    }

    public function bulkApprove(CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        $count = count($this->selectedIds);
        foreach ($this->selectedIds as $id) {
            ($action)($id, $currentUser->user());
        }
        $this->selectedIds = [];
        $this->dispatch('toast', message: "{$count} approved");
    }
```

**Render method DI shape** (ChainReviewQueue lines 73–94):
```php
    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $pending = $query->pendingForUser(
            user: $user,
            cursorId: $this->cursorId,
            limit: 26,
        );

        $view = $views->make('recurring::livewire.recurring-review-page', [
            'pending' => $pending,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Review recurring · diederik']);

        return $view;
    }
}
```

> Critical (ChainReviewQueue lines 40–41): **Constructor injection is banned on Livewire `Component` subclasses by phpstan-strict-rules.** Service collaborators MUST arrive as parameters on action methods + `render()`.

---

### `Modules/Recurring/Models/RecurringSeries.php` (Eloquent model)

**Analog:** `Modules/Chains/Models/ChainLink.php` (entire file, 83 lines)

**Header + BelongsToUser concern** (ChainLink lines 1–47):
```php
namespace Modules\Recurring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Chains\Models\ChainLink;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $direction
 * @property string $detected_name
 * @property ?string $display_name_override
 * @property string $state
 * @property string $cadence
 * @property int $latest_amount_minor
 * @property string $latest_currency
 * @property ?int $latest_funding_chain_link_id
 * @property int $variance_tolerance_percent
 * @property ?CarbonImmutable $snoozed_until
 * @property ?CarbonImmutable $next_expected_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RecurringSeries extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'direction', 'detected_name', 'display_name_override',
        'state', 'cadence', 'latest_amount_minor', 'latest_currency',
        'latest_fx_rate_used', 'monthly_equivalent_minor',
        'variance_tolerance_percent', 'latest_funding_chain_link_id',
        'snoozed_until', 'next_expected_at',
        'next_expected_confidence_low', 'cluster_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snoozed_until' => 'immutable_datetime',
            'next_expected_at' => 'immutable_date',
            'next_expected_confidence_low' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ChainLink, $this> */
    public function latestFundingChainLink(): BelongsTo
    {
        return $this->belongsTo(ChainLink::class, 'latest_funding_chain_link_id');
    }
}
```

---

### `Modules/Recurring/Public/Dto/RecurringSeriesDto.php` (DTO)

**Analog:** `Modules/Chains/Public/Dto/ChainLinkRow.php` (entire file, 37 lines)

**Class declaration** (ChainLinkRow lines 22–36):
```php
final class RecurringSeriesDto extends Data
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $direction,              // 'expense' | 'income'
        public readonly string $detectedName,
        public readonly ?string $displayNameOverride,
        public readonly string $state,
        public readonly string $cadence,                // weekly | monthly | quarterly | yearly | irregular
        public readonly Money $latestAmount,             // original currency
        public readonly ?Money $eurEquivalent,           // null when original = EUR
        public readonly Money $monthlyEquivalent,        // EUR, computed via cadence multiplier
        public readonly ?int $latestFundingChainLinkId,
        public readonly ?CarbonImmutable $nextExpectedAt,
        public readonly bool $nextExpectedConfidenceLow,
        public readonly int $varianceTolerancePercent,   // default 25
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}

    public function displayName(): string
    {
        return $this->displayNameOverride ?? $this->detectedName;
    }
}
```

> Use `Modules\Ledger\Public\ValueObjects\Money` (the same Money VO ChainLinkRow imports). Never accept floats.

---

### Migration: `create_recurring_series_table.php`

**Analog:** `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` (entire file, 98 lines)

**Migration class shape** (card_statements lines 39–97):
```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('recurring_series', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();      // FND-03
            $t->enum('direction', ['expense', 'income']);                                       // D-816
            $t->string('detected_name');
            $t->string('display_name_override')->nullable();                                    // D-813
            $t->string('state', 24)->default('pending');                                        // D-815 set
            $t->string('cadence', 24)->default('irregular');                                    // D-848
            $t->bigInteger('latest_amount_minor');                                              // FND-04
            $t->string('latest_currency', 3);
            $t->string('latest_fx_rate_used')->nullable();                                      // D-851
            $t->bigInteger('monthly_equivalent_minor')->nullable();                             // D-826
            $t->unsignedTinyInteger('variance_tolerance_percent')->default(25);                 // D-825
            $t->foreignId('latest_funding_chain_link_id')->nullable()
                ->constrained('chain_links')->nullOnDelete();                                   // D-828/D-829
            $t->timestamp('snoozed_until')->nullable();                                         // D-810
            $t->date('next_expected_at')->nullable();
            $t->boolean('next_expected_confidence_low')->default(false);                        // D-830
            $t->string('cluster_key');
            $t->timestamps();

            $t->unique(['user_id', 'direction', 'cluster_key', 'latest_currency'], 'rec_series_uniq');
            $t->index(['user_id', 'state']);                                                    // top-nav badge query
            $t->index(['user_id', 'state', 'next_expected_at']);                                // 'This month only' toggle
        });

        // Phase 1 precedent for state-trigger validation (transactions.type)
        // — same idiom card_statements uses on its state column.
        $connection = $this->db()->connection($this->getConnection());
        $allowed = "'pending','approved','rejected','snoozed','cadence_changed'";
        $connection->statement(sprintf(
            "CREATE TRIGGER recurring_series_state_check_insert BEFORE INSERT ON recurring_series FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.state value'); END",
            $allowed,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER recurring_series_state_check_update BEFORE UPDATE OF state ON recurring_series FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.state value'); END",
            $allowed,
        ));
    }
    // down() + schema() + db() helpers identical to card_statements lines 78–97
};
```

> Match the **anonymous-class migration** shape exactly — `private ?DatabaseManager $resolvedDb`, `schema()` and `db()` accessor methods. Direct facade use (`Schema::create(...)`, `DB::statement(...)`) is forbidden by the project's DI-only invariant even in migrations.

---

### Arch test: `noTransactionWritesFromRecurring`

**Analog:** `tests/Contracts/BoundaryArchTest.php` lines 237–286 (`noTransactionWritesFromEmailScan`)

**Drop-in pattern** (the existing EmailScan test, retargeted at `Modules/Recurring`):
```php
it('does not allow any file under Modules/Recurring/ to mutate the transactions table (noTransactionWritesFromRecurring)', function (): void {
    // Phase 8 architectural boundary: the Recurring module is analytical-
    // only. Transaction-type ownership stays with Phase 4 LED-05. Mirrors
    // the EmailScan noTransactionWritesFromEmailScan invariant.
    $hits = [];
    $recurringDir = base_path('Modules/Recurring');
    if (! is_dir($recurringDir)) {
        expect(true)->toBeTrue();
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($recurringDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile()) continue;
        $path = $file->getPathname();
        if (preg_match('/\.php$/', $path) !== 1) continue;
        if (str_contains($path, '/tests/')) continue;
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/Transaction::query|Transaction::where|Transaction::create/', $stripped) === 1
            || preg_match("/->table\(['\"]transactions['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
        ) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([], "Modules/Recurring/ must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits));
});
```

---

### Arch test: `noOtherRecurringSeriesStateMutator`

**Analog:** `tests/Contracts/BoundaryArchTest.php` lines 288–343 (`noOtherInboxScanStateMutator`)

**Drop-in pattern** — copy the EmailScan test verbatim with these substitutions:
- `'Modules/EmailScan'` → `'Modules/Recurring'`
- `Modules/EmailScan/Internal/InboxScanStateMachine.php` → `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php`
- `'inbox_scan_state'` → `'recurring_series'`
- The Eloquent shape regex `InboxScanState::query()` → `RecurringSeries::query()`

> Key invariant (line 296 of BoundaryArchTest): the regex targets `->update(...)` shape on the table specifically, so `insertOrIgnore` + migration inserts stay legal. Mirror that shape so metric-refresh INSERTs (`recurring_series_occurrences`) are not falsely flagged.

---

### Arch test: `noSynchronousDetectionInRequestLifecycle`

**Analog:** `tests/Contracts/BoundaryArchTest.php` lines 81–95 (`RederiveFingerprintsCommand is never imported by any HTTP or routing namespace`)

**Drop-in pattern** — use the marker interface mechanism (RESEARCH Q4 recommendation):
```php
arch('SeriesDetector implementors are never imported by Modules\\Recurring\\Internal\\Http')
    ->expect('Modules\\Recurring\\Public\\Contracts\\SeriesDetector')
    ->not->toBeUsedIn([
        'Modules\\Recurring\\Internal\\Http',
        'Modules\\Recurring\\Resources',
    ]);
```

---

### Facade carve-out: `noFacadeCallsFromRecurring`

**Analog:** `tests/Contracts/BoundaryArchTest.php` lines 44–75 (the existing `no Laravel facade usage in module code` rule)

**Do NOT add a separate rule.** The existing rule already scopes via `->not->toBeUsedIn('Modules')` — it covers `Modules/Recurring/` automatically. Planner just appends one FQN to the `ignoring([...])` array (mirrors the four existing entries on lines 54, 61, 62, 63, 70, 74):
```php
'Modules\\Recurring\\Internal\\Jobs\\DetectRecurringSeriesJob',
```

> The job's class docblock MUST repeat the carve-out rationale verbatim (mirror `ResolveChainLinksJob` lines 44–49 + 89–96). This is the BoundaryArchTest's documented contract.

---

## Shared Patterns

### Pattern S-1: Constructor DI for Public services + Internal services

**Source files:** `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` line 32, `Modules/EmailScan/Internal/InboxScanStateMachine.php` line 118, `Modules/Chains/Public/Actions/ConfirmChainLink.php` lines 47–50.

**Apply to:** Every new file under `Modules/Recurring/` **except** Livewire `Component` subclasses (which use method-parameter DI — see Pattern S-2).

**Excerpt:**
```php
final readonly class RecurringSeriesQuery
{
    public function __construct(private DatabaseManager $db) {}
}

// or, for non-readonly services that need Clock:
final class RecurringSeriesStateMachine
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}
}

// or, for Public Actions:
final class ApproveRecurringSeries
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RecurringSeriesStateMachine $stateMachine,
        private readonly Clock $clock,
    ) {}
}
```

### Pattern S-2: Method-parameter DI on Livewire components

**Source:** `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` lines 57–84 (class-level docblock at lines 40–41 explains why).

**Apply to:** `RecurringReviewPage`, `RecurringPage`, `RecurringSeriesDetailPage`, `FixedPaymentsCard`.

**Excerpt:**
```php
final class RecurringReviewPage extends Component
{
    public ?int $cursorId = null;
    public array $selectedIds = [];

    // Action methods take collaborators as parameters
    public function approve(int $seriesId, CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
    }

    // render() takes ViewFactory + every query/service it needs
    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
    ): View { /* ... */ }
}
```

### Pattern S-3: Cross-user 404 guard on every Public Action

**Source:** `Modules/Chains/Public/Actions/ConfirmChainLink.php` lines 52–68.

**Apply to:** All five Public Actions (`Approve` / `Reject` / `Snooze` / `EditName` / `UnReject`), plus every read query that takes a single-id parameter (`RecurringSeriesQuery::forSeries(int, User)`).

**Excerpt:**
```php
public function __invoke(int $seriesId, User $user): void
{
    /** @var RecurringSeries|null $series */
    $series = RecurringSeries::query()
        ->where('id', $seriesId)
        ->where('user_id', $user->id)
        ->first();

    if ($series === null) {
        throw new NotFoundHttpException('Recurring series not found.');
    }
    // ...
}
```

### Pattern S-4: SQLite WAL + busy_timeout + lockForUpdate on every state-machine write

**Source:** `Modules/EmailScan/Internal/InboxScanStateMachine.php` lines 127–164 (and every other write method in the class — `applyRateLimited`, `resetRetryAttempts`, `recordCursor`, `recordBackfillProgress`).

**Apply to:** Every method on `RecurringSeriesStateMachine` that writes the `state` column.

**Excerpt** (the load-bearing fence):
```php
$this->db->connection()->transaction(function () use (...): void {
    $connection = $this->db->connection();
    $connection->statement('PRAGMA busy_timeout = 5000');

    $row = $connection->table('recurring_series')
        ->where('id', $series->id)
        ->lockForUpdate()
        ->first();

    // ... validate transition, then update state + insert transition audit row
});
```

### Pattern S-5: View Factory contract for composers (NEVER `view()->composer`)

**Source:** `Modules/Chains/Providers/ChainsServiceProvider.php` lines 122–154 — the issue #12 fix is documented in the docblock.

**Apply to:** Top-nav "Recurring" badge composer + dashboard "Fixed monthly payments" card composer.

**Excerpt:**
```php
private function registerTopNavBadgeComposer(): void
{
    $app = $this->app;
    $factory = $app->make(ViewFactoryContract::class);

    $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
        // resolve dependencies inside the composer closure to avoid
        // doing work at boot for views that never render.
        $currentUser = $app->make(CurrentUser::class);
        if (! $currentUser->isAuthenticated()) {
            $compose->with('recurringPendingCount', 0);
            return;
        }
        $query = $app->make(RecurringSeriesQuery::class);
        $compose->with('recurringPendingCount', $query->pendingCountForUser($currentUser->user()));
    });
}
```

### Pattern S-6: BelongsToUser concern on every Eloquent model

**Source:** `Modules/Chains/Models/ChainLink.php` line 47 (`use BelongsToUser;`).

**Apply to:** `RecurringSeries`, `RecurringSeriesOccurrence`, `RecurringSeriesTransition`.

> The concern lives at `Modules\Core\Public\Concerns\BelongsToUser` (verified — referenced from ChainLink line 10). It provides the `user()` BelongsTo relation + scope hooks that the project relies on for cross-user isolation.

### Pattern S-7: 3-step test-suite registration for a new module

**Source:** Phase 4 D-80b pattern, verified against `Modules/Receipts/tests/Pest.php` and `Modules/Receipts/tests/TestCase.php`.

**Apply to:** Wave 0 — register the new test suite.

**Step 1 — `Modules/Recurring/tests/TestCase.php`** (copy Receipts TestCase verbatim, swap namespace):
```php
namespace Modules\Recurring\Tests;

use Tests\TestCase as RootTestCase;

abstract class TestCase extends RootTestCase {}
```

**Step 2 — `Modules/Recurring/tests/Pest.php`** (copy Receipts Pest.php verbatim, swap namespace):
```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Recurring\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
```

**Step 3 — modify three root files:**
- `composer.json` autoload-dev: add `"Modules\\Recurring\\Tests\\": "Modules/Recurring/tests/"`
- `phpunit.xml`: add Recurring Unit + Feature directories to existing testsuites
- `tests/Pest.php`: add the per-module TestCase map entry
- `bootstrap/providers.php`: add `RecurringServiceProvider::class` to the array (matches the seven existing module entries — see lines 7–27)

---

## No Analog Found

| File | Reason | Planner Action |
|------|--------|----------------|
| `Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php` — ApexCharts wiring | ApexCharts is **not installed**. RESEARCH.md Pitfall 10 + Open Q1 flag this as a Wave 0 blocker. | **Escalate to the user before Wave 0 locks.** Three viable paths in RESEARCH.md (install ApexCharts / install Chart.js / Tailwind-only SVG sparkline). Planner MUST NOT plan Wave 4 chart rendering until Q1 resolves. |
| `Modules/Recurring/Internal/CadenceInferrer.php` body | Locked algorithm (D-843 + D-844). No working in-repo prior. | Use the algorithm template in RESEARCH.md Pattern 4 lines 603–646. The class shape (final, stateless, single `infer(array): array` method) mirrors other stateless helpers like `Modules/EmailScan/Internal/MimeHeaderParser.php`. |
| Snooze-expiry pass inside `DetectRecurringSeriesJob::handle()` | Pitfall 8 — no analog because no prior module needed it. | Implement as the first step inside `handle()` BEFORE looping the tagged detectors. Go through the state machine so the audit row records `snoozed → pending` with `transition_reason='snooze_expired'`, `actor='detector'`. |
| `users` table migration adding `recurring_detection_window_months` + `recurring_income_min_amount_minor` | No exact analog file accessible. | Follow Phase 3 `default_currency_view` + Phase 6 `auto_import_drop_folder` add-column migrations on `users` (RESEARCH.md A6). Standard `add_*_to_users.php` anonymous-class migration; non-destructive ALTER TABLE shape. |

---

## Metadata

**Analog search scope:**
- `Modules/Chains/` — primary template for: module skeleton, queued job, state machine column triggers, Eloquent models, Livewire review queue, Public Actions, Public DTOs, top-nav composer, route loading
- `Modules/EmailScan/` — primary template for: sole-mutator state machine (`InboxScanStateMachine`)
- `Modules/Categorization/` — primary template for: `final readonly` Public read service shape (`MerchantMemoryQuery`) — and the file that gets the new batch-method extension (D-834)
- `Modules/Receipts/` — primary template for: module test bootstrap (`Pest.php` + `TestCase.php`)
- `tests/Contracts/BoundaryArchTest.php` — primary template for: every arch invariant Phase 8 adds (the EmailScan invariants are the closest mirrors)
- `routes/console.php` — primary template for: per-user daily `Schedule::call(...)` dispatch closure (`email-scan.discovery`)
- `bootstrap/providers.php` — primary template for: module provider registration

**Files scanned:** 11 source files Read + 1 RESEARCH.md (1,236 lines) + 1 CONTEXT.md (297 lines) + targeted Grep across `Modules/`, `tests/`, `bootstrap/`, `routes/`.

**Pattern extraction date:** 2026-05-17

---

## PATTERN MAPPING COMPLETE
