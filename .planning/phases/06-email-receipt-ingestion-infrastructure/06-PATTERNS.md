# Phase 6: Email Receipt Ingestion Infrastructure - Pattern Map

**Mapped:** 2026-05-16
**Files analyzed:** ~50 new / modified files
**Analogs found:** ~45 exact-or-role-match / ~50 total
**Primary analog source:** `Modules/Chains/` (Phase 5) — every architectural shape Phase 6 needs already lives there in a form ready to copy

---

## File Classification

### New module scaffold

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---------------------|------|-----------|----------------|---------------|
| `Modules/EmailScan/composer.json` | module manifest | n/a | `Modules/Chains/composer.json` | exact |
| `Modules/EmailScan/Providers/EmailScanServiceProvider.php` | service provider | wiring | `Modules/Chains/Providers/ChainsServiceProvider.php` | exact |
| `Modules/EmailScan/Routes/web.php` | route registration | request-response | `Modules/Chains/Routes/web.php` | exact |
| `Modules/EmailScan/tests/TestCase.php` | test bootstrap | n/a | `Modules/Chains/tests/TestCase.php` | exact |
| `Modules/EmailScan/tests/Pest.php` | per-module pest (inert) | n/a | `Modules/Chains/tests/Pest.php` | exact |
| `composer.json` (root, autoload-dev edit) | PSR-4 dev autoload | n/a | existing `Modules\\Chains\\Tests\\` row | exact |
| `phpunit.xml` (root, testsuite edit) | test discovery | n/a | existing `ChainsUnit`/`ChainsFeature`/`ChainsContracts` blocks | exact |
| `tests/Pest.php` (root, foreach edit) | per-module test binding | n/a | existing `Modules/Chains` entry in foreach map | exact |

