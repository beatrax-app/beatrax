# Phase 11: Operational Hardening - Pattern Map

**Mapped:** 2026-05-19
**Files analyzed:** 29 new + 4 modified = 33 surfaces
**Analogs found:** 33 / 33 (every new file has at least one concrete in-tree analog)

---

## File Classification

### New console commands + scheduling

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Internal/Console/BackupDatabaseCommand.php` | Console command | request-response (file I/O + SQL DDL) | `Modules/Core/Internal/Console/InstallCommand.php` | role-exact (same dir, same Command shape, constructor DI of `DatabaseManager` + `Filesystem`) |
| `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` | Console command | request-response (file I/O + SQL DDL + maintenance-mode lifecycle) | `Modules/Core/Internal/Console/InstallCommand.php` | role-exact (constructor DI of `DatabaseManager`, `Filesystem`, `Kernel`/`Repository`) |
| `Modules/Core/Internal/Console/FailedJobsCommand.php` | Console command (subcommand-style) | CRUD on `failed_jobs` table | `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` | role-match (signature with multiple options, `--dry-run` flag, summary output, return code reporter helper) |
| `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php` | Value object (pure logic) | transform (in-memory list filter) | `Modules/Forecasting/Internal/Support/AmountStringParser.php` | role-exact (sibling `Support/` value object dir-pattern, `final` class, static-only API, no IO) |
| `Modules/Core/Internal/Console/Probes/Probe.php` | Interface (contract) | n/a | `Modules/Core/Public/Contracts/Clock.php` | role-match (small contract interface, single method, well-formed PHPDoc) |
| `Modules/Core/Internal/Console/Probes/ProbeResult.php` | Immutable value object | n/a | `Modules/Core/Public/Contracts/CurrentUser.php` (shape only) + RESEARCH §Pattern 7 (verbatim sketch) | role-match (`final readonly class`, constructor-promoted properties, scalar fields) |
| `Modules/Core/Internal/Console/Probes/WalModeProbe.php` | Probe implementation | request-response (PRAGMA read) | `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` | partial (same `connection()->statement('PRAGMA …')` mechanic but as a Probe value class — RESEARCH §Pattern 7 is the canonical sketch) |
| `Modules/Core/Internal/Console/Probes/SynchronousModeProbe.php` | Probe implementation | request-response (PRAGMA read) | `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` | partial (mirror of WalModeProbe, different PRAGMA) |
| `Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php` | Probe implementation | file-I/O + Eloquent write | `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` (PRAGMA shape) + `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` (Eloquent-write side-effect from probe) | partial (no single in-tree precedent — RESEARCH §Pattern 7 explicitly describes the merge) |
| `Modules/Core/Internal/Console/DoctorCommand.php` (MODIFIED) | Console command (extension) | request-response | `Modules/Core/Internal/Console/DoctorCommand.php` (in-place refactor) | exact (file already exists; replace inline `reportTool()` internals with `Probe[]` iteration per RESEARCH §Pattern 7) |
| `routes/console.php` (MODIFIED — append) | Scheduler entry | event-driven (cron-triggered) | `routes/console.php` lines 40–191 (seven existing entries) | exact (append a `Schedule::command(...)->name(...)->dailyAt('03:00')->withoutOverlapping(60)` block at the end of the file) |

### New persistence surface (system_alerts)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Database/Migrations/{timestamp}_create_system_alerts_table.php` | Migration | DDL | `Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php` | exact (anonymous-class migration with `private ?DatabaseManager $resolvedDb`, `schema()` + `db()` helpers, optional severity-enum trigger pair). Phase 10 alternative: `Modules/Forecasting/Database/Migrations/2026_05_19_010005_add_forecast_columns_to_accounts.php` for a simpler `Schema::table` shape. |
| `Modules/Core/Models/SystemAlert.php` | Eloquent model | CRUD | `Modules/DriftAlerts/Models/DriftAlert.php` | exact (`final class … extends Model`, `use BelongsToUser`, `use HasFactory`, `casts()` method returning `array<string,string>`, scopes via query builder methods) |
| `Modules/Core/Public/Services/SystemAlertQuery.php` | Public read service | CRUD (read-only) | `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` | exact (`final readonly class` with constructor-promoted `DatabaseManager` + `Clock`, per-user scoping, returns Collection / int) |
| `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` | Public action | CRUD (mutate) | `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` | exact (`final class`, constructor DI, `__invoke(int $alertId, User $user)`, 404 via `NotFoundHttpException`, idempotent on already-acknowledged, dispatch event optional) |

### New Livewire SFC + layout slot

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | Livewire 4 Component | request-response | `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php` | exact (method-parameter DI on `render()`, `CurrentUser + Query + ViewFactory` triple, `acknowledge(int $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser)` action method matches the DriftAlerts pattern verbatim) |
| `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` | Blade SFC | server-render | `Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php` | exact (`rounded-lg border p-4` row shell, `flex items-start justify-between gap-4`, inline SVG icons, severity-tinted button classes, `wire:click="acknowledge({{ $alert->id }})"`) |
| `resources/views/layouts/app.blade.php` (MODIFIED) | Layout slot | server-render | `resources/views/layouts/app.blade.php` lines 12–17 (existing `@auth` block with three Livewire mounts) | exact (one new line `@livewire('core.system-alerts-banner')` between `core.top-nav` and `categorization.rule-form-modal`) |