### Public surface (DTOs + services + actions)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/EmailScan/Public/Services/InboxQuery.php` | public read service | DB → DTO | `Modules/Chains/Public/Services/ChainLinkQuery.php` | exact |
| `Modules/EmailScan/Public/Services/KnownSenderQuery.php` | public read service | DB → DTO | `Modules/Chains/Public/Services/CardStatementQuery.php` | exact |
| `Modules/EmailScan/Public/Services/InboxMessageQuery.php` | public read service | DB → DTO (iterable) | `Modules/Chains/Public/Services/ChainLinkQuery.php` | role-match |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` | public repository (file I/O) | filesystem → DTO | **no analog** (new pattern — atomic chmod-600 rotation) | none |
| `Modules/EmailScan/Public/Dto/InboxHealthDto.php` | spatie/laravel-data DTO | n/a | `Modules/Chains/Public/Dto/ChainLinkRow.php` | exact |
| `Modules/EmailScan/Public/Dto/KnownSenderDto.php` | spatie/laravel-data DTO | n/a | `Modules/Chains/Public/Dto/ChainLinkRow.php` | exact |
| `Modules/EmailScan/Public/Dto/InboxMessageDto.php` | spatie/laravel-data DTO | n/a | `Modules/Chains/Public/Dto/ChainLinkRow.php` | exact |
| `Modules/EmailScan/Public/Dto/InboxCredentials.php` | spatie/laravel-data DTO | n/a | `Modules/Chains/Public/Dto/StatementSettlement.php` | role-match |
| `Modules/EmailScan/Public/Dto/EmailScanHealthTile.php` | dashboard-tile DTO | n/a | `Modules/Chains/Public/Dto/CardStatementForecastTile.php` | exact |
| `Modules/EmailScan/Public/Dto/ScanCursor.php` | value object | n/a | **no analog** (new pattern — provider-cursor normalisation) | none |

### Internal jobs + state machine + clients

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php` | queued job | HTTP fetch → filesystem → DB | `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | exact |
| `Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php` | queued job (scheduled hourly) | HTTP fetch → filesystem → DB | `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | exact |
| `Modules/EmailScan/Internal/Jobs/DiscoveryScanJob.php` | queued job (scheduled daily) | HTTP fetch → DB | `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | role-match |
| `Modules/EmailScan/Internal/InboxScanStateMachine.php` | locked state mutator | DB transaction | `Modules/Chains/Internal/CardStatementStateMachine.php` | exact |
| `Modules/EmailScan/Internal/Clients/GmailApiClient.php` | external API wrapper | HTTP request-response | **no analog** (first external SDK wrapper in module code) | none |
| `Modules/EmailScan/Internal/Clients/GraphApiClient.php` | external API wrapper | HTTP request-response | **no analog** (first external SDK wrapper in module code) | none |
| `Modules/EmailScan/Internal/Clients/FakeGmailApiClient.php` | test fake | fixture-driven | `Modules/Chains/tests/fixtures/scenario-1/*` (synthesised cross-source) | role-match |
| `Modules/EmailScan/Internal/Clients/FakeGraphApiClient.php` | test fake | fixture-driven | same as above | role-match |
| `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php` | OAuth abstraction | HTTP request-response | **no analog** (first OAuth surface; league/oauth2-* wrapper) | none |
| `Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php` | OAuth abstraction | HTTP request-response | **no analog** (same reason) | none |
| `Modules/EmailScan/Internal/MimeHeaderParser.php` | parser (header extraction) | bytes → struct | `Modules/Ingestion/Internal/Adapters/Asn/AsnCamt053Adapter.php` (library wrapper) | role-match |
| `Modules/EmailScan/Internal/EmlBlobStore.php` | filesystem repository | filesystem | **no analog** (first raw-blob persistence) | none |

### Internal HTTP (Livewire SFCs + Controller)

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php` | Livewire SFC (page) | DB → DTO → view | `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` | exact |
| `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` | Livewire SFC (modal) | form-submit → file write | `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` | role-match |
| `Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php` | Livewire SFC (modal) | form-submit → job dispatch | `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` | role-match |
| `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php` | invokable controller | OAuth callback → DB → file | **no analog** (first traditional controller class in module code) | none |
| `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php` | Blade view | render | `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php` | exact |
| `Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php` | Blade view (modal) | render | `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` (flux:modal shape) | role-match |
| `Modules/EmailScan/Resources/views/livewire/backfill-window-modal.blade.php` | Blade view (modal) | render | same as above | role-match |

### Migrations + models

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `Modules/EmailScan/Database/Migrations/*_create_inboxes_table.php` | migration (CRUD table) | DDL | `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` | exact |
| `Modules/EmailScan/Database/Migrations/*_create_inbox_scan_state_table.php` | migration (enum-state table) | DDL + triggers | `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` | exact |
| `Modules/EmailScan/Database/Migrations/*_create_inbox_messages_table.php` | migration (CRUD + UNIQUE) | DDL | `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php` | exact |
| `Modules/EmailScan/Database/Migrations/*_create_known_senders_table.php` | migration + seed | DDL + INSERT | `Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php` | role-match |
| `Modules/EmailScan/Database/Migrations/*_create_discovered_senders_table.php` | migration | DDL | `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php` | exact |
| `Modules/EmailScan/Models/Inbox.php` | Eloquent model | n/a | `Modules/Chains/Models/CardStatement.php` | exact |
| `Modules/EmailScan/Models/InboxScanState.php` | Eloquent model | n/a | `Modules/Chains/Models/CardStatement.php` | exact |
| `Modules/EmailScan/Models/InboxMessage.php` | Eloquent model | n/a | `Modules/Chains/Models/ChainLink.php` | exact |
| `Modules/EmailScan/Models/KnownSender.php` | Eloquent model | n/a | `Modules/Chains/Models/ChainLink.php` | exact |
| `Modules/EmailScan/Models/DiscoveredSender.php` | Eloquent model | n/a | `Modules/Chains/Models/ChainLink.php` | exact |

### Cross-module + deployment

| New / Modified File | Role | Closest Analog | Match Quality |
|---------------------|------|----------------|---------------|
| `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (extend) | dashboard-tile method | existing `nextIcsSettlement()` in same file | exact |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php` (extend) | nav badge | existing "Review chains" entry in same file | exact |
| `tests/Contracts/BoundaryArchTest.php` (extend) | architecture invariants | existing `noResolverWritesTransactions` / `Modules\\Chains\\Internal` rule | exact |
| `tests/Contracts/NoExtImapTest.php` (extend) | composer.lock lint | existing PLT-05 ext-imap grep (Phase 1) | exact |
| `composer.json` (root, conflict block) | dependency hard-fail | n/a | none |
| `Modules/Core/Internal/Console/InstallLaunchdCommand.php` (or `app/Console/Commands/`) | artisan command | `Modules/Core/Internal/Console/InstallCommand.php` | role-match |
| `deploy/launchd/com.diederik.horizon.plist` | launchd plist | **no analog** | none |
| `deploy/launchd/com.diederik.scheduler.plist` | launchd plist | **no analog** | none |
| `deploy/launchd/com.diederik.redis.plist` | launchd plist | **no analog** | none |
| `Modules/EmailScan/tests/fixtures/eml/{paypal,ics,googleplay}/*.eml` | synthesised fixtures | `Modules/Chains/tests/fixtures/scenario-1/*` | exact |

---

## Pattern Assignments

### `Modules/EmailScan/composer.json` (module manifest)

**Analog:** `Modules/Chains/composer.json` (entire file, 13 lines)

Copy verbatim; change name, description, and the PSR-4 namespace string:

```json
{
    "name": "diederik/email-scan",
    "description": "EmailScan module — Gmail + Microsoft Graph OAuth ingestion, raw .eml persistence, resumable scan cursors.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\EmailScan\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\EmailScan\\Tests\\": "tests/"
        }
    }
}
```

**Why this analog:** Identical module-manifest shape; only the diederik/<slug> and namespace differ.

---

### `Modules/EmailScan/Providers/EmailScanServiceProvider.php`

**Analog:** `Modules/Chains/Providers/ChainsServiceProvider.php`

**Singleton bindings pattern** (lines 67-83):
```php
public function register(): void
{
    $this->app->singleton(CardStatementStateMachine::class);
    $this->app->singleton(ChainLinkInsertHelper::class);
    $this->app->singleton(IcsSettlementResolver::class);
    // ...
    $this->app->singleton(ChainLinkQuery::class);
    $this->app->singleton(CardStatementQuery::class);
    $this->app->singleton(ConfirmChainLink::class);
}
```

EmailScan bindings: `InboxScanStateMachine`, `OAuthSecretsRepository`, `GmailApiClient`, `GraphApiClient`, `GoogleOAuthProvider`, `MicrosoftOAuthProvider`, `EmlBlobStore`, `MimeHeaderParser`, `BackfillInboxJob`, `IncrementalScanJob`, `DiscoveryScanJob`, plus the four Public services + `EmailScanHealthTile`-bearing query.

**boot() Livewire + migrations + routes registration** (lines 85-103):
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
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'chains');
    }
    $livewire->component('chains.chain-drawer', ChainDrawer::class);
    $livewire->component('chains.chain-review-queue', ChainReviewQueue::class);
    $this->registerJobFailedListener($events);
    $this->registerTopNavBadgeComposer();
}
```

Phase 6 mirror: `loadViewsFrom(..., 'email-scan')`, register three Livewire components (`email-scan.inboxes-page`, `email-scan.oauth-client-wizard-modal`, `email-scan.backfill-window-modal`), wire the same `JobFailed` listener (now matching `BackfillInboxJob` / `IncrementalScanJob` / `DiscoveryScanJob` substrings), and wire the inboxes-badge composer.

**View Factory composer for top-nav badge** (lines 122-137):
```php
private function registerTopNavBadgeComposer(): void
{
    $app = $this->app;
    $factory = $app->make(ViewFactoryContract::class);

    $factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {
        $currentUser = $app->make(CurrentUser::class);
        if (! $currentUser->isAuthenticated()) {
            $compose->with('chainOpenCandidateCount', 0);
            return;
        }
        $query = $app->make(ChainLinkQuery::class);
        $compose->with('chainOpenCandidateCount', $query->openCandidateCount($currentUser->user()));
    });
}
```

Phase 6 mirror: same shape; replace `ChainLinkQuery::openCandidateCount(User)` with `InboxQuery::reviewBadgeCount(User)` which returns `count(discovered_senders WHERE state='candidate' AND user_id=?) + count(inbox_scan_state WHERE status='needs_reauth' AND user_id=?)`. Bind to the SAME view name `core::livewire.top-nav` and `$compose->with('inboxesBadgeCount', ...)`. **Issue #12 carry-forward invariant — never `view()` global helper.**

**JobFailed listener (audit-row failure path)** (lines 152-191):
```php
$events->listen(JobFailed::class, function (JobFailed $event) use ($app): void {
    $jobName = $event->job->resolveName();
    if (! str_contains($jobName, 'ResolveChainLinksJob')) {
        return;
    }
    $userId = $this->extractUserIdFromFailedJob($event);
    // ... update chain_resolution_runs row with status=failed + last_error
});
```

Phase 6 mirror: same shape; match any of the three new job class names; flip the corresponding `inbox_scan_state.status` to `error` for `BackfillInboxJob` / `IncrementalScanJob`, set `last_error` from `$event->exception->getMessage()` first line, truncated to 500 chars. **Never log the OAuth token payload** (RESEARCH Anti-Pattern).

**Why this analog:** Exact role + data flow; Phase 5 just shipped both the View Factory composer and the JobFailed listener patterns. Use them as-is.

---

### `Modules/EmailScan/Internal/Jobs/BackfillInboxJob.php` (+ IncrementalScanJob + DiscoveryScanJob)

**Analog:** `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php`

**Class header + interface declarations** (lines 57-74):
```php
final class ResolveChainLinksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}
```

Phase 6 mirror per job:
- `BackfillInboxJob(public readonly int $inboxId)` — `uniqueId() = (string) $inboxId`, `uniqueFor() = 1800`, `tries = 3`, `backoff = [60, 300, 900]`.
- `IncrementalScanJob(public readonly int $inboxId)` — `uniqueId() = (string) $inboxId`, `uniqueFor() = 600`.
- `DiscoveryScanJob(public readonly int $userId)` — `uniqueId() = (string) $userId`, `uniqueFor() = 600`.

**uniqueVia() — single permitted Cache facade carve-out** (lines 89-96):
```php
public function uniqueVia(): Repository
{
    // The Cache facade is the single permitted facade use in
    // module code (BoundaryArchTest carve-out). Reason: Laravel
    // calls uniqueVia() before constructor DI completes — there
    // is no path to inject a Repository at this point.
    return Cache::driver('redis');
}
```

Copy verbatim into each new job. Each gets its own carve-out entry in `tests/Contracts/BoundaryArchTest.php` (see Shared Patterns below).

**handle() — DatabaseManager + Clock + collaborators via constructor of handle, audit row lifecycle** (lines 98-142):
```php
public function handle(
    DatabaseManager $db,
    Clock $clock,
    IcsSettlementResolver $icsResolver,
    PaypalFundingResolver $paypalResolver,
): void {
    /** @var User $user */
    $user = User::query()->where('id', $this->userId)->firstOrFail();

    $now = $clock->now()->toDateTimeString();
    $jobId = $this->job?->getJobId();
    $runId = $db->connection()->table('chain_resolution_runs')->insertGetId([
        'user_id' => $this->userId,
        'job_uuid' => is_string($jobId) ? $jobId : null,
        'status' => 'running',
        'started_at' => $now,
        // ...
    ]);

    $icsResolver->resolveForUser($user);
    // ...
    $db->connection()->table('chain_resolution_runs')->where('id', $runId)->update([
        'status' => 'complete', 'completed_at' => $completedAt, /* ... */
    ]);
}
```

Phase 6 mirror: inject `DatabaseManager`, `Clock`, `GmailApiClient`, `GraphApiClient` (strategy-dispatch on `$inbox->provider`), `EmlBlobStore`, `MimeHeaderParser`, `InboxScanStateMachine`, `OAuthSecretsRepository`. The audit-row lifecycle is now the `inbox_scan_state` row itself — `status` transitions to `backfilling` / `scanning` on entry and `idle` / `rate_limited` / `error` / `needs_reauth` on exit, mutated EXCLUSIVELY via `InboxScanStateMachine`. **Atomic .eml-then-DB ordering per RESEARCH Pattern 3 — write `.eml` first, open DB tx, on throw unlink `.eml`.**

**Why this analog:** Exact role + data flow. Phase 6's three jobs differ from `ResolveChainLinksJob` only in (a) what they do inside `handle()` and (b) what `uniqueId()` keys on (`inbox_id` vs `user_id`).

---

### `Modules/EmailScan/Internal/InboxScanStateMachine.php`

**Analog:** `Modules/Chains/Internal/CardStatementStateMachine.php`

**Class header + DI** (lines 38-51):
```php
final class CardStatementStateMachine
{
    private const SETTLED_TOLERANCE_MINOR = 1;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}
```

**Transaction wrapper + busy_timeout + scoped read** (lines 55-94):
```php
$result = $connection->transaction(function () use ($connection, $statementId, $deltaMinor, $user): StatementSettlement {
    $connection->statement('PRAGMA busy_timeout = 5000');

    $row = $connection->table('card_statements')
        ->where('id', $statementId)
        ->where('user_id', $user->id)
        ->first(['id', 'open_balance_minor', 'state']);

    if ($row === null) {
        throw new RuntimeException("card_statement {$statementId} not found for user {$user->id}");
    }
    // ... compute new state via match (true)
    $connection->table('card_statements')
        ->where('id', $statementId)
        ->where('user_id', $user->id)
        ->update(['open_balance_minor' => $newOpen, 'state' => $newState, 'updated_at' => $now]);
    // ...
});
```

Phase 6 mirror: `applyStatus(int $inboxId, string $newStatus, User $user, ?string $errorMessage = null)`; reads `inbox_scan_state`, validates the transition is legal (`idle → backfilling/scanning`, `backfilling → idle/rate_limited/error/needs_reauth`, etc.), writes new status + `retry_attempts` + `error_message` inside one transaction with `busy_timeout = 5000`.

**Static type-coercion helpers** (lines 112-123):
```php
private static function toInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}
private static function toString(mixed $value): string
{
    return is_string($value) ? $value : (string) (is_scalar($value) ? $value : '');
}
```

Copy verbatim — keeps phpstan strict-rules `cast.int` / `cast.string` happy when reading raw query-builder scalars.

**Why this analog:** Exact match. Phase 5 D-95 invariant ("only this class may mutate `card_statements.state`") is mirrored verbatim by Phase 6 D-123 ("only `InboxScanStateMachine` may mutate `inbox_scan_state.status`"); BoundaryArchTest grep at lines 113-164 enforces it.

---

### `Modules/EmailScan/Public/Services/InboxQuery.php`

**Analog:** `Modules/Chains/Public/Services/ChainLinkQuery.php`

**Class header + DI + constants** (lines 46-62):
```php
final class ChainLinkQuery
{
    private const MAX_DEPTH = 5;
    private const AUTO_PROMOTE_THRESHOLD = 3;