### New boot-time health-check provider

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Internal/Providers/HealthCheckServiceProvider.php` | Service provider (boot-time) | request-response (PRAGMA read on first connection) + write to `system_alerts` | `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` | exact (`final class … extends ServiceProvider`, `boot(Dispatcher $events)`, listen `ConnectionEstablished`, branch on `getDriverName() === 'sqlite'`, `connection->statement('PRAGMA …')`) — Phase 11's variant ADDS Eloquent-write side-effects after the read; the listener body is the only structural divergence. |
| `Modules/Core/Providers/CoreServiceProvider.php` (MODIFIED) | Service provider | n/a | `Modules/Core/Providers/CoreServiceProvider.php` (current file) | exact (one new `$this->app->register(HealthCheckServiceProvider::class);` line in `register()`; `commands([])` array extended with the three new commands; `livewire->component('core.system-alerts-banner', SystemAlertsBanner::class)` added in `boot()`) |

### Arch tests

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `tests/Contracts/BoundaryArchTest.php` (MODIFIED — append) | Arch invariants | n/a | `tests/Contracts/BoundaryArchTest.php` lines 834–879 (`noScenarioMutationsJoinedToTransactionQueries` block) | exact (one new `it(…)` block per invariant; `RecursiveIteratorIterator` + comment-stripping `preg_replace('#/\*.*?\*/\|//[^\n]*#s', …)`; regex hit-collection into `$hits[]`; final `expect($hits)->toBe([], "…")`) |
| `tests/Contracts/HorizonForceFlagTest.php` | Arch invariant | n/a | `tests/Contracts/BoundaryArchTest.php` (any `it(…)` block) — RESEARCH §A2 assumption | role-match (one-off Pest file asserting Horizon's `config/horizon.php` does NOT set `force: true` on any supervisor — same `it(…)` + file-grep shape) |

### Module tests (new)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `tests/Feature/Core/SystemAlertsMigrationTest.php` (or `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php`) | Migration unit test | DDL assertion | `Modules/DriftAlerts/tests/Unit/DriftAlertsMigrationTest.php` | exact (column existence loop, severity-enum trigger rejects, index assertions, `Pest dataset->with([...])` for allowed severities) |
| `Modules/Core/tests/Unit/SystemAlertModelTest.php` | Model unit | CRUD | `Modules/DriftAlerts/tests/Unit/DriftAlertDtoTest.php` (DTO shape) + Eloquent-cast assertions on existing DriftAlert model | role-match (assert casts, scopes `active()` + `byKind()`, `belongsTo(User::class)` relation) |
| `Modules/Core/tests/Unit/SystemAlertQueryTest.php` | Query service unit | CRUD (read-only) | `Modules/DriftAlerts/tests/Unit/CancellationImpactQueryTest.php` | role-match (constructor-DI'd service, per-user scope assertions, Pest dataset for severities) |
| `Modules/Core/tests/Unit/AcknowledgeSystemAlertTest.php` | Action unit | CRUD (mutate) | `Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php` | exact (action invoked through container; `assertDatabaseHas('system_alerts', ['acknowledged_at' => …])`; cross-user `NotFoundHttpException` test) |
| `Modules/Core/tests/Unit/BackupRetentionPolicyTest.php` | Value object unit | transform | `Modules/Forecasting/tests/Unit/AmountStringParserTest.php` (if exists; otherwise sibling `Forecasting/tests/Feature/ForecastHighlightsTileTest.php` shape) | role-match (Pest `dataset()` of input-list → expected-keep-list across the 7-daily + 4-Sunday-weekly rule, including the edge cases enumerated in CONTEXT.md `<domain>`) |
| `Modules/Core/tests/Feature/BackupDatabaseCommandTest.php` (CONTEXT path: `tests/Feature/Backup/BackupDatabaseCommandTest.php` — see "Path note" below) | Feature command test | request-response (file I/O + SQL) | `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` | role-match (artisan invocation, output assertions, DB seeding via raw `DatabaseManager`, follow-up filesystem inspection) |
| `Modules/Core/tests/Feature/BackupCorruptionPathTest.php` | Feature command test | request-response (corrupt-source path) | `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` (lines 95–110: "aborts cleanly when collision") | role-match (forced-error path → assert `.suspect` file remains, `system_alerts` row exists, exit code non-zero) |
| `Modules/Core/tests/Feature/BackupScheduleTest.php` | Feature scheduler test | event-driven | `Modules/DriftAlerts/tests/Feature/SnoozedAlertRevivalTest.php` (scheduler-fired flow) | role-match (`$this->artisan('schedule:run')` or registry inspection of `Schedule` entries) |
| `Modules/Core/tests/Feature/RestoreDatabaseCommandTest.php` | Feature command test | request-response (maintenance mode + file swap + DI'd `Kernel`) | `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` | role-match (artisan invocation paths: `--confirm` absent, `--confirm` present, `--force-maintenance`, corrupt source pre-swap rejected, post-swap success) |
| `Modules/Core/tests/Feature/RestoreSuccessPathTest.php` | Feature happy-path test | request-response | `Modules/Core/tests/Feature/InstallCommandTest.php` (idempotency check shape) | role-match (after-state assertions: DB swapped, app back up, log row written) |
| `Modules/Core/tests/Unit/DoctorProbesTest.php` | Per-probe unit | request-response | `Modules/DriftAlerts/tests/Unit/DriftAlertStateMachineTest.php` (small focused unit) | role-match (instantiate each probe in isolation; assert `ProbeResult` severity + message; one Pest dataset per probe) |
| `Modules/Core/tests/Feature/DoctorCommandTest.php` (MODIFIED — extend existing) | Feature command test | request-response | `Modules/Core/tests/Feature/DoctorCommandTest.php` (existing — currently 11 lines, very thin) | exact (existing file lives in this location; extend with the three new probe-aggregation assertions per CONTEXT.md) |
| `Modules/Core/tests/Feature/AppBootHealthCheckTest.php` | Feature boot-time test | event-driven (boot listener fires `system_alerts` write) | `Modules/EmailScan/tests/Feature/InvalidGrantToastTest.php` (boot-side-effect → assertion shape) | partial (no exact precedent — force `PRAGMA journal_mode = DELETE`, re-boot the app via `app()->bootstrapWith([…])` or `Artisan::call('boot')`, assert `system_alerts` row) |
| `Modules/Core/tests/Feature/FailedJobsCommandTest.php` | Feature command test | CRUD on `failed_jobs` | `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` | role-match (seed rows via raw `DatabaseManager`, run artisan with `--older-than=`, `--dry-run`, assert row counts) |
| `Modules/Core/tests/Feature/SystemAlertsBannerTest.php` | Livewire feature test | request-response | `Modules/DriftAlerts/tests/Feature/DashboardDriftBadgeTest.php` | exact (`Livewire::actingAs($user)->test(SystemAlertsBanner::class)->assertSee(...)`, `->call('acknowledge', $id)` flow, cross-user isolation test) |
| `Modules/Core/tests/Feature/ReadmeOperationalDocsTest.php` | Doc-content arch test | file-I/O grep | `tests/Contracts/BoundaryArchTest.php` (any `it(…)` block) | role-match (grep the file for forbidden + required substrings; same comment-strip + regex shape as the boundary arch tests) |

**Path note:** CONTEXT.md lists the test file paths as `tests/Feature/Backup/BackupDatabaseCommandTest.php` etc., but every other module hosts its Pest tests under `Modules/<Module>/tests/{Feature,Unit}/*Test.php` (the module-local `Pest.php` extends `TestCase` and binds `RefreshDatabase` to `Feature/`). The planner should host the Phase 11 tests under `Modules/Core/tests/Feature/` and `Modules/Core/tests/Unit/` (with `Backup/` + `Core/` subdirectories optional — the existing `Modules/Core/tests/Feature/` is currently flat). This matches the substrate's verified Pest topology and keeps the module-local TestCase binding intact.

### README

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `README.md` (MODIFIED — rewrite `## Backups` + `## Operator recovery`) | Documentation | n/a | `README.md` lines 125–167 (current `## Backups` + `## Operator recovery` sections) | exact (in-place rewrite; preserve the existing "Stuck Redis unique-lock keys" subsection and add three new sibling subsections) |

---

## Pattern Assignments

### `Modules/Core/Internal/Console/BackupDatabaseCommand.php` (Command, request-response)

**Analog:** `Modules/Core/Internal/Console/InstallCommand.php`

**Class declaration + namespace + signature pattern** (InstallCommand.php lines 39–50):
```php
namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:install
        {--email= : Email for the single-user account}
        ...';

    /** @var string */
    protected $description = '…';
```

For `BackupDatabaseCommand`, mirror this exactly but with `final` (the codebase prefers `final` — InstallCommand stays `class` only because the launchd tests subclass it). Signature is `db:backup {--force}` per D-1107.

**Constructor DI pattern** (InstallCommand.php lines 81–88):
```php
public function __construct(
    private readonly Repository $config,
    private readonly Dispatcher $events,
    private readonly DatabaseManager $db,
    private readonly Filesystem $files,
) {
    parent::__construct();
}
```

For `BackupDatabaseCommand`, the deps are `DatabaseManager $db`, `Filesystem $files`, `Clock $clock` (from `Modules\Core\Public\Contracts\Clock`), and `Repository $config` (to read `database.connections.sqlite.database`).

**Handle pattern returning self::SUCCESS / self::FAILURE** (InstallCommand.php lines 90–169): the method walks a series of guard clauses, returns `self::FAILURE` on each refusal, returns `self::SUCCESS` on the happy path. For `BackupDatabaseCommand` the equivalent sequence is: read live `PRAGMA data_version` → compare to most-recent `*.meta.json` sidecar → exit 0 if equal AND `--force` absent → run `VACUUM INTO` → chmod 600 → fresh-PDO integrity_check → on `'ok'` write sidecar + prune → on non-`'ok'` rename to `.suspect` + insert `system_alerts` row + return `self::FAILURE`.

**Filesystem chmod pattern** (InstallCommand.php lines 263–265):
```php
if (! $this->files->isDirectory($launchAgentsDir)) {
    $this->files->makeDirectory($launchAgentsDir, 0700, recursive: true);
}
```

Phase 11 needs the same `makeDirectory(...)` + `$this->files->chmod($destination, 0o600)` after `VACUUM INTO` returns (RESEARCH §Pitfall 4).

**VACUUM INTO mechanic** (RESEARCH §Pattern 1 + §Code Examples lines 706–728): the destination path is escaped via `str_replace("'", "''", $destination)`; the SQL is `$this->db->connection()->statement("VACUUM INTO '{$escaped}'")`; the call MUST NOT be wrapped in a transaction.

---

### `Modules/Core/Internal/Console/RestoreDatabaseCommand.php` (Command, request-response)

**Analog:** `Modules/Core/Internal/Console/InstallCommand.php` (constructor + signature shape) + RESEARCH §Pattern 6 (lifecycle).

**Signature:**
```php
protected $signature = 'db:restore {path : Path to the .sqlite backup file to restore}
    {--confirm : Skip the interactive y/N prompt}
    {--force-maintenance : Bring the app down/up automatically around the swap}';
```

**Lifecycle pattern** (RESEARCH §Pattern 6): guard rails in order — verify maintenance mode (or `--force-maintenance`); verify `--confirm` (or TTY prompt); open source via fresh `PDO`, run `PRAGMA integrity_check`, refuse if not `ok`; `VACUUM INTO 'pre-restore-…sqlite'` against the current live DB; `$this->db->purge()`; `copy($source, $configuredDbPath)`; trigger reconnect via `$this->db->connection()->statement(...)` — `SqliteOptimizationsProvider` listener auto-reapplies PRAGMAs; post-swap `PRAGMA integrity_check` via framework connection; `Artisan::call('up')` ONLY if this command brought it down. Wrap the body in `try/finally` so the `up` always runs.

**DI shape:** `DatabaseManager $db`, `Filesystem $files`, `Repository $config`, `Kernel $artisan` (for `Artisan::call('down')` / `'up'` — inject `Illuminate\Contracts\Console\Kernel`, never the facade).

---

### `Modules/Core/Internal/Console/FailedJobsCommand.php` (Command, CRUD)

**Analog:** `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php`

**Signature + class shape** (RederiveFingerprintsCommand.php lines 27–35):
```php
final class RederiveFingerprintsCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:rederive-fingerprints
        {--confirm : Apply the update inside a single DB transaction.}
        {--dry-run : Compute the new fingerprints in memory and report without writing.}';

    /** @var string */
    protected $description = '…';