    public function __construct(private readonly DatabaseManager $db) {}
```

**Cross-user scoped read with explicit 404** (lines 64-78):
```php
public function forTransaction(int $transactionId, User $user): ChainTree
{
    $rootRow = $this->db->connection()->table('transactions')
        ->where('id', $transactionId)
        ->where('user_id', $user->id)
        ->first([/* columns */]);

    if ($rootRow === null) {
        throw new NotFoundHttpException('Transaction not found.');
    }
    // ...
}
```

Phase 6 mirror per public method:
- `forCurrentUser(User $user): array<InboxHealthDto>` — `SELECT inboxes.* + LEFT JOIN inbox_scan_state` filtered to `user_id`, hydrate to DTOs.
- `reviewBadgeCount(User $user): int` — composite count for the top-nav badge.
- `findForUser(int $inboxId, User $user): ?InboxHealthDto` — returns null (the View-Factory composer reads), or throws `NotFoundHttpException` (the row-action endpoints read) — pick per call site.

**Raw DatabaseManager query builder (not Eloquent::query()->exists() — strict rules)** (lines 66-74 above):
Use `$this->db->connection()->table('inboxes')->...->count()` for any "is there at least one row" check; never `Inbox::query()->exists()` (RESEARCH "phpstan strict rule `staticMethod.dynamicCall`").

**Why this analog:** Exact role + data flow. `KnownSenderQuery`, `InboxMessageQuery` follow the same shape with simpler queries.

---

### `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`

**Analog:** **NO CODEBASE ANALOG.** This is the first atomic-chmod-600 filesystem repository in the codebase. RESEARCH.md Example 2 (lines 836-877) provides the canonical shape; planner should treat it as the de-novo template:

```php
final class OAuthSecretsRepository
{
    private const PATH = 'app/secrets/email-oauth.json';
    private const DIR_MODE = 0700;
    private const FILE_MODE = 0600;

    public function __construct(private readonly Filesystem $files) {}

    private function writeAtomic(array $data): void
    {
        $absolutePath = storage_path(self::PATH);
        $absoluteDir = dirname($absolutePath);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, self::DIR_MODE, recursive: true);
        }
        $tmp = $absolutePath . '.tmp';
        $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fp = fopen($tmp, 'wb');
        flock($fp, LOCK_EX);
        fwrite($fp, $bytes);
        fflush($fp);
        if (function_exists('fsync')) {
            fsync($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        chmod($tmp, self::FILE_MODE);
        rename($tmp, $absolutePath);
    }
}
```

**Closest sibling for DI shape only:** `Modules/Chains/Internal/CardStatementStateMachine.php` (lines 48-51) for the constructor pattern (final class, readonly DI, private const config).

**Why no analog:** No prior phase persisted secrets to disk. Phase 6 is greenfield for this surface. Wave 0 must add a dedicated `OAuthSecretsRepositoryTest` covering atomic-rotation crash safety (RESEARCH Test Map: `OAuthSecretsAtomicRotationTest`).

---

### `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php`

**Analog:** `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php`

**Component shape (no constructor; services as render-method args; cursor as public properties)** (lines 42-72):
```php
final class ChainReviewQueue extends Component
{
    public ?int $cursorId = null;
    public ?string $cursorConfidence = null;

    public function confirm(int $chainLinkId, CurrentUser $currentUser, ConfirmChainLink $confirm): void
    {
        ($confirm)($chainLinkId, $currentUser->user());
    }

    public function reject(int $chainLinkId, CurrentUser $currentUser, RejectChainLink $reject): void
    {
        ($reject)($chainLinkId, $currentUser->user());
    }
```

Phase 6 mirror — public action methods on the component:
- `scanNow(int $inboxId, CurrentUser $cu, /* dispatcher */)` — dispatches `IncrementalScanJob`.
- `reconnect(int $inboxId, CurrentUser $cu)` — kicks off the per-inbox OAuth consent dance (probably a `redirect()->to(...)`).
- `editWindow(int $inboxId, CurrentUser $cu)` — dispatches a Livewire event opening the backfill-window modal.
- `promoteSender(int $senderId, CurrentUser $cu)` / `dismissSender(int $senderId, CurrentUser $cu)` — same shape as `confirm` / `reject`.

**render() with ViewFactory injection + view-extends('layouts.app')** (lines 73-94):
```php
public function render(
    CurrentUser $currentUser,
    ChainLinkQuery $query,
    ViewFactory $views,
): View {
    $user = $currentUser->user();
    $candidates = $query->candidatesForReview(/* ... */);

    $view = $views->make('chains::livewire.chain-review-queue', [
        'candidates' => $candidates,
    ]);

    /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
    $view->extends('layouts.app', ['title' => 'Review chains · diederik']);

    return $view;
}
```

Phase 6 mirror — `render()` injects `InboxQuery`, `KnownSenderQuery` (for discovered-senders panel), `CurrentUser`, `ViewFactory`; passes `$inboxes`, `$discoveredSenders`, `$activeBackfill` to the Blade view.

**Constructor injection is BANNED on Livewire Components** (per docblock line 39): every service arrives as an argument on an action method or `render()`. **Critical invariant for Phase 6.**

**Why this analog:** Exact role + data flow. The `/inboxes` page is structurally `/chains/review` with different DTOs.

---

### `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` + `BackfillWindowModal.php`

**Analog:** `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` + `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php`

**Flux modal scaffold + open-on-event pattern** (ChainDrawer.php lines 57-75):
```php
final class ChainDrawer extends Component
{
    public ?int $transactionId = null;

    #[On('chain-drawer:open')]
    public function open(int $transactionId): void
    {
        $this->transactionId = $transactionId;
        // ... reset state
    }
```

Phase 6 mirror:
- `OAuthClientWizardModal`: `#[On('oauth-client-wizard:open')]` setter for `?string $provider` ('gmail' | 'microsoft'); form-state public properties `string $clientId`, `string $clientSecret`, `bool $publishedConfirmed` (Pitfall 1 mandatory checkbox); `submit()` method writes via `OAuthSecretsRepository` then redirects to OAuth consent flow.
- `BackfillWindowModal`: `#[On('backfill-window:open')]` setter for `?int $inboxId`; `int $months = 3` slider state; `submit()` dispatches `BackfillInboxJob` with `min(12, max(1, $this->months))` defensive clamp.

**flux:modal blade scaffold** (chain-drawer.blade.php lines 21-67):
```blade
<div>
    <flux:modal name="chain-drawer-{{ $tree?->rootTransactionId ?? 0 }}" flyout position="right" class="md:w-2xl">
        <flux:heading size="lg" class="sticky top-0 bg-white z-10 pb-3 -mx-6 px-6">
            {{-- header --}}
        </flux:heading>
        {{-- body --}}
    </flux:modal>
</div>
```

Phase 6 modals: drop the `flyout position="right"` attributes — use `<flux:modal name="oauth-client-wizard-{{ $provider }}" class="md:max-w-2xl">` for the wizard (centered modal, NOT a flyout, per UI-SPEC § "first `flux:modal` (non-flyout)") and `class="md:max-w-lg"` for the backfill picker.

**Why this analog:** Same Livewire + Flux scaffolding shape; the only delta is `flyout` attribute removal for the centered modal posture.

---

### `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php`

**Analog:** `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php`

**Page envelope + heading + empty state pattern** (lines 39-53):
```blade
<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-12">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Review chains</h1>
        <p class="mt-2 text-sm text-slate-500">
            Confirm or reject candidate links the chain resolver could not auto-confirm.
        </p>
    </header>

    @if (count($candidates) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900">Nothing to review</h2>
            <p class="mt-2 text-sm text-slate-500">
                Every chain link is either confirmed or rejected. New candidates will appear here as imports land.
            </p>
        </div>
    @else
        {{-- list of rows --}}
    @endif
</div>
```

Phase 6 mirror: same wrapper + heading shape; empty-state block becomes the D-127 hero ("Connect your email to import receipts…"); table block is the connected-inboxes table.

**Per-row Tailwind shape (Confirm/Reject buttons)** (lines 79-92):
```blade
<button
    type="button"
    wire:click="confirm({{ $row->chainLinkId }})"
    aria-label="Confirm chain link {{ $row->chainLinkId }}"
    class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
>Confirm</button>
```

Phase 6 mirror for `/inboxes` row actions (Scan Now / Reconnect / Edit window) — same `wire:click="..."` shape, same `aria-label`, same focus-visible ring discipline. Discovered-senders Add/Dismiss chips are the literal same shape with different copy.

**`wire:poll` conditional polling** — see `Modules/Import/Resources/views/livewire/preview-wizard.blade.php` lines 193-209:
```blade
@if ($chainResolutionStatus !== null && $chainResolutionStatus !== 'complete')
    <section
        wire:poll.2s="refreshChainResolutionStatus"
        class="rounded-md border border-slate-200 bg-white p-6"
        aria-live="polite"
    >
        <h3 class="text-base font-semibold text-slate-900">Resolving chains…</h3>
        {{-- body --}}
    </section>
@endif
```

Phase 6 mirror for the backfill progress strip: `@if ($activeBackfill !== null) <section wire:poll.2s="..."> ... </section>`. Per RESEARCH Pitfall 10, render `wire:poll.30s` instead when `inbox_scan_state.status='rate_limited'`, omit `wire:poll` entirely when `status='idle'` / `needs_reauth`.

**Why this analog:** Exact role + data flow; the entire calm-aesthetic Blade dialect (Tailwind class set, focus-visible, escaped `{{ }}` only, no `{!! !!}`) is locked in this file.

---

### `Modules/EmailScan/Database/Migrations/*_create_inbox_messages_table.php`

**Analog:** `Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php`

**Migration class shape + DatabaseManager memoisation** (lines 34-44, 120-134):
```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('chain_links', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // ...
        });
        // ... trigger statements
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

**FND-03 user_id pattern** (line 42):
```php
$table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
```

Every Phase 6 table includes this verbatim.

**Composite + UNIQUE index pattern** (lines 53-56):
```php
$table->index('from_transaction_id');
$table->index('to_transaction_id');
$table->index(['user_id', 'state']); // review-queue scan
```

Phase 6 mirror for `inbox_messages`:
- `$table->unique(['inbox_id', 'provider_message_id'])` — D-117 idempotency contract
- `$table->index(['user_id', 'status'])` — Phase 7 "fetched" scan hot path
- `$table->index('internal_date')` — date-range queries

**Enum-as-string with BEFORE INSERT/UPDATE trigger pair** (lines 61-75):
```php
$allowedKinds = "'paypal_funding','ics_bulk_settle'";
$connection->statement(sprintf(
    "CREATE TRIGGER chain_links_kind_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
     WHEN NEW.kind NOT IN (%s)
     BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
    $allowedKinds,
));
$connection->statement(sprintf(
    "CREATE TRIGGER chain_links_kind_check_update BEFORE UPDATE OF kind ON chain_links FOR EACH ROW
     WHEN NEW.kind NOT IN (%s)
     BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END",
    $allowedKinds,
));
```

Phase 6 enum columns:
- `inbox_messages.status` — `'fetched','parsed','skipped','unmatched'`
- `inbox_scan_state.status` — `'idle','backfilling','scanning','rate_limited','needs_reauth','error'`
- `inboxes.provider` — `'gmail','microsoft'`
- `known_senders.source` — `'system','user'`
- `discovered_senders.state` — `'candidate','added','dismissed'`

Each gets the same paired-trigger idiom verbatim.

**Why this analog:** Every Phase 6 migration uses the same Blueprint + trigger-pair shape; differs only in column list.

---

### `Modules/EmailScan/Database/Migrations/*_create_known_senders_table.php` (with system seed)

**Analog:** `Modules/Chains/Database/Migrations/2026_05_16_010002_create_card_statements_table.php` (schema shape) + `2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php` (seed shape)

Use the same migration class shape (Container DI + memoised DatabaseManager). For the system seed, `up()` inserts after the trigger pair is in place:

```php
$now = now()->toDateTimeString();
$connection->table('known_senders')->insert([
    ['user_id' => null, 'email_pattern' => 'paypal.com',                  'label' => 'PayPal',      'source' => 'system', 'added_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ['user_id' => null, 'email_pattern' => '@ics.nl',                     'label' => 'ICS Cards',   'source' => 'system', 'added_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ['user_id' => null, 'email_pattern' => 'googleplay-noreply@google.com','label' => 'Google Play', 'source' => 'system', 'added_at' => $now, 'created_at' => $now, 'updated_at' => $now],
]);
```

System rows carry `user_id = NULL` (per FND-03 nullable column); `KnownSenderQuery::all(User)` returns `WHERE user_id = $user->id OR user_id IS NULL`.

---

### `Modules/EmailScan/Public/Dto/InboxHealthDto.php` (+ KnownSenderDto + InboxMessageDto + EmailScanHealthTile)

**Analog:** `Modules/Chains/Public/Dto/ChainLinkRow.php` + `Modules/Chains/Public/Dto/CardStatementForecastTile.php`

**Data DTO shape with readonly constructor properties** (ChainLinkRow.php lines 22-37):
```php
final class ChainLinkRow extends Data
{
    public function __construct(
        public readonly int $chainLinkId,
        public readonly string $kind,
        public readonly string $state,
        public readonly float $confidence,
        public readonly string $fromCounterparty,
        public readonly Money $fromAmount,
        // ...
        public readonly int $confirmsRemaining,
    ) {}
}
```

**Dashboard-tile DTO with "null = hide tile" semantics** (CardStatementForecastTile.php lines 23-31):
```php
final class CardStatementForecastTile extends Data
{
    public function __construct(
        public readonly Money $amount,
        public readonly CarbonImmutable $dueDate,
        public readonly int $statementId,
        public readonly string $state,
    ) {}
}
```

Phase 6 mirror — `EmailScanHealthTile` carries `array<InboxScanLine> $lines` (one per connected inbox, max 3 + overflow count), `string $overallStatus` ('healthy' | 'stale' | 'reauth'); `null` return from `ThisPeriodAtAGlanceQuery::emailScanHealth()` means hide tile entirely (matches D-99 / D-125).

**Why this analog:** Exact match. All Phase 6 DTOs are `final class … extends Data` with readonly properties; no methods. Note that DTOs are the contract Phase 7 + the Livewire SFC consume — **never `Inbox::class` or other Eloquent models** (RESEARCH Anti-Pattern).

---

### `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (extend)

**Analog:** **Same file** — the existing `nextIcsSettlement(User $user): ?CardStatementForecastTile` method (lines 205-236).

**Direct query → null guard → DTO construction** (lines 207-235):
```php
public function nextIcsSettlement(User $user): ?CardStatementForecastTile
{
    $row = $this->db->connection()
        ->table('card_statements')
        ->join('accounts', 'accounts.id', '=', 'card_statements.account_id')
        ->where('card_statements.user_id', $user->id)
        ->where('accounts.kind', 'ics_card')
        ->whereIn('card_statements.state', ['open', 'partially_settled'])
        ->orderByDesc('card_statements.period_end')
        ->orderByDesc('card_statements.id')
        ->select(/* columns */)
        ->first();