```

For Phase 11: `diederik:failed-jobs {action=prune} {--older-than=30d} {--dry-run}` — the `{action}` token is the subcommand slot (CONTEXT D-1117); only `prune` is wired in v1.

**Service-vs-command split** (RederiveFingerprintsCommand lines 37–51): the command delegates to an injected `FingerprintRederiveService` and reports the outcome. Mirror this for the failed-jobs command — push the duration-parsing into the new `Support/DurationParser.php` value object (RESEARCH §Pattern 8). The command only reads option values, calls `$this->durationParser->subFromNow(...)`, queries via raw `DatabaseManager`, prints summary.

**Report pattern** (RederiveFingerprintsCommand lines 53–86): a private `report()` method receives the outcome and prints the summary via `$this->info(...)` / `$this->error(...)`, returning the right exit code.

---

### `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php` (Value object, transform)

**Analog:** `Modules/Forecasting/Internal/Support/AmountStringParser.php`

**Directory pattern:** Phase 11 introduces `Modules/Core/Internal/Console/Support/` mirroring `Modules/Forecasting/Internal/Support/`. Same "small testable helper, sibling to the consumer command" placement.

**Class shape** (AmountStringParser.php lines 34–45):
```php
final class AmountStringParser
{
    public static function toMinor(string $input, bool $allowNegative = true, bool $requireNonZero = false): int
    {
        // pure transform; throws on invalid input
    }
}
```

For `BackupRetentionPolicy`: `final class BackupRetentionPolicy` with a single public method `keepers(array $candidateFilenames, CarbonImmutable $now): array` returning the filenames to KEEP (Open Question #6 in RESEARCH explicitly recommends "return a list; the command does the actual delete calls"). Inputs are pure strings + a clock; no `Filesystem` injection — keeps it 100% unit-testable.

**No imports of Filesystem/Carbon-facade** — only `CarbonImmutable` value and PHP-string functions. Mirrors AmountStringParser's zero-IO posture.

---

### `Modules/Core/Internal/Console/Probes/Probe.php` (Interface)

**Analog:** `Modules/Core/Public/Contracts/Clock.php` (style + docblock) + RESEARCH §Pattern 7 (verbatim).

**Verbatim from RESEARCH §Pattern 7 lines 494–509:**
```php
namespace Modules\Core\Internal\Console\Probes;

interface Probe
{
    /**
     * Human-readable label printed in the doctor command's summary table.
     */
    public function label(): string;

    /**
     * Run the probe and return its result. Probes MUST NOT throw — wrap
     * any IO / SQL call in try/catch and surface failure as a critical
     * ProbeResult with the exception message.
     */
    public function run(): ProbeResult;
}
```

Note the analog `Clock.php`'s minimal docblock style (1–2 sentences per method) and `declare(strict_types=1);` header. Replicate that.

---

### `Modules/Core/Internal/Console/Probes/ProbeResult.php` (Value object)

**Analog:** RESEARCH §Pattern 7 lines 512–524 (verbatim sketch).

```php
namespace Modules\Core\Internal\Console\Probes;