    if ($row === null) {
        return null;
    }
    // ...
    return new CardStatementForecastTile(/* ... */);
}
```

Phase 6 mirror: add a new method `emailScanHealth(User $user): ?EmailScanHealthTile`. Same shape: query `inboxes` + LEFT JOIN `inbox_scan_state`, filter on `user_id`, return null when zero connected inboxes, else compose DTO. **Or** the planner may instead add a sibling `EmailScanHealthQuery` in `Modules/EmailScan/Public/Services/` — D-125 leaves this open. Either choice works; the existing precedent strongly favours extending the existing query (cohesion of "what the dashboard reads").

**Why this analog:** This is the exact method the new one mirrors. Same file, same DI, same null-guard pattern.

---

### `Modules/Core/Resources/views/livewire/top-nav.blade.php` (extend)

**Analog:** **Same file** — the existing "Review chains" nav entry (lines 42-58):
```blade
{{-- "Review chains" — open candidate count from ChainLinkQuery
     injected via the ChainsServiceProvider View Factory composer
     (issue #12 fix: View Factory contract resolved via
     $this->app->make(), never the view() global helper). --}}
<a
    href="{{ route('chains.review') }}"
    class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm {{ $isActive('/chains/review') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
>
    Review chains
    @if (($chainOpenCandidateCount ?? 0) > 0)
        <span
            class="inline-flex items-center justify-center rounded-full bg-slate-900 px-2 py-0.5 text-xs font-medium text-white"
            style="font-variant-numeric: tabular-nums;"
        >{{ $chainOpenCandidateCount > 99 ? '99+' : $chainOpenCandidateCount }}</span>
    @endif
</a>
```

Phase 6 mirror — copy verbatim, change to:
- `href="{{ route('inboxes.index') }}"`
- `$isActive('/inboxes')`
- Label `Inboxes`
- `($inboxesBadgeCount ?? 0)` (variable from `EmailScanServiceProvider::registerTopNavBadgeComposer`)

Per UI-SPEC, position the new entry between "Imports" and "Uncategorized" (or wherever the planner / UI-SPEC pass locks).

---

### `tests/Contracts/BoundaryArchTest.php` (extend)

**Analog:** **Same file** — three patterns already locked:

1. **Internal namespace containment** (lines 32-34):
   ```php
   arch('Modules\\Chains\\Internal is only used inside Modules\\Chains')
       ->expect('Modules\\Chains\\Internal')
       ->toOnlyBeUsedIn('Modules\\Chains');
   ```
   Phase 6 adds `Modules\\EmailScan\\Internal` row.

2. **Facade carve-out for Cache::driver('redis')** (lines 36-49):
   ```php
   arch('no Laravel facade usage in module code')
       ->expect('Illuminate\\Support\\Facades')
       ->not->toBeUsedIn('Modules')
       ->ignoring([
           'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
       ]);
   ```
   Phase 6 extends the `ignoring` list with the three new job FQNs:
   - `Modules\\EmailScan\\Internal\\Jobs\\BackfillInboxJob`
   - `Modules\\EmailScan\\Internal\\Jobs\\IncrementalScanJob`
   - `Modules\\EmailScan\\Internal\\Jobs\\DiscoveryScanJob`

3. **Per-table-write boundary grep** (lines 69-114, `noResolverWritesTransactions`):
   ```php
   it('does not allow any file under Modules/Chains/Internal/Resolvers/ to mutate the transactions table (noResolverWritesTransactions)', function (): void {
       $hits = [];
       $resolversDir = base_path('Modules/Chains/Internal/Resolvers');
       // ... recursive iterator over .php files ...
       $contents = (string) file_get_contents($path);
       $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
       if (
           preg_match('/Transaction::query|Transaction::where/', $stripped) === 1
           || preg_match("/->table\(['\"]transactions['\"]\)[^;]*->(update|insert|delete)\\s*\\(/", $stripped) === 1
       ) {
           $hits[] = $path;
       }
       // ...
       expect($hits)->toBe([], "...");
   });
   ```

   Phase 6 mirror — add `noTransactionWritesFromEmailScan` rule:
   ```php
   it('does not allow any file under Modules/EmailScan/ to mutate the transactions table (noTransactionWritesFromEmailScan)', function (): void {
       // walk Modules/EmailScan/, apply the same grep on transactions table writes.
   });
   ```

4. **Single-mutator boundary grep** (`noOtherCardStatementStateMutator`, lines 116-164) — Phase 6 adds an exact mirror for `inbox_scan_state.status`:
   ```php
   it('does not allow any file other than InboxScanStateMachine to mutate inbox_scan_state.status (noOtherInboxScanStateMutator)', ...);
   ```

**Why this analog:** Exact match — the four invariants Phase 6 needs (containment, facade carve-out, no-transaction-write, single-state-mutator) are all already in this file in copy-able form.

---

### `tests/Contracts/NoExtImapTest.php` (extend)

**Analog:** Existing Phase 1 PLT-05 ext-imap grep (referenced in RESEARCH.md line 1050).

Phase 6 adds an additional grep over `composer.lock` for `webklex/laravel-imap`, `webklex/php-imap`, and `ddeboer/imap` package names. Belt-and-braces with the new `composer.json` `"conflict"` block (RESEARCH.md lines 199-207).

---

### `Modules/Core/Internal/Console/InstallLaunchdCommand.php`

**Analog:** `Modules/Core/Internal/Console/InstallCommand.php`

**Console command shape + constructor DI** (InstallCommand.php lines 24-69):
```php
final class InstallCommand extends Command
{
    protected $signature = 'diederik:install
        {--email= : Email for the single-user account}
        {--password= : Password for the single-user account}
        {--period-start-day=1 : Period start day (1-28, 1 = calendar month, 25 = salary cycle)}';