final readonly class ProbeResult
{
    /**
     * @param 'ok'|'warning'|'critical' $severity
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $severity,
        public string $message,
        public array $metadata = [],
    ) {}
}
```

Constructor-promoted public readonly fields — matches the `final readonly class` shape used by every Spatie-Data DTO in the codebase and by `DriftAlertDto` style.

---

### `Modules/Core/Internal/Console/Probes/WalModeProbe.php` / `SynchronousModeProbe.php` (Probe implementations)

**Analog:** `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` (lines 17–35) for the PRAGMA-read mechanic.

**PRAGMA read mechanic** (SqliteOptimizationsProvider.php lines 27–32):
```php
$connection->statement('PRAGMA journal_mode = WAL');
$connection->statement('PRAGMA synchronous = NORMAL');
$connection->statement('PRAGMA busy_timeout = 5000');
$connection->statement('PRAGMA foreign_keys = ON');
$connection->statement('PRAGMA temp_store = MEMORY');
```

For probes the read is `$this->db->connection()->select('PRAGMA journal_mode')` returning `array<int, stdClass{journal_mode: string}>`. The probe maps the value into an `ok`/`warning` `ProbeResult`. Note: `select(...)` not `statement(...)` because we need the return value.

**DI shape:** constructor-promoted `DatabaseManager $db`. `final class WalModeProbe implements Probe`.

---

### `Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php` (Probe with side-effect)

**Analog (side-effect side):** `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` (Eloquent-write pattern). **Analog (file-mtime side):** RESEARCH §Pattern 7 + Pitfall 6.

**Eloquent insert shape** (AcknowledgeDriftAlert.php pattern for clarity; the probe writes a NEW row, not transitions an existing one):
```php
SystemAlert::create([
    'user_id' => null, // system-wide
    'kind' => 'backup_overdue',
    'severity' => 'warning',
    'message' => sprintf('Most recent backup is %d hours old.', $hoursOld),
    'metadata' => ['hours_old' => $hoursOld],
]);
```

**DI shape:** `DatabaseManager $db`, `Filesystem $files`, `Clock $clock`. The probe reads `storage/app/backups/*.meta.json` mtimes via `$this->files->files(...)`, picks the newest, compares to `$this->clock->now()->subHours(48)`, and on fail issues both the Eloquent write AND returns a `warning` `ProbeResult`.

---

### `Modules/Core/Internal/Console/DoctorCommand.php` (MODIFIED)

**Analog:** the existing file `Modules/Core/Internal/Console/DoctorCommand.php` — refactor in place.

**Existing shape** (DoctorCommand.php lines 25–89): inline `reportTool()` helpers, `$blockers` + `$warnings` accumulator arrays, 0/1/2 exit codes. The refactor replaces these with:

```php
public function __construct(
    private readonly WalModeProbe $walProbe,
    private readonly SynchronousModeProbe $synchronousProbe,
    private readonly BackupFreshnessProbe $backupFreshnessProbe,
    // existing version-check helpers can stay or be refactored into more Probes —
    // the planner's call per Open Question #6/RESEARCH 6.
) {
    parent::__construct();
}

public function handle(): int
{
    $results = [
        $this->walProbe->run(),
        $this->synchronousProbe->run(),
        $this->backupFreshnessProbe->run(),
        // …existing tool probes if refactored
    ];
    // print summary table + aggregate exit code (0 / 1 / 2) per the
    // existing convention.
}
```

**Existing exit-code semantics to preserve** (DoctorCommand.php lines 21–24): 0 = all ok; 1 = warning(s); 2 = blocker(s). Map probe severity: `ok` → ignore; `warning` → bump exit to ≥1; `critical` → bump exit to 2.

---

### `Modules/Core/Database/Migrations/{timestamp}_create_system_alerts_table.php`

**Analog:** `Modules/DriftAlerts/Database/Migrations/2026_05_19_010001_create_drift_alerts_table.php`

**Anonymous-class migration shape** (drift_alerts migration lines 45–119):
```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('system_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('severity', 16);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();

            $table->index(['user_id', 'acknowledged_at']);
            $table->index(['kind', 'acknowledged_at']);
        });

        // Optional severity-enum trigger pair (mirrors drift_alerts.state pair)
        $connection = $this->db()->connection($this->getConnection());
        $allowedSeverities = "'info','warning','critical'";
        $connection->statement(sprintf(
            "CREATE TRIGGER system_alerts_severity_check_insert BEFORE INSERT ON system_alerts FOR EACH ROW
             WHEN NEW.severity NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END",
            $allowedSeverities,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER system_alerts_severity_check_update BEFORE UPDATE OF severity ON system_alerts FOR EACH ROW
             WHEN NEW.severity NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END",
            $allowedSeverities,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS system_alerts_severity_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS system_alerts_severity_check_update');
        $this->schema()->dropIfExists('system_alerts');
    }

    private function schema(): Builder { /* … */ }
    private function db(): DatabaseManager { /* … resolves via Container::getInstance()->make */ }
};
```

The two private helpers `schema()` + `db()` are verbatim from drift_alerts migration lines 104–118.

---

### `Modules/Core/Models/SystemAlert.php` (Eloquent model)

**Analog:** `Modules/DriftAlerts/Models/DriftAlert.php`

**Class declaration + trait stack** (DriftAlert.php lines 56–61):
```php
final class DriftAlert extends Model
{
    use BelongsToUser;
    /** @use HasFactory<DriftAlertFactory> */
    use HasFactory;
```

For `SystemAlert`: same trait stack, `use BelongsToUser` (from `Modules\Core\Public\Concerns\BelongsToUser`) gives the per-user global scope + `user()` relation + `user_id` fillable. Skip `HasFactory` if no factory is needed in v1 (banner tests can use raw `DatabaseManager` inserts as the migration test does).

**Fillable + casts shape** (DriftAlert.php lines 64–97):
```php
protected $fillable = [
    'user_id', 'kind', 'severity', 'message', 'metadata', 'acknowledged_at',
];

protected function casts(): array
{
    return [
        'metadata' => 'array',
        'acknowledged_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
```

**Scopes:** add `scopeActive(Builder $query)` returning `$query->whereNull('acknowledged_at')` and `scopeByKind(Builder $query, string $kind)` returning `$query->where('kind', $kind)`. DriftAlert does NOT have explicit scopes — the equivalent filter is done in `DriftAlertQuery::scopedOpen` (DriftAlertQuery.php lines 212–225). Either pattern is acceptable; CONTEXT.md `<domain>` calls out scopes explicitly so define them on the model.

---

### `Modules/Core/Public/Services/SystemAlertQuery.php` (Public read service)

**Analog:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`

**Class declaration + constructor DI** (DriftAlertQuery.php lines 49–55):
```php
final readonly class DriftAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
    ) {}
```

For `SystemAlertQuery`: `final readonly class` with constructor-promoted `DatabaseManager $db` + `Clock $clock`. No cross-module dependency needed (system_alerts joins nothing — arch invariant in fact bans the obvious JOIN).

**Per-user scope query** (DriftAlertQuery.php lines 102–108):
```php
public function openCountForUser(User $user): int
{
    return $this->db->connection()->table('drift_alerts')
        ->where('user_id', $user->id)
        ->where(fn (Builder $q) => $this->applyOpenStateFilter($q))
        ->count();
}
```

For `SystemAlertQuery::count(?User $user): int`:
```php
public function count(?User $user): int
{
    $query = $this->db->connection()->table('system_alerts')
        ->whereNull('acknowledged_at');
    if ($user !== null) {
        $query->where(function (Builder $q) use ($user): void {
            $q->where('user_id', $user->id)->orWhereNull('user_id');
        });
    } else {
        $query->whereNull('user_id');
    }
    return $query->count();
}
```

**Active() method:** parallels DriftAlertQuery's `openForUser` returning a collection. UI-SPEC says the banner reads `$query->active($user)` returning `Collection<SystemAlert>` — so use Eloquent `SystemAlert::query()->where(...)->get()` rather than the raw `table()` builder, so the Blade can iterate Eloquent rows with relations available. (Eloquent statics ARE allowed per project DI feedback memory.)

---

### `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` (Public action)

**Analog:** `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php`

**Class shape** (AcknowledgeDriftAlert.php lines 30–71):
```php
final class AcknowledgeDriftAlert
{
    public function __construct(
        private readonly DriftAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user): void
    {
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
        // …state-machine transition + event dispatch
    }
}
```

For `AcknowledgeSystemAlert`:
- DI: `Clock $clock` only (no state machine, no events in v1 — simpler than drift).
- Signature: `__invoke(int $alertId, User $user): SystemAlert` (CONTEXT.md says "returns the updated model").
- Cross-user 404: same `(id, user_id)` guard EXCEPT system-wide rows (`user_id IS NULL`) MUST also be acknowledgeable by any user — adjust the `where` to `->where(function ($q) use ($user) { $q->where('user_id', $user->id)->orWhereNull('user_id'); })`.
- Idempotent: if `$alert->acknowledged_at !== null`, return `$alert` without writing.
- Transactional: per CONTEXT.md "transactional, returns the updated model" — wrap the `$alert->update(...)` in `$this->db->connection()->transaction(...)`. (Inject `DatabaseManager $db` for this; mirrors DriftAlertStateMachine's pattern.)

---

### `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` (Livewire 4 Component)

**Analog:** `Modules/DriftAlerts/Internal/Http/Livewire/DashboardDriftBadge.php`

**Verbatim class skeleton** (DashboardDriftBadge.php — entire file, 39 lines):
```php
namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;

final class SystemAlertsBanner extends Component
{
    public function render(
        CurrentUser $currentUser,
        SystemAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $alerts = $query->active($user);

        return $views->make('core::livewire.system-alerts-banner', [
            'alerts' => $alerts,
        ]);
    }

    public function acknowledge(int $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser): void
    {
        $action($alertId, $currentUser->user());
    }
}
```

**Critical convention:** method-parameter DI on `render()` AND `acknowledge()` — NEVER constructor DI on Livewire Component subclasses (phpstan-strict-rules; DashboardDriftBadge.php line 22 comment is the in-code documentation).

---

### `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` (Blade SFC)

**Analog:** `Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php`

**Row shell** (drift-alert-row.blade.php lines 37–38):
```blade
<div class="rounded-lg border border-slate-200 bg-white p-4">
    <div class="flex items-start justify-between gap-4">
```

For Phase 11 the row shell varies by severity (per UI-SPEC §Color):
- critical: `rounded-lg border border-rose-500 bg-rose-50 text-rose-900 p-4`
- warning: `rounded-lg border border-amber-300 bg-amber-50 text-amber-900 p-4`
- info: `rounded-lg border border-slate-200 bg-slate-50 text-slate-700 p-4`

**Action button** (drift-alert-row.blade.php lines 85–94):
```blade
<button
    type="button"
    wire:click="acknowledge({{ $alert->driftAlertId }})"
    aria-label="Acknowledge drift alert {{ $alert->driftAlertId }}"
    @class([
        'inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
        'bg-emerald-600 text-white hover:bg-emerald-700 focus-visible:ring-emerald-600' => $primaryAcknowledge,
        'bg-slate-100 text-slate-700 hover:bg-slate-200 focus-visible:ring-slate-900' => ! $primaryAcknowledge,
    ])
>Acknowledge</button>
```

For Phase 11: same `wire:click="acknowledge({{ $alert->id }})"` shape; `aria-label="Mark system alert #{{ $alert->id }} as resolved"`; button label `Mark as resolved`; severity-tier classes per UI-SPEC §Color.

**Empty-state pattern** (dashboard-drift-badge.blade.php line 24): `@if ($openCount > 0) … @endif` wraps the entire chrome — when count is zero, only the empty `<div></div>` wrapper renders. UI-SPEC §State Matrix locks this for the banner too: empty wrapper with `role="region" aria-label="System alerts"` and `space-y-2` when no alerts.

**Tabular numerals + Blade escaping** (drift-alert-row.blade.php lines 50–60): `style="font-variant-numeric: tabular-nums;"` on any timestamp/id span; ALL interpolation uses `{{ }}` (UI-SPEC explicitly bans `{!! !!}`).

---

### `resources/views/layouts/app.blade.php` (MODIFIED — one line)

**Analog:** the current file itself, lines 12–17 (the existing `@auth` block).

**Verbatim diff** (per UI-SPEC §Banner Insertion Point):

Before:
```blade
@auth
    @livewire('core.top-nav')
    @livewire('categorization.rule-form-modal')
    @livewire('categorization.correction-divergence-toast')
    @livewire('receipts.receipt-conflict-toast')
@endauth
```

After:
```blade
@auth
    @livewire('core.top-nav')
    @livewire('core.system-alerts-banner')   {{-- new --}}
    @livewire('categorization.rule-form-modal')
    @livewire('categorization.correction-divergence-toast')
    @livewire('receipts.receipt-conflict-toast')
@endauth
```

One line addition. No other changes to the layout file.

---

### `Modules/Core/Internal/Providers/HealthCheckServiceProvider.php` (Service provider)

**Analog:** `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php`

**Verbatim shape to mirror** (SqliteOptimizationsProvider.php — entire 35 lines):
```php
namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;

final class SqliteOptimizationsProvider extends ServiceProvider
{
    public function boot(Dispatcher $events): void
    {
        $events->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            $connection = $event->connection;
            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }
            $connection->statement('PRAGMA journal_mode = WAL');
            // …
        });
    }
}
```

For `HealthCheckServiceProvider`: same `ServiceProvider` subclass + `Dispatcher` DI + `ConnectionEstablished` listener + `sqlite` driver guard. The body diverges: instead of WRITING PRAGMAs, READ them (`->select('PRAGMA journal_mode')`) and on drift call `SystemAlert::create([...])` with kind `wal_mode_missing` / `synchronous_misconfigured` and a structured `Log::warning(...)` (inject `LoggerInterface` to avoid the facade).

**Registration:** add `$this->app->register(HealthCheckServiceProvider::class)` to `CoreServiceProvider::register()` immediately after the existing `SqliteOptimizationsProvider` register line — same neighbourhood, mirrors the convention.

**Caveat from RESEARCH §Pitfall 8:** the listener fires on EVERY connection — gate the write on a once-per-boot static flag inside the listener, or check the existing `system_alerts` table for an unacknowledged row of the same kind in the last hour, so booting the app a hundred times doesn't insert a hundred identical rows. The planner picks the implementation.

---

### `routes/console.php` (MODIFIED — append)

**Analog:** the seven existing entries in `routes/console.php` lines 40–191. The closest exact match for a `Schedule::command(...)` (rather than `Schedule::call(closure)`) is RESEARCH §Code Examples lines 750–755 (verbatim sketch).

**Append at end of file:**
```php
// Daily SQLite backup: produces a verified VACUUM-INTO snapshot under
// storage/app/backups/, runs smart-skip via PRAGMA data_version, prunes
// the retention bucket on success. The Schedule facade lives at the
// project root (outside the Modules\ namespace) so the BoundaryArchTest
// "no Laravel facade usage in module code" rule does not apply here.
//
// Method order .name() BEFORE .dailyAt('03:00')->withoutOverlapping(60)
// — CallbackEvent::withoutOverlapping throws LogicException when
// description is not set yet (same shape as the seven entries above).
//
// Lock TTL of 60 minutes (not the 24h default): a backup that's still
// running an hour later is anomalous; a shorter TTL ensures a crashed
// run recovers within an hour rather than blocking 24h of scheduled
// backups.
Schedule::command('db:backup')
    ->name('db.backup-daily')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);
```

The comment block above mirrors the explanatory style of the existing scheduler entries (see lines 30–39 and 64–74 in `routes/console.php`).

---

### `tests/Contracts/BoundaryArchTest.php` (MODIFIED — append)

**Analog:** the same file, lines 834–879 (`noScenarioMutationsJoinedToTransactionQueries` block) — RESEARCH explicitly cites this as the shape to mirror.

**Pattern** (lines 834–879):
```php
it('does not allow any file to JOIN forecast_scenario_mutations onto transactions / …', function (): void {
    $hits = [];
    $modulesDir = base_path('Modules');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        $hasMutationJoin = preg_match(
            "/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]forecast_scenario_mutations['\"]/",
            $stripped,
        ) === 1;
        $hasForbiddenTable = preg_match(
            "/['\"](transactions|recurring_series_occurrences|chain_links|card_statements)['\"]/",
            $stripped,
        ) === 1;
        if ($hasMutationJoin && $hasForbiddenTable) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([], "forecast_scenario_mutations must never be JOINed onto transaction-substrate tables. Offenders:\n  ".implode("\n  ", $hits));
});
```

Phase 11 invariants follow exactly this skeleton:

- `noFacadeCallsFromCoreConsoleCommands` — walk `Modules/Core/Internal/Console/` (recursive), grep for `Illuminate\\Support\\Facades\\` after comment-strip. RESEARCH §Code Examples lines 831–857 provides the verbatim function body.
- `systemAlertsTableNotJoinedToTransactions` — walk `Modules/`, grep for `->(join|leftJoin|rightJoin|crossJoin)\s*\(\s*['"]system_alerts['"]` co-occurring with `['"]transactions['"]`. RESEARCH §Code Examples lines 859–881 provides the verbatim function body.

---

### `tests/Contracts/HorizonForceFlagTest.php` (NEW arch test)

**Analog:** any `it(…)` block in `tests/Contracts/BoundaryArchTest.php`. Per RESEARCH §A2, this is an isolated assumption check — keep it in its own file because it greps a config file (`config/horizon.php`), not the Modules tree.

**Shape:**
```php
it('does not allow any Horizon supervisor to set force: true (HorizonForceFlagInvariant)', function (): void {
    $horizonConfigPath = base_path('config/horizon.php');
    if (! is_file($horizonConfigPath)) {
        expect(true)->toBeTrue();
        return;
    }
    $contents = (string) file_get_contents($horizonConfigPath);
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
    expect($stripped)->not->toMatch("/['\"]force['\"]\\s*=>\\s*true/");
});
```

This locks the assumption (RESEARCH §A2) that `php artisan down` will actually pause Horizon workers — which is load-bearing for the `db:restore` correctness story.

---

### Test files (general pattern)

**Migration test analog:** `Modules/DriftAlerts/tests/Unit/DriftAlertsMigrationTest.php` — column-existence loop, trigger-rejection assertions, UNIQUE / INDEX assertions, severity dataset.

**Action test analog:** `Modules/DriftAlerts/tests/Feature/AcknowledgeDriftAlertTest.php` — fixture helpers (`ackdaUser`, `ackdaTransaction`, `ackdaAlert`), `beforeEach` user setup, `Event::fake([…])` if events are dispatched, `$this->app->make(AcknowledgeDriftAlert::class)(…)` invocation.

**Livewire feature test analog:** `Modules/DriftAlerts/tests/Feature/DashboardDriftBadgeTest.php` — `Livewire::actingAs($user)->test(Component::class)`, `->viewData('alerts')`, `->assertSee`, `->call('acknowledge', $id)`, cross-user isolation test.

**Command test analog:** `Modules/Ledger/tests/Feature/RederiveFingerprintsCommandTest.php` — `$this->artisan('cmd', ['opt' => true])->expectsOutputToContain(...)->assertSuccessful()`; seed raw rows via `DatabaseManager`; post-state assertions on tables.

**Module-local Pest binding:** every module's `tests/Pest.php` looks like `Modules/Core/tests/Pest.php` (already exists):
```php
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');
```
Phase 11 inherits this binding by hosting tests under `Modules/Core/tests/{Feature,Unit}/`. Test files declare `uses(RefreshDatabase::class);` on the first non-strict-types line if they need the trait (mirrors `DriftAlertsMigrationTest.php` line 10).

---

## Shared Patterns

### Authentication / current-user resolution
**Source:** `Modules/Core/Public/Contracts/CurrentUser.php` + `Modules/Core/Public/Services/CurrentUserService.php`
**Apply to:** `SystemAlertsBanner` (render + acknowledge), `AcknowledgeSystemAlert` (caller scopes by user)

Standard shape: inject `CurrentUser $currentUser` (Livewire components via method-parameter DI; everywhere else via constructor DI). Call `$currentUser->user()` to get the `User`; throws `NotAuthenticatedException` if no auth — Livewire's `@auth` block in `app.blade.php` guarantees the banner only renders when authenticated, so the exception is structurally unreachable.

### Per-user scoping (with system-wide rows for SystemAlerts only)
**Source:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` lines 102–127
**Apply to:** `SystemAlertQuery` + `AcknowledgeSystemAlert`

```php
->where('user_id', $user->id)
```

For SystemAlerts, the deviation is the `OR IS NULL` clause covering system-wide alerts:
```php
->where(function (Builder $q) use ($user): void {
    $q->where('user_id', $user->id)->orWhereNull('user_id');
})
```

### Constructor DI of `DatabaseManager` (no facades)
**Source:** `Modules/Core/Internal/Console/InstallCommand.php` line 84; `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` line 52; `Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php` line 21 (event-listener style)
**Apply to:** ALL new console commands, the Public service, the Public action, every Probe, and the boot health-check provider.

Sample:
```php
public function __construct(
    private readonly DatabaseManager $db,
    // …
) { /* … */ }
```

Then `$this->db->connection()->statement(...)` / `->table(...)->where(...)->...`. **NEVER** `DB::statement(...)`. The new arch invariant `noFacadeCallsFromCoreConsoleCommands` will catch any regression.

### Eloquent statics ARE allowed
**Source:** `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` lines 41–44 (`DriftAlert::query()->where(...)`) — DI feedback memory explicitly carves this out.
**Apply to:** `SystemAlertQuery::active($user)` (returning `Collection<SystemAlert>`), `AcknowledgeSystemAlert` (the per-user `WHERE` guard), the `BackupFreshnessProbe`'s `SystemAlert::create(...)` write.

### Error handling — 404 on cross-user, idempotent on already-done
**Source:** `Modules/DriftAlerts/Public/Actions/AcknowledgeDriftAlert.php` lines 38–53
**Apply to:** `AcknowledgeSystemAlert`

```php
if ($alert === null) {
    throw new NotFoundHttpException('System alert not found.');
}
if ($alert->acknowledged_at !== null) {
    return $alert; // idempotent
}
```

### Atomic file-permission writes (chmod 600)
**Source:** `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` lines 258–355 (`writeAtomic()`)
**Apply to:** the `<backup>.meta.json` sidecar writer inside `BackupDatabaseCommand`. Pattern: `umask(0077)` → write `.tmp` → `chmod(tmp, 0600)` → `rename(tmp, final)` → restore umask in `finally`.

**Caveat (RESEARCH §Pitfall 4):** the `.sqlite` file itself is created BY SQLite via `VACUUM INTO`, not by PHP `fopen()`. The umask trick is irrelevant for the .sqlite file — call `$this->files->chmod($destination, 0o600)` IMMEDIATELY after the `VACUUM INTO` statement returns successfully.

### Method order on Schedule entries: `.name()` BEFORE `.dailyAt()->withoutOverlapping()`
**Source:** `routes/console.php` lines 62, 81, 98, 117, 136, 154, 191 (every existing entry follows this order; each entry's inline comment explains why — `CallbackEvent::withoutOverlapping` throws `LogicException` when description is not set yet).
**Apply to:** the new `Schedule::command('db:backup')` entry.

### Tabular numerals + Blade escaping in views
**Source:** `Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php` lines 51, 53, 56–60
**Apply to:** `system-alerts-banner.blade.php` for every timestamp, alert id, and hours-old number.

```blade
<span style="font-variant-numeric: tabular-nums;">{{ $alert->created_at->format('d M Y · HH:mm') }}</span>
```

ALL interpolation uses `{{ }}` (Blade default escapes); never `{!! !!}` (UI-SPEC §S4 explicitly forbids; per project DI feedback memory + the `XSS via system_alerts.message rendered without escaping` threat in RESEARCH §Security).

---

## Cross-Cutting Container-Registration Shape

**Analog:** `Modules/Core/Providers/CoreServiceProvider.php`

The existing `CoreServiceProvider::boot(LivewireManager $livewire)` (lines 47–64) is the exact extension point:

- **Migration auto-load:** already covered by `$this->loadMigrationsFrom(__DIR__.'/../Database/Migrations')` (line 49) — new `create_system_alerts_table.php` is picked up automatically once dropped into `Modules/Core/Database/Migrations/`.
- **View auto-load:** already covered by `$this->loadViewsFrom(__DIR__.'/../Resources/views', 'core')` (line 52) — new `system-alerts-banner.blade.php` is reachable via `core::livewire.system-alerts-banner`.
- **Livewire component registration:** add `$livewire->component('core.system-alerts-banner', SystemAlertsBanner::class);` alongside the existing three component registrations (lines 54–56).
- **Console commands:** add `BackupDatabaseCommand::class`, `RestoreDatabaseCommand::class`, `FailedJobsCommand::class` to the `$this->commands([…])` array (lines 59–62).
- **HealthCheckServiceProvider register:** add `$this->app->register(HealthCheckServiceProvider::class);` in `register()` (next to the existing `SqliteOptimizationsProvider` register call on line 37).
- **Public service / action singletons (optional):** `DriftAlertsServiceProvider::register()` lines 51–60 binds Public services as singletons. Mirror by binding `SystemAlertQuery` + `AcknowledgeSystemAlert` as singletons in `CoreServiceProvider::register()` — keeps DI consistent across the module.

---

## No Analog Found

| File | Role | Data Flow | Notes |
|------|------|-----------|-------|
| `Modules/Core/Internal/Console/Support/DurationParser.php` (if extracted per RESEARCH §Pattern 8) | Value object | transform (string→Carbon) | No exact analog — `AmountStringParser.php` is the closest "pure-string parser value object" but its API is different (returns int minor units, not Carbon). RESEARCH §Pattern 8 provides a verbatim sketch the planner can implement directly. |
| `Modules/Core/tests/Feature/AppBootHealthCheckTest.php` | Boot-time feature test | event-driven | No direct precedent for force-misconfiguring PRAGMAs and re-booting. Closest pattern: `Modules/EmailScan/tests/Feature/InvalidGrantToastTest.php` (boot-side-effect → assertion). Implementation: `$this->app->make(DatabaseManager::class)->connection()->statement('PRAGMA journal_mode = DELETE')` then re-resolve `HealthCheckServiceProvider` via `$this->app->register(...)` or simulate a `ConnectionEstablished` event manually. The planner picks. |

Both are still implementable from RESEARCH.md patterns + the closest in-tree examples; no out-of-codebase research is required.

---

## Metadata

**Analog search scope:**
- `Modules/Core/` (commands, providers, models, public services, internal http/livewire, tests)
- `Modules/DriftAlerts/` (the primary mirror — migration, model, public service + action, dashboard livewire badge, dashboard livewire tests, drift-alert-row blade partial, migration tests)
- `Modules/Forecasting/` (most-recent migration shape, internal support value-object, livewire tile shape)
- `Modules/Ledger/` (RederiveFingerprintsCommand for the diederik: + --dry-run + --confirm command shape and its test)
- `Modules/EmailScan/` (OAuthSecretsRepository for atomic chmod-600 file-write pattern; InvalidGrantToastTest for boot-side-effect test shape)
- `routes/console.php` (scheduler entry convention)
- `resources/views/layouts/app.blade.php` (slot insertion point)
- `tests/Contracts/BoundaryArchTest.php` (arch invariant pattern)
- `bootstrap/providers.php` + `app/Providers/HorizonServiceProvider.php` (provider registration discovery — confirms there is NO `App\Providers\AppServiceProvider`, so `HealthCheckServiceProvider` registered from `CoreServiceProvider` is the correct home per RESEARCH Open Question #1)

**Files scanned (Read in full or with focused offset/limit):** 22 production files + 5 test files + 2 bootstrap files = 29 files.

**Pattern extraction date:** 2026-05-19