    protected $description = 'Idempotent first-run setup: validate DB path, run migrations, create the single user.';

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }
```

**D-131 decision point:** the existing `diederik:install` command may be EXTENDED with a `--launchd` option (single command, multiple jobs) OR a sibling command may be added. CONTEXT.md D-131 specifies `php artisan diederik:install --launchd`; that implies extending the existing command. Phase 6 planner: add a new `--launchd` option to `Modules/Core/Internal/Console/InstallCommand.php`'s `$signature`, and a new branch in `handle()` that delegates to a Phase-6-owned helper (probably a new method on the same class, or a constructor-injected `LaunchdInstaller` from `Modules/EmailScan/Internal/Deploy/` — keeps the launchd plumbing inside the module that owns it).

**plist template + PHP_BINARY substitution** — RESEARCH.md Example 4 (lines 895-923) is the canonical template; no codebase analog yet.

**Why this analog:** Same class shape, same constructor DI, same `$signature` / `$description` discipline. The plist authoring is genuinely new (no analog) but everything around it is `InstallCommand`-shaped.

---

### `Modules/EmailScan/Routes/web.php`

**Analog:** `Modules/Chains/Routes/web.php` (entire file, 22 lines)

```php
use Illuminate\Support\Facades\Route;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/chains/review', ChainReviewQueue::class)
        ->name('chains.review');
});
```

Phase 6 mirror — single file:
```php
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/inboxes', InboxesPage::class)->name('inboxes.index');
    Route::get('/oauth/callback/{provider}', OAuthCallbackController::class)->name('oauth.callback');
    Route::post('/inboxes/{id}/scan-now', /* InboxesPage action method via Livewire */)->name('inboxes.scan-now');
    Route::post('/inboxes/{id}/reconnect',  /* ... */)->name('inboxes.reconnect');
    Route::post('/inboxes/{id}/window',     /* ... */)->name('inboxes.window');
    Route::post('/senders/{id}/promote',    /* ... */)->name('senders.promote');
    Route::post('/senders/{id}/dismiss',    /* ... */)->name('senders.dismiss');
});
```

The Livewire-component-as-route shape (`Route::get('/inboxes', InboxesPage::class)`) is the convention; most POST endpoints fold into Livewire `wire:click` action methods on the same component, so the explicit POST routes above may not all need to exist. Planner picks per UI-SPEC § Routing.

**Route::facade is permitted in module Routes/ files** (per CONTEXT.md / BoundaryArchTest exemption); the rest of the module is facade-free.

---

### `Modules/EmailScan/tests/fixtures/eml/{paypal,ics,googleplay}/*.eml`

**Analog:** `Modules/Chains/tests/fixtures/scenario-1/` (three synthesised cross-source files: `asn-camt053.xml`, `ics-statement.pdf`, `paypal-activity.csv`, plus `scenario-1-{overpaid,underpaid}.json` expected-state, plus `scenario-1.md` narrative).

Phase 6 mirror: synthesise anonymised `.eml` files (PayPal NL receipt, ICS monthly statement notification, Google Play purchase confirmation) under `Modules/EmailScan/tests/fixtures/eml/{paypal,ics,googleplay}/`. Pair with `api-responses/{gmail,graph}/*.json` for the Fake clients to replay. Add a `scenario.md` narrative describing the fixture's "expected post-fetch state" the way `scenario-1.md` does.

**Why this analog:** Exact match. Phase 5 D-107 + Phase 4 D-58 anonymisation discipline is the project-wide convention.

---

## Shared Patterns

### DI-only / no facades / no helpers

**Source:** CLAUDE.md (`feedback_laravel_di_only.md`) + `tests/Contracts/BoundaryArchTest.php` (lines 36-49).

**Apply to:** Every file under `Modules/EmailScan/` except `Routes/web.php` (which is exempt) and the three jobs (which carry the documented `Cache::driver('redis')` carve-out in `uniqueVia()` only).

**Concrete shape (visible at every constructor):**
```php
public function __construct(
    private readonly DatabaseManager $db,
    private readonly Clock $clock,
    private readonly CurrentUser $currentUser,
    private readonly OAuthSecretsRepository $secrets,
) {}
```

**Eloquent direct OK:** `Inbox::query()->where('user_id', $user->id)->...` is permitted (per CLAUDE.md memory note). **`DB::table(...)` is forbidden** — use the injected `DatabaseManager`.

**`Model::query()->exists()` is forbidden** (phpstan strict `staticMethod.dynamicCall`) — use `$this->db->connection()->table('inboxes')->where(...)->count() > 0`.

---

### Cross-user 404 invariant

**Source:** `Modules/Chains/Public/Services/ChainLinkQuery.php` lines 66-78 + `Modules/Chains/Public/Actions/ConfirmChainLink.php` lines 53-68.

**Apply to:** Every `/inboxes/{id}/*` action handler + every `Modules/EmailScan/Public/` read method that takes an `int $inboxId`.

**Two-layer defence shape:**
```php
// Layer 1 — read scoped to the current user; cross-user id returns null.
$row = $this->db->connection()->table('inboxes')
    ->where('id', $inboxId)
    ->where('user_id', $user->id)
    ->first(['...']);

if ($row === null) {
    throw new NotFoundHttpException('Inbox not found.');
}
```

**No `firstOrFail()`** (returns ModelNotFoundException, harder to assert outside HTTP) — explicit `NotFoundHttpException` raises the canonical 404 that Livewire's handler renders and unit tests can `expect(...)->toThrow(NotFoundHttpException::class)` on.

---

### Atomic .eml-then-DB ordering (cleanup-on-rollback)

**Source:** RESEARCH.md Pattern 3 (lines 502-523) — no codebase analog; this is Phase 6's signature pattern.

**Apply to:** `BackfillInboxJob::handle()`, `IncrementalScanJob::handle()`.

**Concrete shape:**
```php
$emlPath = $this->blobStore->pathFor($inbox->user_id, $inbox->id, $internalDate, $providerMessageId);
$this->blobStore->put($emlPath, $rawMime);
try {
    $this->db->connection()->transaction(function () use (/* ... */) {
        $this->db->connection()->statement('PRAGMA busy_timeout = 5000');
        $this->db->connection()->table('inbox_messages')->insertOrIgnore([/* ... */]);
        $this->db->connection()->table('inbox_scan_state')->where('inbox_id', $inbox->id)->update([/* ... */]);
    });
} catch (\Throwable $e) {
    $this->blobStore->delete($emlPath);
    throw $e;
}
```

UNIQUE `(inbox_id, provider_message_id)` makes `insertOrIgnore` idempotent on retry (RESEARCH line 523).

---

### SQLite WAL + busy_timeout pragma

**Source:** `Modules/Chains/Internal/CardStatementStateMachine.php` line 63.

**Apply to:** Every transaction inside the three new jobs + `InboxScanStateMachine`.

```php
$connection->statement('PRAGMA busy_timeout = 5000');
```

Mitigates SQLITE_BUSY when two `BackfillInboxJob`s for different inboxes of the same user run in parallel (RESEARCH Pitfall 6).

---

### Type-coercion helpers (strict-rules-clean)

**Source:** `Modules/Chains/Internal/CardStatementStateMachine.php` lines 112-123 (`toInt` / `toString`).

**Apply to:** Every Public service that reads raw query-builder scalars (stdClass attributes come back as strings from SQLite).

Copy verbatim into every read-side service.

---

### Failed-job audit-row listener

**Source:** `Modules/Chains/Providers/ChainsServiceProvider.php` lines 152-217 (`registerJobFailedListener` + `extractUserIdFromFailedJob`).

**Apply to:** `EmailScanServiceProvider::boot()` for `BackfillInboxJob` / `IncrementalScanJob` (DiscoveryScanJob's failure is less critical — planner may skip).

Replace the substring match against `'ResolveChainLinksJob'` with a check against `BackfillInboxJob|IncrementalScanJob`; replace `chain_resolution_runs` updates with `inbox_scan_state.status = 'error'` via `InboxScanStateMachine::applyStatus(...)`.

---

### View Factory composer for top-nav badge (issue #12 fix)

**Source:** `Modules/Chains/Providers/ChainsServiceProvider.php` lines 122-137.

**Apply to:** `EmailScanServiceProvider::registerTopNavBadgeComposer()`.

Critical invariant: NEVER `view()->composer(...)` (global helper banned). Always `$this->app->make(ViewFactoryContract::class)->composer(...)`.

---

### Failed-job toast on dashboard

**Source:** Phase 5 toast pattern referenced in CONTEXT.md ("Failed-job toast pattern from Phase 4/5"); precise file location not surveyed here.

**Apply to:** D-115 needs-reauth one-shot toast (first time `inbox_scan_state.status` transitions to `needs_reauth`).

Planner: locate the Phase 5 toast emitter (likely `Modules/Ledger/.../Dashboard.php` or similar) and mirror — `$this->dispatch('toast', message: '...')` + Alpine `x-on:toast.window` listener.

---

### Synthesised fixture corpus + Fake clients

**Source:** `Modules/Chains/tests/fixtures/scenario-1/` (cross-source synthesised trio).

**Apply to:** `Modules/EmailScan/tests/fixtures/{eml,api-responses}/` + `FakeGmailApiClient` + `FakeGraphApiClient`.

The Fakes implement the same public interface as the real clients (`listSenderMessages()`, `getRawMessage()`, `listHistory()`, etc.); the test container binds the Fake to the real class via the ServiceProvider in `TestCase::setUp()` or per-test `$this->app->instance(GmailApiClient::class, $fake)`.

---

## Files With No Codebase Analog (planner uses RESEARCH.md)

Phase 6 introduces five genuinely new patterns that don't have a prior-phase template:

| File | Role | Why no analog | RESEARCH.md reference |
|------|------|---------------|-----------------------|
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` | atomic chmod-600 JSON repository | First filesystem-as-secrets-store in the project | Example 2 (lines 836-877) — copy this shape |
| `Modules/EmailScan/Internal/Clients/GmailApiClient.php` | external SDK wrapper | First wrapper around an official vendor SDK | Pattern 1 (lines 407-462) + Pattern 2 (ScanCursor, lines 464-500) |
| `Modules/EmailScan/Internal/Clients/GraphApiClient.php` | external SDK wrapper | Same as above | Pattern 1 + Pattern 4 (Two-Phase Graph Scan, lines 525-536) |
| `Modules/EmailScan/Internal/OAuth/{Google,Microsoft}OAuthProvider.php` | OAuth provider thin wrapper | First league/oauth2-client surface | Example 1 (lines 757-832) |
| `deploy/launchd/com.diederik.{horizon,scheduler,redis}.plist` | macOS launchd plist | First plist asset in the repo | Pitfall 7 plist template (lines 682-711) — copy verbatim, substitute `{{ABS_PHP_BINARY}}` + `{{ABS_PROJECT_ROOT}}` at install time |
| `Modules/EmailScan/Public/Dto/ScanCursor.php` | provider-cursor value object | First value object normalising two provider semantics | Pattern 2 (lines 464-500) — copy `gmail()` / `microsoft()` / `emptyFor()` factory shape |
| `Modules/EmailScan/Internal/EmlBlobStore.php` | filesystem repository | First raw-blob persistence layer | D-116 path scheme + RESEARCH lines 308-318 atomicity rule |
| `Modules/EmailScan/Internal/MimeHeaderParser.php` | zbateson facade | First zbateson/mail-mime-parser surface | Example 3 (lines 880-893) |
| `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php` | invokable controller (non-Livewire) | First traditional controller class in module code | Example 1 (lines 757-832) — full handler walkthrough |

The planner should write these directly from RESEARCH.md without trying to force-fit an unrelated Phase 5 analog.

---

## Pattern Summary for Planner

The dominant story: **Phase 6 is Phase 5's `Modules/Chains/` shape, with EmailScan-specific contents.** Approximately 90% of file scaffolding can be produced by mechanically copying the corresponding `Modules/Chains/*` file and renaming. The remaining 10% (OAuth, atomic secrets, external SDKs, launchd) is genuinely new and lives in RESEARCH.md's "Patterns" + "Code Examples" sections.

For each Wave's plan, the action sections should explicitly cite:
1. The analog file in `Modules/Chains/` (or earlier module) — give a path + line range.
2. The specific decision (D-XXX) the new file implements.
3. The RESEARCH.md pattern / example number when no codebase analog applies.

## Metadata

**Analog search scope:** `Modules/Chains/` (entire module), `Modules/Core/Resources/views/livewire/top-nav.blade.php`, `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php`, `Modules/Import/Resources/views/livewire/preview-wizard.blade.php`, `Modules/Core/Internal/Console/InstallCommand.php`, `tests/Contracts/BoundaryArchTest.php`, root `composer.json`, root `phpunit.xml`, root `tests/Pest.php`.
**Files scanned:** ~30
**Pattern extraction date:** 2026-05-16

## PATTERN MAPPING COMPLETE
