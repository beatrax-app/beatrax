# Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) - Pattern Map

**Mapped:** 2026-05-16
**Files analyzed:** 30 new + 8 modified
**Analogs found:** 36 / 38 (2 files have no direct codebase analog — patterns derived from RESEARCH.md Pattern sections)

This map binds every new / modified file in Phase 5 to a concrete existing analog in the codebase. The Phase 4 `Modules/Transfers/` module is the closest structural reference (composer.json, ServiceProvider, listener-shaped logic with `DatabaseManager` raw query builder, idempotency-safe symmetric writes). The Phase 1 ASN ingestion module + Phase 1 `transactions` migration (BEFORE-INSERT/UPDATE trigger pattern) supply the schema + service-provider analogs. The planner consumes these in `<read_first>` and `<action>` blocks.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Chains/composer.json` | module manifest | config | `Modules/Transfers/composer.json` | exact |
| `Modules/Chains/Providers/ChainsServiceProvider.php` | service provider | config / wiring | `Modules/Categorization/Providers/CategorizationServiceProvider.php` (Livewire + migrations + routes + views + bindings) + `Modules/Transfers/Providers/TransfersServiceProvider.php` (minimal listener-only shape) | exact (composite) |
| `Modules/Chains/Database/Migrations/*_create_chain_links_table.php` | schema migration (CREATE) | schema | `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` (CREATE shape) + `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` (CHECK-via-trigger for `type` enum) | exact (composite) |
| `Modules/Chains/Database/Migrations/*_create_card_statements_table.php` | schema migration (CREATE) | schema | `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` (same FK-shape, json `extras`) + `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` (state-enum trigger) | exact |
| `Modules/Chains/Database/Migrations/*_create_card_statement_credits_table.php` | schema migration (CREATE) | schema | `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` (small per-row table shape) | exact |
| `Modules/Chains/Database/Migrations/*_backpopulate_card_statements_from_statement_summaries.php` | data migration (one-shot back-population) | read-then-write | `Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php` (existing forward-only data migration with row-iteration) | role match (data migration) |
| `Modules/Chains/Models/ChainLink.php` | Eloquent model | data | `Modules/Ledger/Models/StatementSummary.php` (BelongsToUser + json cast + Carbon casts) | exact |
| `Modules/Chains/Models/CardStatement.php` | Eloquent model | data | `Modules/Ledger/Models/StatementSummary.php` | exact |
| `Modules/Chains/Models/CardStatementCredit.php` | Eloquent model | data | `Modules/Ledger/Models/StatementSummary.php` (smaller subset) | exact |
| `Modules/Chains/Public/Dto/ChainTree.php` | typed DTO | data | `Modules/Ledger/Public/Dto/DashboardSummary.php` (composite DTO with nested-DTO array) | exact |
| `Modules/Chains/Public/Dto/ChainTreeNode.php` | typed DTO | data | `Modules/Ledger/Public/Dto/PerCurrencyTile.php` (small leaf DTO) | exact |
| `Modules/Chains/Public/Dto/CardStatementForecastTile.php` | typed DTO | data | `Modules/Ledger/Public/Dto/PerCurrencyTile.php` | exact |
| `Modules/Chains/Public/Dto/ChainLinkRow.php` | typed DTO | data | `Modules/Ledger/Public/Dto/TransactionRowDto.php` | exact |
| `Modules/Chains/Public/Services/ChainLinkQuery.php` | Public read query | read-only | `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (DatabaseManager + raw query builder + DTO composition) | exact |
| `Modules/Chains/Public/Services/CardStatementQuery.php` | Public read query | read-only | `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` | exact |
| `Modules/Chains/Public/Actions/ConfirmChainLink.php` | invokable action | read-then-write | `Modules/Categorization/Public/Actions/AssignCategory.php` (invokable __invoke + event dispatch) + `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php` (defence-in-depth user-scoped guard) | exact (composite) |
| `Modules/Chains/Public/Actions/RejectChainLink.php` | invokable action | write | `Modules/Categorization/Public/Actions/AssignCategory.php` | exact |
| `Modules/Chains/Internal/CardStatementStateMachine.php` | encapsulated state mutator | write (transactional) | `Modules/Ledger/Public/Actions/RecordTransactions.php` (`$db->connection()->transaction(...)` wrapper + raw query builder + Clock injection) | role match |
| `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | queued job | event-driven (async) | **No exact analog** — project has no queued jobs today. Closest shape is `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (event-handler class shape). Job-contract details (`ShouldBeUniqueUntilProcessing`, `uniqueId`, `uniqueVia`, `backoff`, `tries`) come from RESEARCH.md Pattern 3. | partial (no analog) |
| `Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php` | resolver service | read-then-write (chain_links only) | `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (DatabaseManager raw query + symmetric-write idiom + cross-user safety guard) | role match |
| `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` | resolver service | read-then-write | `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (same shape) + algorithm from RESEARCH.md Pattern 4 | role match |
| `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` | Livewire SFC (page) | read-then-write | `Modules/Categorization/Internal/Http/Livewire/TriageInbox.php` (paginated list + per-row action + cursor pagination) | exact |
| `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` | Livewire SFC (drawer) | read-only + dispatch | `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` (mounted Livewire detail surface, cross-user 404 guard, `$this->dispatch('toast', ...)`) | role match |
| `Modules/Chains/Internal/Http/Livewire/NextIcsSettlementTile.php` | Livewire SFC (small tile) — *optional, planner can render via dashboard view directly* | read-only | `Modules/Core/Internal/Http/Livewire/Dashboard.php` (`render()` collaborator DI pattern) | role match |
| `Modules/Chains/Routes/web.php` | route file | config | `Modules/Categorization/Routes/web.php` (`/uncategorized` route) + `Modules/Ledger/Routes/web.php` (Livewire-component-as-page handler) | exact |
| `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php` | Blade view | view | `Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php` | exact |
| `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` | Blade view (Flux flyout) | view | **No exact analog** — first project use of `<flux:modal flyout>`. Closest shape is `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` lines 22-25 (existing `<flux:radio.group>` pattern in same component library). Drawer skeleton from RESEARCH.md Pattern 6. | partial |
| `Modules/Chains/tests/Pest.php` | test bootstrap | test | `Modules/Transfers/tests/Pest.php` | exact |
| `Modules/Chains/tests/TestCase.php` | test base | test | `Modules/Transfers/tests/TestCase.php` | exact |
| `Modules/Chains/tests/Unit/Resolvers/IcsSettlementResolverTest.php` | unit test | test | `Modules/Categorization/tests/Feature/AssignCategoryTest.php` (per-user fixture setup) + Pest dataset shape from `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsAmountParserTest.php` | role match |
| `Modules/Chains/tests/Unit/Resolvers/PaypalFundingResolverTest.php` | unit test | test | `Modules/Categorization/tests/Feature/AssignCategoryTest.php` | role match |
| `Modules/Chains/tests/Unit/CardStatementStateMachineTest.php` | unit test | test | `Modules/Categorization/tests/Feature/AssignCategoryTest.php` | role match |
| `Modules/Chains/tests/Feature/ChainReviewQueueTest.php` | feature test (Livewire) | test | `Modules/Categorization/tests/Feature/TriagePageTest.php` (Livewire SFC test pattern) | exact |
| `Modules/Chains/tests/Feature/ChainDrawerTest.php` | feature test (Livewire) | test | `Modules/Categorization/tests/Feature/TriagePageTest.php` | exact |
| `Modules/Chains/tests/Contracts/ChainResolutionIdempotencyTest.php` | contract test | test | `tests/Contracts/IdempotencyContractTest.php` (cross-format dataset shape) + `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` (idempotency-on-re-fire pattern) | role match |
| `Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml` | test fixture | static data | `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` (committed redacted fixture precedent) | role match (synthesised, not anonymised) |
| `Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf` | test fixture | static data | `Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf` | role match (synthesised) |
| `Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv` | test fixture | static data | `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` | role match (synthesised) |
| `Modules/Chains/tests/fixtures/scenario-1/scenario-1.md` | fixture-record doc | doc | `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` + `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` | exact |
| `scripts/synthesise_phase5_scenario.php` | composer-dep-free CLI script | transform / write | `scripts/anonymize_paypal_csv.php` (shebang + idempotent + composer-dep-free shape) + `scripts/generate_tiny_ics_pdf.php` (PDF emit shape) | exact (composite) |
| `app/Providers/HorizonServiceProvider.php` | service provider | config | `Modules/Core/Internal/Providers/FortifyServiceProvider.php` (existing config-publish ServiceProvider with auth gate) | role match |
| `Modules/Transfers/Public/Services/PairLookup.php` (NEW — D-110 promotion) | Public read query | read-only | `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (DatabaseManager + raw query builder) + `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (existing partner-query pattern) | role match (composite) |
| `Modules/Import/Public/Actions/ConfirmImport.php` (MODIFIED) | action / orchestrator | event-driven | self — extend post-`transaction()` block to dispatch `ResolveChainLinksJob` | self |
| `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (MODIFIED) | Public read query | read-only | self — extend with `nextIcsSettlement(User $user): ?CardStatementForecastTile` method | self |
| `tests/Contracts/BoundaryArchTest.php` (MODIFIED) | architectural contract | test | self — extend with three new invariants (D-84 / D-95 / no-`DB::*`-in-Chains) mirroring the existing `noPaypalApiRoute` grep-based pattern | self |
| `bootstrap/providers.php` (MODIFIED) | bootstrap config | config | self — add `ChainsServiceProvider::class` line | self |
| `config/horizon.php` (NEW — published by `php artisan horizon:install`) | infrastructure config | config | `config/database.php` + `config/auth.php` (existing Laravel-shape config files) | role match |
| `composer.json` (MODIFIED — root) | dependency manifest | config | self — add `laravel/horizon ^5.x` + `predis/predis ^3.x` + `Modules\\Chains\\Tests\\` autoload-dev entry | self |
| `.planning/PROJECT.md` (MODIFIED) | doc | doc | self — atomic amendment per D-101 / D-102 (mirror Phase 4's REQUIREMENTS.md ING-09 / ROADMAP SC #2 edit posture) | self |
| `README.md` (MODIFIED) | doc | doc | self — Docker Redis setup section + `php artisan horizon` second-terminal note | self |

## Pattern Assignments

### `Modules/Chains/composer.json` (module manifest)

**Analog:** `Modules/Transfers/composer.json` (16 lines — read whole file)

**Verbatim shape with namespace swap:**
```json
{
    "name": "diederik/chains",
    "description": "Chains module — cross-source funding-chain resolver + ICS bulk-iDEAL decomposer + card_statements model.",
    "type": "laravel-module",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Modules\\Chains\\": ""
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Chains\\Tests\\": "tests/"
        }
    }
}
```

**Delta vs Transfers:** Description string changes; psr-4 namespace differs. Nothing else.

---

### `Modules/Chains/Providers/ChainsServiceProvider.php` (service provider)

**Primary analog:** `Modules/Categorization/Providers/CategorizationServiceProvider.php` (53 lines — read whole file; canonical full-feature provider shape with migrations + routes + views + Livewire + DI bindings)
**Secondary analog:** `Modules/Transfers/Providers/TransfersServiceProvider.php` (42 lines — minimal listener-only shape for the `Public/` empty case)

**Imports pattern** (copy from CategorizationServiceProvider lines 1-17):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Chains\Internal\Http\Livewire\ChainDrawer;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Chains\Public\Services\CardStatementQuery;
```

**Class skeleton** (Categorization lines 32-53 + Transfers lines 29-41 composite):
```php
final class ChainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChainLinkQuery::class);
        $this->app->singleton(CardStatementQuery::class);
        // No Public-contract → Internal-impl binding needed for ConfirmChainLink/
        // RejectChainLink: those are invokable Actions resolved directly by
        // type-hint (same pattern Phase 1's RecordTransactions uses — bound by
        // contract elsewhere, but Categorization's AssignCategory shows the
        // direct-resolve pattern works for actions that don't need a swap seam
        // yet).
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'chains');

        $livewire->component('chains.chain-review-queue', ChainReviewQueue::class);
        $livewire->component('chains.chain-drawer', ChainDrawer::class);
    }
}
```

**Delta vs Categorization:** No `Dispatcher` injection in `boot()` — Phase 5 listener-pattern is the queued job dispatched from `ConfirmImport`, not an event listener. No `UserInstalled` subscription. No Public-contract-to-impl bindings (the actions are concrete invocables resolved directly, mirroring `AssignCategory`).

---

### `Modules/Chains/Database/Migrations/*_create_chain_links_table.php` (schema migration)

**Primary analog:** `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` (60 lines — read whole file; CREATE shape with FKs + json + UNIQUE + memoised schema helper)
**Secondary analog:** `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` lines 80-98 (the CHECK-via-trigger pattern for `transactions.type` enum that Phase 5's `chain_links.kind` + `chain_links.state` enums mirror)
**Tertiary analog:** `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` (97 lines — memoised `$resolvedDb` pattern + container resolution; **the most-recent Phase 4 migration with the correct DI-only-exception comment**)

**Skeleton** (verbatim shape from `create_statement_summaries_table.php` lines 1-60 — anonymous-class + memoised schema helper):
```php
<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class extends Migration
{
    /**
     * Memoised DatabaseManager handle. Anonymous migrations cannot
     * receive constructor injection — Laravel's migrator instantiates
     * them with no arguments — so the DatabaseManager is resolved once
     * from the container at the migration boundary and cached for the
     * duration of the up/down call. This is the standing Laravel-
     * migration exception to the DI-only rule.
     */
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('chain_links', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('to_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('state', 16);
            $table->decimal('confidence', 4, 3);   // 0.000..1.000
            $table->string('resolver', 8);          // 'auto' | 'user' | 'rule'
            $table->json('evidence');
            $table->timestamps();

            $table->index('from_transaction_id');
            $table->index('to_transaction_id');
            $table->index(['user_id', 'state']);   // review-queue scan
        });

        // CHECK constraints implemented as BEFORE INSERT / BEFORE UPDATE
        // triggers — same shape Phase 1 uses on transactions.type
        // (Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php
        // lines 80-98). SQLite cannot ALTER TABLE ADD CHECK after the
        // fact, so the trigger-pair pattern is the standing project
        // idiom for enum-shaped string columns.
        $connection = $this->db()->connection($this->getConnection());
        $allowedKinds = "'paypal_funding','ics_bulk_settle'";
        $allowedStates = "'candidate','confirmed','rejected'";

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
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_state_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.state value'); END",
            $allowedStates,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER chain_links_state_check_update BEFORE UPDATE OF state ON chain_links FOR EACH ROW
             WHEN NEW.state NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.state value'); END",
            $allowedStates,
        ));
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('chain_links');
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

**Critical pattern reminders:**
- **Trigger-pair for enum CHECK** mirrors the canonical Phase 1 shape at `transactions_type_check_insert` / `transactions_type_check_update`. RESEARCH.md Pattern 1 proposed table-level CHECK as an alternative; **lock to the trigger-pair pattern** for consistency with the Phase 1 idiom.
- **Memoised `$resolvedDb`** pattern is the canonical Phase 4 shape (verified at `2026_05_15_010002_add_pair_transaction_id_to_transactions.php` lines 41, 87-95). Earlier migrations use the inline `app(DatabaseManager::class)` shorthand (`create_statement_summaries_table.php` line 56); the memoised shape is preferred for new migrations.

**Migration timestamp slot:** Latest existing Phase 4 migration is `2026_05_15_010002_*`. Phase 5 migrations should land at `2026_05_16_010001_*` through `2026_05_16_010004_*` (one date-step forward, 4 sequential slots). Planner locks exact filenames after verifying no concurrent Phase 5 work has claimed earlier slots.

---

### `Modules/Chains/Database/Migrations/*_create_card_statements_table.php` (schema migration)

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` (60 lines — the table this back-populates FROM).

**Schema (per D-94):**
```php
$this->schema()->create('card_statements', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
    $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
    $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
    $table->dateTime('period_start');
    $table->dateTime('period_end');
    $table->bigInteger('total_amount_minor');     // negative (outstanding)
    $table->bigInteger('open_balance_minor');     // positive (remaining to settle)
    $table->string('state', 24);                  // open | partially_settled | settled | overpaid
    $table->timestamps();

    $table->unique(['user_id', 'account_id', 'period_start', 'period_end']);
    $table->index(['user_id', 'state']);
});

// state enum trigger-pair (mirrors chain_links migration):
$connection = $this->db()->connection($this->getConnection());
$allowedStates = "'open','partially_settled','settled','overpaid'";
// ... same shape as chain_links state CHECK triggers ...
```

**Delta vs statement_summaries:** The new table is a forward-looking model of statement lifecycle state (not historical metadata). `total_amount_minor` + `open_balance_minor` are mutable (state machine writes them). `import_run_id` is nullable + `nullOnDelete` because back-populated rows may outlive their original import_run; ongoing-imports also need to refresh the row without losing it on import_run delete.

---

### `Modules/Chains/Database/Migrations/*_create_card_statement_credits_table.php` (schema migration)

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php` (60 lines — same FK shape).

**Schema (per D-96):**
```php
$this->schema()->create('card_statement_credits', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
    $table->foreignId('from_statement_id')->constrained('card_statements')->cascadeOnDelete();
    $table->foreignId('to_statement_id')->nullable()->constrained('card_statements')->nullOnDelete();
    $table->bigInteger('amount_minor');
    $table->string('reason', 32);                 // 'overpayment' | 'refund_after_close'
    $table->timestamps();

    $table->index(['user_id', 'to_statement_id']);
});

// reason enum trigger-pair:
$allowedReasons = "'overpayment','refund_after_close'";
// ... same trigger-pair pattern ...
```

**Delta:** `to_statement_id` is nullable + `nullOnDelete` because an overpayment surplus may exist before the next statement period rolls in (D-96). When the next statement lands, a follow-up resolver pass updates `to_statement_id` to point at it.

---

### `Modules/Chains/Database/Migrations/*_backpopulate_card_statements_from_statement_summaries.php` (data migration)

**Analog:** `Modules/Ledger/Database/Migrations/2026_05_13_010001_rederive_fingerprints_to_v3.php` (existing forward-only data migration — read for the row-iteration + transactional shape).

**Skeleton (D-94 — one-shot back-population, idempotent via `insertOrIgnore`):**
```php
public function up(): void
{
    $connection = $this->db()->connection($this->getConnection());

    // Pull every statement_summaries row whose account is ICS-kind.
    // Cross-table join via raw query builder to stay phpstan-strict-rules
    // clean (Eloquent::join() triggers staticMethod.dynamicCall).
    $rows = $connection
        ->table('statement_summaries')
        ->join('accounts', 'accounts.id', '=', 'statement_summaries.account_id')
        ->where('accounts.kind', 'ics_card')
        ->select(
            'statement_summaries.user_id',
            'statement_summaries.account_id',
            'statement_summaries.import_run_id',
            'statement_summaries.period_start',
            'statement_summaries.period_end',
            'statement_summaries.closing_balance_minor',
        )
        ->get();

    foreach ($rows as $row) {
        // Idempotent: insertOrIgnore against UNIQUE (user_id, account_id,
        // period_start, period_end) so a re-run produces zero new rows.
        $connection->table('card_statements')->insertOrIgnore([
            'user_id'            => $row->user_id,
            'account_id'         => $row->account_id,
            'import_run_id'      => $row->import_run_id,
            'period_start'       => $row->period_start,
            'period_end'         => $row->period_end,
            'total_amount_minor' => $row->closing_balance_minor,
            'open_balance_minor' => abs((int) $row->closing_balance_minor),
            'state'              => 'open',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }
}

public function down(): void
{
    // Forward-only — leave card_statements rows in place. Drop the
    // table itself via the create migration's down() if rollback is
    // genuinely needed in dev (rerunning up() is idempotent).
}
```

**Pitfall guard (RESEARCH Pitfall 7):** `insertOrIgnore` against the UNIQUE constraint makes the migration re-runnable in dev. **Do not** use plain `insert` — re-running would 23000 the UNIQUE.

---

### `Modules/Chains/Models/ChainLink.php` / `CardStatement.php` / `CardStatementCredit.php` (Eloquent models)

**Analog:** `Modules/Ledger/Models/StatementSummary.php` (read whole file — 88 lines; canonical BelongsToUser + json cast + Carbon casts shape)

**Imports pattern** (verbatim from StatementSummary lines 1-12):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Transaction;
```

**Class skeleton — `ChainLink`** (BelongsToUser + $fillable + casts + relations):
```php
/**
 * @property int $id
 * @property int|null $user_id
 * @property int $from_transaction_id
 * @property int $to_transaction_id
 * @property string $kind                   // 'paypal_funding' | 'ics_bulk_settle'
 * @property string $state                  // 'candidate' | 'confirmed' | 'rejected'
 * @property string $confidence             // decimal 0.000..1.000 (string for precision)
 * @property string $resolver               // 'auto' | 'user' | 'rule'
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ChainLink extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'from_transaction_id', 'to_transaction_id',
        'kind', 'state', 'confidence', 'resolver', 'evidence',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function fromTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'from_transaction_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function toTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'to_transaction_id');
    }
}
```

**Critical pattern reminders:**
- `BelongsToUser` trait (project-wide invariant) — every domain model uses it. Verified across StatementSummary, Transaction, ImportRun.
- `confidence` cast: **decimal column comes back as a string** from SQLite — leave it cast-less so PHPStan's strict-rules `cast.string` rule stays happy. Resolvers + tests compare via `(float)` cast at the boundary.
- No `Currency` cast: not used here (chain_links carry no money column).

**For `CardStatement.php`:** Same shape + `total_amount_minor` / `open_balance_minor` cast to `integer`; `period_start` / `period_end` cast to `immutable_datetime`. Relations: BelongsTo Account + BelongsTo ImportRun.

**For `CardStatementCredit.php`:** Same shape + `amount_minor` cast to `integer`. Relations: BelongsTo `CardStatement` for both `from_statement_id` and `to_statement_id`.

---

### `Modules/Chains/Public/Dto/ChainTree.php` / `ChainTreeNode.php` / `CardStatementForecastTile.php` / `ChainLinkRow.php` (typed DTOs)

**Primary analog (composite + nested array):** `Modules/Ledger/Public/Dto/DashboardSummary.php` (35 lines — read whole file)
**Secondary analog (small leaf DTO):** `Modules/Ledger/Public/Dto/PerCurrencyTile.php` (34 lines — read whole file)

**Imports pattern** (verbatim from PerCurrencyTile lines 1-9):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;
```

**Class skeleton — `CardStatementForecastTile`** (mirrors PerCurrencyTile):
```php
/**
 * Single-row payload for the dashboard's "Next ICS settlement" tile (D-99).
 *
 * `amount` is denominated in the card_statement's display currency
 * (typically EUR for the diederik ICS account). `dueDate` is computed as
 * `period_end + 5 calendar days` per D-100; the constant 5-day lag is
 * Phase 5's forecast — Phase 8 refines via the user's prior cadence.
 *
 * The tile is hidden on the dashboard when this DTO is null (no open
 * card_statement exists). Never serialises a placeholder "—" value.
 */
final class CardStatementForecastTile extends Data
{
    public function __construct(
        public readonly Money $amount,
        public readonly \Carbon\CarbonImmutable $dueDate,
        public readonly int $statementId,
        public readonly string $state,    // 'open' | 'partially_settled'
    ) {}
}
```

**For `ChainTree`** (composite DTO with nested array of `ChainTreeNode`):
```php
final class ChainTree extends Data
{
    /** @param array<ChainTreeNode> $nodes */
    public function __construct(
        public readonly int $rootTransactionId,
        public readonly array $nodes,                      // ordered top-down (root → funder)
    ) {}
}
```

**For `ChainTreeNode`** (leaf DTO — one waterfall leg):
```php
final class ChainTreeNode extends Data
{
    /** @param array<ChainTreeNode> $children — for ICS bulk-settle fan-out */
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $chainLinkId,                  // null on root
        public readonly string $counterpartyName,
        public readonly Money $amount,
        public readonly \Carbon\CarbonImmutable $bookedAt,
        public readonly string $accountName,
        public readonly string $kind,                       // 'root' | 'paypal_funding' | 'ics_bulk_settle'
        public readonly string $confidenceTier,             // 'Deterministic' | 'Confirmed' | 'Candidate'
        public readonly array $children = [],
    ) {}
}
```

**Delta vs PerCurrencyTile:** Phase 5 DTOs have richer payloads (multiple display fields per node) but the same `final class extends Data` + readonly constructor + `@param` docblock-array annotation invariant from Phase 3 holds.

---

### `Modules/Chains/Public/Services/ChainLinkQuery.php` / `CardStatementQuery.php` (Public read query)

**Primary analog:** `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (read whole file — 204 lines; canonical DI + DatabaseManager raw query + integer-coerce helper + DTO composition)
**Secondary analog:** `Modules/Categorization/Public/Services/UncategorizedTriageQuery.php` (for the cursor pagination shape — when `ChainReviewQueue` uses paginated query backing)

**Imports pattern** (verbatim from ThisPeriodAtAGlanceQuery lines 1-15):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\Money;
```

**Class skeleton — `ChainLinkQuery`** (DI-only, `final`, single-purpose query methods):
```php
final class ChainLinkQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Build the chain tree for one transaction (the "View chain" drill-down
     * surface). Walks chain_links recursively from the root transaction
     * upward toward funders. Confirmed + candidate links are both included;
     * rejected links are filtered out at query time.
     *
     * The walk depth is bounded — chains in the project's payment topology
     * are ≤5 levels — so a simple recursive PHP walk is fine here; SQLite
     * 3.45+ supports recursive CTEs but the bounded depth makes the PHP
     * walk simpler to test.
     */
    public function forTransaction(int $transactionId, User $user): ChainTree
    {
        // 1. Verify root transaction belongs to $user (cross-user 404 guard).
        // 2. Walk chain_links where from_transaction_id IN (...) starting at root.
        // 3. For each link, fetch the to_transaction row.
        // 4. Compose nodes top-down.
        // 5. Return ChainTree DTO.

        $rootRow = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['id', 'counterparty_name', 'amount_minor', 'currency',
                     'settled_amount_minor', 'settled_currency', 'booked_at',
                     'account_id']);

        if ($rootRow === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(
                "Transaction {$transactionId} not found."
            );
        }

        // ... walk chain_links from this root, building the tree ...
    }

    /**
     * Count of open candidates for the user — used by the top-nav badge
     * (D-86 / UI-SPEC § Top-nav count badge).
     */
    public function openCandidateCount(User $user): int
    {
        return $this->db->connection()
            ->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', 'candidate')
            ->count();
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
```

**Critical pattern reminders (from `ThisPeriodAtAGlanceQuery`):**
- Constructor-only DI; never `auth()` / `Auth::user()`; user comes in by parameter.
- Raw `$db->connection()->table(...)` query builder — never `Transaction::query()->whereBetween(...)` because Larastan `staticMethod.dynamicCall` fails Eloquent\Builder's dynamic methods.
- Helper `toInt(mixed $value): int` for `is_numeric ? (int) $value : 0` coercion (verified pattern at `ThisPeriodAtAGlanceQuery::toInt` line 188 + `PairTransferCandidates::toInt` line 198).
- Cross-user safety: every query filters on `$user->id` first.

**For `CardStatementQuery`:**
```php
public function openForAccount(int $accountId, User $user): ?CardStatement
{
    /** @var ?CardStatement */
    return CardStatement::query()
        ->where('user_id', $user->id)
        ->where('account_id', $accountId)
        ->whereIn('state', ['open', 'partially_settled'])
        ->orderByDesc('period_end')
        ->first();
}
```

Eloquent direct is OK here (no `whereBetween` / `whereIn` against Eloquent\Builder triggers the strict-rule; `whereIn` is fine because phpstan accepts it on Eloquent\Builder when there's no chained dynamic method afterward — verify against `staticMethod.dynamicCall` in the live phpstan config during planning).

---

### `Modules/Chains/Public/Actions/ConfirmChainLink.php` / `RejectChainLink.php` (invokable actions)

**Primary analog:** `Modules/Categorization/Public/Actions/AssignCategory.php` (42 lines — canonical Action: invokable + Dispatcher + Public-contract pattern)
**Secondary analog:** `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php` (53 lines — defence-in-depth user-scoped guard pattern)

**Imports pattern** (verbatim from AssignCategory lines 1-12):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
```

**Class skeleton — `ConfirmChainLink`** (composite of AssignCategory + UpdateTransactionCategory):
```php
/**
 * Promotes a candidate chain_link to confirmed; increments the per-user
 * signature counter; auto-promotes other candidates with the same
 * signature when the counter ≥3 (D-87 / D-88 learning loop).
 *
 * Same action class powers BOTH review surfaces (D-86): the
 * `/chains/review` page and the inline chips in `ChainDrawer`.
 *
 * Defence in depth: filters on (id, user_id) via firstOrFail before any
 * mutation. A cross-user invocation raises NotFoundHttpException (404) —
 * same pattern as `TransactionDetail::reclassify` (Modules/Ledger/
 * Internal/Http/Livewire/TransactionDetail.php lines 116-122).
 */
final class ConfirmChainLink
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->db->connection()->transaction(function () use ($link, $user): void {
            $link->state = 'confirmed';
            $link->save();

            // Auto-promotion learning loop (D-87 / D-88). Count confirmed
            // chain_links for this user whose evidence.signature_hash matches.
            $signatureHash = $link->evidence['signature_hash'] ?? null;
            if (! is_string($signatureHash)) {
                return;
            }

            $confirmedCount = $this->db->connection()
                ->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', 'confirmed')
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->count();

            if ($confirmedCount >= 3) {
                $this->db->connection()
                    ->table('chain_links')
                    ->where('user_id', $user->id)
                    ->where('state', 'candidate')
                    ->whereJsonContains('evidence->signature_hash', $signatureHash)
                    ->update([
                        'state'      => 'confirmed',
                        'resolver'   => 'rule',
                        'updated_at' => $this->clock->now()->toDateTimeString(),
                    ]);
            }
        });
    }
}
```

**Critical pattern reminders:**
- **Final readonly DI constructor** + `__invoke()` signature `(int $id, User $user): void` — same shape as `AssignCategory::__invoke()` (with `?int $categoryId` instead of `void`).
- **Cross-user 404 via `firstOrFail()`** — the canonical safety pattern. Phase 4 PATTERNS.md "Authentication / Cross-User Safety" shared pattern verified at multiple call sites.
- **`whereJsonContains` SQLite caveat** (RESEARCH Pitfall — Pattern 5): works with JSON1 extension (default in modern SQLite). Wave 0 verifies against the live driver; if unexpected behaviour, fall back to `whereRaw("json_extract(evidence, '$.signature_hash') = ?", [$signatureHash])`.
- **No `Dispatcher` injection unless events are needed** — Phase 5 does NOT fire a `ChainLinkConfirmed` event (deferred to a future phase when a consumer appears). The `AssignCategory` event-firing pattern is structurally similar; just omit the Dispatcher.
- **Clock injection** (project DI-only invariant) — never `now()` / `Carbon::now()`. Verified at `RecordTransactions::__construct` line 47 + `PairTransferCandidates::__construct` line 64.

**For `RejectChainLink`** — strictly simpler (D-89 per-pair scope):
```php
final class RejectChainLink
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $link->state = 'rejected';
        $link->save();
        // D-89: signature counter stays NEUTRAL — no recount, no demotion.
    }
}
```

---

### `Modules/Chains/Internal/CardStatementStateMachine.php` (state mutator)

**Analog:** `Modules/Ledger/Public/Actions/RecordTransactions.php` (read whole file — 96 lines; `DatabaseManager::connection()->transaction(...)` wrapper + Clock injection + DI constructor) + RESEARCH.md Pattern 2 (canonical algorithm)

**Imports pattern** (verbatim from RecordTransactions lines 1-17 — strip event Dispatcher):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\StatementSettlement;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;
```

**Class skeleton (full code from RESEARCH.md Pattern 2 lines 506-602):**
```php
/**
 * The single legal mutator of card_statements.state and
 * card_statements.open_balance_minor (D-95 invariant).
 *
 * Wraps state transitions in a transaction with explicit PRAGMA
 * busy_timeout, so two concurrent chain_link inserts cannot race the
 * state transition. SQLite does not support SELECT ... FOR UPDATE
 * (RESEARCH Pitfall 2); BEGIN IMMEDIATE + busy_timeout is the standing
 * project workaround.
 *
 * BoundaryArchTest invariant: a grep-based check (mirroring Phase 4's
 * noPaypalApiRoute) asserts no Chains-module file calls
 * card_statements.state= or table('card_statements')->update(['state'
 * outside this class.
 */
final class CardStatementStateMachine
{
    private const SETTLED_TOLERANCE_MINOR = 1; // ±€0.01

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function applySettlement(int $statementId, int $deltaMinor, User $user): StatementSettlement
    {
        $connection = $this->db->connection();
        $connection->statement('PRAGMA busy_timeout = 5000');

        return $connection->transaction(function () use ($connection, $statementId, $deltaMinor, $user): StatementSettlement {
            $row = $connection->table('card_statements')
                ->where('id', $statementId)
                ->where('user_id', $user->id)
                ->first(['id', 'open_balance_minor', 'state']);

            if ($row === null) {
                throw new RuntimeException(sprintf(
                    'card_statement %d not found for user %d',
                    $statementId, $user->id,
                ));
            }

            $prevOpen = self::toInt($row->open_balance_minor);
            $newOpen = $prevOpen - $deltaMinor;
            $newState = match (true) {
                abs($newOpen) <= self::SETTLED_TOLERANCE_MINOR => 'settled',
                $newOpen < -self::SETTLED_TOLERANCE_MINOR      => 'overpaid',
                $newOpen > 0 && $prevOpen > $newOpen           => 'partially_settled',
                default                                        => self::toString($row->state),
            };

            $connection->table('card_statements')
                ->where('id', $statementId)
                ->where('user_id', $user->id)
                ->update([
                    'open_balance_minor' => $newOpen,
                    'state'              => $newState,
                    'updated_at'         => $this->clock->now()->toDateTimeString(),
                ]);

            return new StatementSettlement(
                statementId: $statementId,
                previousOpenMinor: $prevOpen,
                newOpenMinor: $newOpen,
                newState: $newState,
            );
        });
    }

    private static function toInt(mixed $v): int { return is_numeric($v) ? (int) $v : 0; }
    private static function toString(mixed $v): string { return is_string($v) ? $v : ''; }
}
```

**Critical pattern reminders:**
- **`->transaction(closure)` wrapper** at Phase 1 `RecordTransactions` line 55 — same shape. The closure receives nothing; collaborators arrive via `use ()`.
- **`PRAGMA busy_timeout = 5000`** is the SQLite-specific concurrency primitive (RESEARCH Pattern 2 + Pitfall 2). Pair with the transaction wrapper for the BEGIN IMMEDIATE effect.
- **`toInt(mixed)` / `toString(mixed)` helpers** mirror `PairTransferCandidates::toInt` (line 198) and `ThisPeriodAtAGlanceQuery::toString` (line 199).
- **NEVER mutate `card_statements` outside this class.** BoundaryArchTest enforces.

---

### `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` (queued job)

**Analog:** **No exact codebase analog** — first queued job in the project. RESEARCH.md Pattern 3 (lines 620-693) is the canonical source. Closest structural shape is `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (event-handler class — `final class` with `handle()` method + DI parameters).

**Imports pattern** (RESEARCH Pattern 3 lines 627-639):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Core\Models\User;
```

**Class skeleton (verbatim from RESEARCH Pattern 3 — D-103 contract):**
```php
final class ResolveChainLinksJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Retry attempts before final failure (D-103). */
    public int $tries = 3;

    /** @var array<int, int> Exponential backoff in seconds (D-103). */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    /**
     * Per-user uniqueness — eliminates the parallel-resolution race
     * (research/ARCHITECTURE.md L446). ShouldBeUniqueUntilProcessing
     * releases the lock when the worker BEGINS handle(), so a crashed
     * worker auto-releases the lock for retry (RESEARCH Pitfall 1).
     */
    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600; // 10-minute ceiling
    }

    /**
     * SINGLE permitted facade use in the Phase 5 codebase.
     *
     * The Laravel queue infrastructure calls uniqueVia() before the
     * job constructor's DI completes, so injecting Cache via constructor
     * is not possible. BoundaryArchTest's "no facades" rule needs an
     * explicit allow-list entry for THIS FILE ONLY — see the BoundaryArchTest
     * modification entry below for the carve-out.
     */
    public function uniqueVia(): Repository
    {
        return Cache::driver('redis');
    }

    /**
     * @phpstan-param IcsSettlementResolver $icsResolver
     * @phpstan-param PaypalFundingResolver $paypalResolver
     */
    public function handle(
        IcsSettlementResolver $icsResolver,
        PaypalFundingResolver $paypalResolver,
    ): void {
        /** @var User $user */
        $user = User::query()->where('id', $this->userId)->firstOrFail();
        $icsResolver->resolveForUser($user);
        $paypalResolver->resolveForUser($user);
    }
}
```

**Critical pattern reminders:**
- **Single facade exception** documented in the class-level docblock + BoundaryArchTest allow-list entry. The carve-out is per-file, not module-wide.
- **`User` not in constructor** — `SerializesModels` doesn't work cleanly with the project's `final class extends Model`; pass `$userId: int` (serialises cleanly) and resolve in `handle()`.
- **`handle()` parameter injection** is the canonical Laravel queue-job pattern — Laravel resolves typed parameters via the container. Same shape as Livewire `render()` parameter injection that the project already uses.

---

### `Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php` (resolver service)

**Primary analog:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (read whole file — 203 lines; DatabaseManager raw query builder + cross-user safety guard + raw `update()` symmetric writes + `toInt()` helper)
**Secondary analog:** `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (DTO assembly pattern)

**Imports pattern** (composite from PairTransferCandidates lines 1-11 + ChainLinkQuery shape):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\FingerprintComposer;
```

**Class skeleton** (composite — PairTransferCandidates shape + RESEARCH.md Pattern 4 algorithm):
```php
/**
 * Resolves the funding chain for every PayPal expense lacking a
 * confirmed chain_link of kind='paypal_funding' (D-104 user-scoped
 * scan). Two-arm algorithm:
 *
 *   1. Deterministic arm: inspect rawPayload.events[] for funding-source
 *      IBAN hints (Funding Source / Reference Txn ID / counterparty IBAN
 *      in "Bankstorting" memo lines per D-106). When an own-account IBAN
 *      match is found, create a chain_link of confidence=1.0,
 *      state='confirmed', resolver='auto'.
 *
 *   2. Fuzzy arm: when arm 1 misses, score candidate funder rows by
 *      Levenshtein-normalised-merchant similarity + amount band (±2%) +
 *      date window (±3 days). Confidence ∈ [0.6, 0.99]; ≥0.6 surfaces as
 *      candidate, <0.6 dropped.
 *
 * Resolver writes chain_links ONLY (D-84 invariant) — never mutates
 * transactions, never re-types rows. BoundaryArchTest enforces.
 *
 * Cross-user safety: every query filters on $user->id first (FND-03).
 *
 * Raw DatabaseManager query builder per the staticMethod.dynamicCall
 * rule (PairTransferCandidates is the canonical shape).
 */
final class PaypalFundingResolver
{
    private const AMOUNT_BAND_PERCENT = 2;
    private const DATE_WINDOW_DAYS = 3;
    private const FUZZY_MIN_CONFIDENCE = 0.6;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly FingerprintComposer $fingerprints,
    ) {}

    public function resolveForUser(User $user): void
    {
        // 1. SELECT all PayPal transactions of type='expense' or 'transfer_out'
        //    lacking a confirmed chain_link of kind='paypal_funding'.
        //    The query mirrors PairTransferCandidates' partner-query shape
        //    (raw query builder + whereBetween + whereIn + filter on user_id).
        // 2. For each row, run arm 1 (deterministic). If hit → INSERT chain_link
        //    (confidence=1.0, state='confirmed', resolver='auto').
        // 3. Else run arm 2 (fuzzy). If best score ≥0.6 → INSERT chain_link
        //    (confidence=score, state='candidate', resolver='auto').
        // 4. Compute auto-promotion signature_hash = sha256(normalized_merchant
        //    + funding_source_identity) and store in chain_link.evidence.
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
```

**Critical pattern reminders:**
- **`->where('user_id', $user->id)` first** in every query, every time. PairTransferCandidates lines 108, 135 verified.
- **Raw `update()` / `insert()` via `->table('chain_links')`** — never `ChainLink::create(...)` from Eloquent inside the resolver (Larastan strict-rules conflict). Read shape: `PairTransferCandidates` lines 169-184.
- **Use `FingerprintComposer::normalize()`** for `normalized_merchant` — same merchant-identity space as the dedup pipeline (RESEARCH Don't-Hand-Roll table).
- **`signature_hash` = `hash('sha256', $normalizedMerchant . '|' . $fundingIban)`** — stored in `chain_link.evidence['signature_hash']` so `ConfirmChainLink` can recount.

---

### `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` (resolver service)

**Primary analog:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (same shape as `PaypalFundingResolver` above)
**Secondary analog:** RESEARCH.md Pattern 4 (lines 696-757) for the bulk-settle decomposition algorithm

**Class skeleton** (mirror PaypalFundingResolver shape):
```php
final class IcsSettlementResolver
{
    private const AMOUNT_TOLERANCE_MINOR = 500;      // ±€5 absolute (D-97)
    private const AMOUNT_TOLERANCE_PERCENT = 2;       // ±2% (D-97)
    private const PERIOD_WINDOW_DAYS = 10;            // ±10 days (D-97)
    private const SETTLED_TOLERANCE_MINOR = 1;        // ±€0.01 for "exact" (D-95)

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CardStatementStateMachine $stateMachine,
    ) {}

    public function resolveForUser(User $user): void
    {
        // Per RESEARCH Pattern 4 algorithm:
        // 1. For each transfer_in row whose account_id is ICS-kind and lacks
        //    a confirmed chain_link of kind='ics_bulk_settle':
        //   a. Find candidate card_statement (state IN open|partially_settled,
        //      period_end ± 10 days, amount within ±€5 OR ±2%).
        //   b. Pull all ICS expense rows in the statement period lacking a
        //      confirmed ics_bulk_settle chain_link.
        //   c. Apply prior credits via card_statement_credits.
        //   d. Compute delta.
        //   e. If within tolerance: INSERT N chain_links (state='confirmed',
        //      confidence=1.0, resolver='auto'). Call stateMachine->applySettlement.
        //      If state lands 'overpaid', INSERT card_statement_credits row.
        //      If refund-after-close (D-98), chain back to original AND emit
        //      a credit row.
        //   f. Else: INSERT single chain_link (state='candidate', confidence
        //      derived from delta-magnitude).
    }
}
```

**Critical pattern reminders:**
- **`CardStatementStateMachine` is the ONLY state mutator** — never `$db->table('card_statements')->update(['state' => ...])` from the resolver. Routes every state change through `applySettlement()`. BoundaryArchTest enforces.
- **Chain_link.evidence shape** (D-97 / D-98 — planner locks during plan-phase, must include at minimum):
  ```json
  {
    "statement_id": 123,
    "unaccounted_delta_minor": 153,
    "tolerance_used": "amount_5eur",
    "covered_count": 23,
    "credits_applied_minor": 0,
    "signature_hash": "<sha256 of degenerate signature — D-88>"
  }
  ```

---

### `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` (Livewire SFC, `/chains/review`)

**Primary analog:** `Modules/Categorization/Internal/Http/Livewire/TriageInbox.php` (read whole file — 103 lines; canonical paginated-list SFC with per-row actions, cursor pagination, parameter-injection-on-render pattern)

**Imports pattern** (verbatim from TriageInbox lines 1-14):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;
```

**Class skeleton** (mirror TriageInbox lines 38-101):
```php
/**
 * `/chains/review` Livewire SFC. Renders the user's open candidate
 * chain_links sorted by confidence DESC, then posted_at DESC (D-86 +
 * UI-SPEC § Review queue page).
 *
 * Each row has Confirm / Reject buttons that dispatch wire:click into
 * the corresponding Public action. Optimistic flip is rendered via
 * Livewire's component-internal state — no toast on success (the row
 * fades out per UI-SPEC); only the server-error case dispatches the
 * inline 12px rose-600 flash via $this->dispatch('toast', ...).
 *
 * Every collaborator arrives as a parameter on render() / action
 * methods — no boot() injection (strict-rules ruleset bans property-
 * based constructor injection on Livewire components — verified at
 * TriageInbox line 33-36 comment block).
 */
final class ChainReviewQueue extends Component
{
    public ?int $cursorId = null;
    public ?string $cursorConfidence = null;

    public function confirm(
        int $chainLinkId,
        CurrentUser $currentUser,
        ConfirmChainLink $confirm,
    ): void {
        $confirm($chainLinkId, $currentUser->user());
    }

    public function reject(
        int $chainLinkId,
        CurrentUser $currentUser,
        RejectChainLink $reject,
    ): void {
        $reject($chainLinkId, $currentUser->user());
    }

    public function loadMore(int $nextCursorId, ?string $nextCursorConfidence = null): void
    {
        $this->cursorId = $nextCursorId;
        $this->cursorConfidence = $nextCursorConfidence;
    }

    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        $candidates = $db->connection()
            ->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', 'candidate')
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->limit(26)
            ->get();
        // ... cursor pagination shape from TriageInbox::render lines 80-101 ...

        return $views->make('chains::livewire.chain-review-queue', [
            'candidates' => $candidates,
            'openCandidateCount' => $query->openCandidateCount($user),
        ]);
    }
}
```

---

### `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` (Livewire SFC, flyout)

**Primary analog:** `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` (172 lines — mounted Livewire detail SFC + cross-user 404 + `$this->dispatch('toast', ...)`)

**Imports pattern** (verbatim from TransactionDetail lines 1-15):
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;
```

**Class skeleton:**
```php
/**
 * The chain drill-down side-drawer Livewire SFC (D-90 / D-92 / D-93,
 * UI-02). Mounts on /transactions/{id} and surfaces when the
 * "View chain" button dispatches `chain-drawer:open`.
 *
 * Project's first Flux flyout — see chain-drawer.blade.php for the
 * <flux:modal flyout> markup. The Livewire component owns the chain
 * tree state and the fan-out pagination cursor; Flux owns the open/
 * close/escape/click-outside behaviour.
 */
final class ChainDrawer extends Component
{
    public ?int $transactionId = null;
    public int $fanoutPage = 0;
    public ?int $expandedFanoutId = null;

    /** @var array<int> Per-leg collapse state for D-92 click-to-collapse. */
    public array $collapsedLegs = [];

    #[On('chain-drawer:open')]
    public function open(int $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->fanoutPage = 0;
        $this->expandedFanoutId = null;
        $this->collapsedLegs = [];
    }

    public function confirm(
        int $chainLinkId,
        CurrentUser $currentUser,
        ConfirmChainLink $confirm,
    ): void {
        $confirm($chainLinkId, $currentUser->user());
    }

    public function reject(
        int $chainLinkId,
        CurrentUser $currentUser,
        RejectChainLink $reject,
    ): void {
        $reject($chainLinkId, $currentUser->user());
    }

    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        ViewFactory $views,
    ): View {
        if ($this->transactionId === null) {
            return $views->make('chains::livewire.chain-drawer', [
                'tree' => null,
            ]);
        }

        $tree = $query->forTransaction($this->transactionId, $currentUser->user());

        return $views->make('chains::livewire.chain-drawer', [
            'tree' => $tree,
        ]);
    }
}
```

**Pattern note:** `#[On('chain-drawer:open')]` is the Livewire 4 attribute-based event listener (verified — Livewire 4 supports `Livewire\Attributes\On`). Phase 5 is the first project use; planner verifies attribute reachability against the framework version in Wave 0.

---

### `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` (Blade — Flux flyout)

**Analog:** **No exact codebase analog** — first project use of `<flux:modal flyout>`. Closest reference is `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` lines 22-25 (existing `<flux:radio.group>` invocation pattern within the same Flux UI library). UI-SPEC § Component Inventory specifies the exact attributes.

**Skeleton (from RESEARCH Pattern 6 + UI-SPEC):**
```blade
<flux:modal name="chain-drawer-{{ $tree?->rootTransactionId ?? 0 }}" flyout position="right" class="md:w-2xl">
    <flux:heading size="lg" class="sticky top-0 bg-white z-10 pb-3 -mx-6 px-6">
        @if ($tree)
            Chain for {{ $tree->nodes[0]->counterpartyName ?? 'transaction' }}
        @else
            Chain
        @endif
    </flux:heading>

    @if ($tree === null)
        {{-- Loading / pre-mount state --}}
    @elseif (empty($tree->nodes))
        {{-- Empty state (D-90 / UI-SPEC Copywriting Contract) --}}
        <p class="text-sm text-slate-500">
            This transaction has no detected funding chain. If you expected one,
            file a candidate from the review queue.
        </p>
    @else
        <div class="space-y-md">
            @foreach ($tree->nodes as $node)
                @include('chains::livewire.partials.chain-node', ['node' => $node])
            @endforeach
        </div>
    @endif
</flux:modal>
```

**UI-SPEC obligations to honour (locked in UI-SPEC):**
- `flyout position="right" class="md:w-2xl"` — width locked at 672px desktop.
- Sticky header via `sticky top-0 bg-white z-10` (UI-SPEC § Interaction Contracts — Chain drill-down drawer).
- No outer scroll on the drawer body (D-93). Fan-out containers carry `overflow-y-auto max-h-96` instead.
- No `footer` slot — Confirm/Reject chips live inline on candidate legs.

---

### `Modules/Chains/Routes/web.php` (route file)

**Analog:** `Modules/Categorization/Routes/web.php` (10 lines) + `Modules/Ledger/Routes/web.php` (14 lines)

**Verbatim shape:**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/chains/review', ChainReviewQueue::class)
        ->name('chains.review');
});
```

**Pattern note:** `Route::facade` is allowed in route files (verified across Modules/Ledger/Routes/web.php, Modules/Categorization/Routes/web.php — every existing module uses the facade here). BoundaryArchTest's "no Laravel facade usage in module code" rule explicitly allows `Modules\*\Routes` because Laravel routing files are the standing exception.

---

### `Modules/Chains/tests/Pest.php` / `TestCase.php` (test bootstrap)

**Analog:** `Modules/Transfers/tests/Pest.php` (13 lines) + `Modules/Transfers/tests/TestCase.php` (15 lines)

**Verbatim copy with namespace swap** — Phase 4's Transfers test bootstrap is structurally identical:

**Pest.php:**
```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts');

pest()->extend(TestCase::class)->in('Unit');
```

**TestCase.php:**
```php
<?php

declare(strict_types=1);

namespace Modules\Chains\Tests;

use Tests\TestCase as RootTestCase;

abstract class TestCase extends RootTestCase {}
```

---

### `Modules/Chains/tests/Unit/Resolvers/IcsSettlementResolverTest.php` / `PaypalFundingResolverTest.php` (unit tests with Pest datasets)

**Primary analog:** `Modules/Categorization/tests/Feature/AssignCategoryTest.php` (read lines 16-78 — canonical `beforeEach` per-user fixture setup with User + Account + ImportRun + Category)
**Secondary analog:** `Modules/Transfers/tests/Feature/PairTransferCandidatesTest.php` (lines 36-100 — partner-pairing fixture setup that the Phase 5 resolver tests structurally mirror)

**Imports pattern** (verbatim from PairTransferCandidatesTest lines 1-13):
```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
```

**`beforeEach` skeleton** (mirror PairTransferCandidatesTest lines 36-73):
```php
beforeEach(function (): void {
    $this->user = User::query()->create([
        'email' => 'chain-fixture@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asnAccount = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'chain-asn', 'kind' => 'asn',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);

    $this->icsAccount = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ICS card', 'slug' => 'chain-ics', 'kind' => 'ics_card',
        'iban' => 'ICS-CARD', 'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/chain-fixture.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->resolver = $this->app->make(IcsSettlementResolver::class);
});
```

**Pest dataset for tolerance variants (D-107 — clean / overpaid / underpaid):**
```php
dataset('bulk_settle_variants', [
    'clean'      => ['delta_minor' => 0,    'expected_state' => 'settled',           'expected_chain_state' => 'confirmed'],
    'overpaid'   => ['delta_minor' => 153,  'expected_state' => 'overpaid',          'expected_chain_state' => 'confirmed'],
    'underpaid'  => ['delta_minor' => -218, 'expected_state' => 'partially_settled', 'expected_chain_state' => 'confirmed'],
    'exceed_tol' => ['delta_minor' => 5000, 'expected_state' => 'open',              'expected_chain_state' => 'candidate'],
]);

it('decomposes the bulk-iDEAL settlement within tolerance', function (int $deltaMinor, string $expectedState, string $expectedChainState): void {
    // ... fixture setup uses scenario-1 with overlay JSON to adjust delta ...
})->with('bulk_settle_variants');
```

---

### `Modules/Chains/tests/Contracts/ChainResolutionIdempotencyTest.php` (contract test)

**Analog:** `tests/Contracts/IdempotencyContractTest.php` (read whole file — 88 lines; canonical re-import-produces-zero-rows contract test shape)

**Test shape (re-run resolver twice → no duplicate chain_links):**
```php
it('re-running the resolver produces zero additional chain_links', function (): void {
    // 1. Set up scenario-1 fixture (synthesised cross-source).
    // 2. Run resolver: first pass writes chain_links.
    // 3. Run resolver: second pass writes ZERO additional chain_links.
    //    (idempotency via existence check in the resolver — verify no
    //     UNIQUE constraint needed because the resolver SHOULD short-
    //     circuit via "lacks a confirmed chain_link" filter from D-104.)
});

it('re-running after a chain_link is rejected does NOT re-propose the same pair', function (): void {
    // ... rejected rows stay rejected; resolver does not re-create them.
});
```

---

### `Modules/Chains/tests/fixtures/scenario-1/scenario-1.md` (fixture-record doc)

**Analog:** `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` (read for the "Source / Date / Redaction protocol / Empirical findings" doc shape)

**Doc shape:**
```markdown
# Phase 5 scenario-1 — synthesised cross-source fixture

`scenario-1/` is a synthesised cross-source matching fixture trio.
**NOT anonymised from real user data** — generated by
`scripts/synthesise_phase5_scenario.php` so the bulk-iDEAL totals across
ASN CAMT.053 ↔ ICS PDF ↔ PayPal CSV match deterministically.

## Variants
- `asn-camt053.xml` — ASN CAMT.053 with bulk-iDEAL settlement to ICS-CARD
- `ics-statement.pdf` — ICS PDF statement covering N transactions
- `paypal-activity.csv` — PayPal CSV including D-106 "General Withdrawal NL"
- `scenario-1-overpaid.json` — overlay: bulk-iDEAL amount = total + €1.53
- `scenario-1-underpaid.json` — overlay: bulk-iDEAL amount = total − €2.18

## Empirical contract
- ICS period: 2026-04-15 → 2026-05-14
- ICS transaction count: 23 (mix of EUR + USD purchases)
- ICS statement total: €847.32
- ASN bulk-iDEAL date: 2026-05-19 (period_end + 5 days)
- PayPal Reference Txn ID chain depth: 3 (parent + fee + currency-conversion)
```

---

### `scripts/synthesise_phase5_scenario.php` (composer-dep-free CLI script)

**Analog (composer-dep-free PHP shebang shape):** `scripts/anonymize_paypal_csv.php` (read lines 1-50 for the shebang + docblock + idempotent regex-pass shape)
**Analog (PDF emit):** `scripts/generate_tiny_ics_pdf.php` (read for the bundled PDF writer pattern)

**Pattern reminders:**
- `#!/usr/bin/env php\n<?php\ndeclare(strict_types=1);` — first three lines mirror existing scripts.
- Composer-dep-free: synthesize the ICS PDF via the same approach as `generate_tiny_ics_pdf.php` (no FPDF, no TCPDF — uses raw PDF bytecode emit). Synthesise the CAMT.053 via a string-template approach. Synthesise the PayPal CSV via `fputcsv()`.
- Idempotent: re-running produces identical output via seeded `mt_srand()` for any random values.
- D-107 variants: emit `scenario-1-overpaid.json` / `scenario-1-underpaid.json` overlay manifests that the resolver tests use to adjust the bulk-iDEAL amount per Pest dataset row.

---

### `Modules/Transfers/Public/Services/PairLookup.php` (D-110 promotion — NEW)

**Primary analog:** `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (DI + DatabaseManager + raw query builder shape)
**Secondary analog:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` (existing partner-query patterns line 133-147 — the shape this Public service exposes for cross-module read access)

**Imports pattern + class skeleton:**
```php
<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Public read API over `transactions.pair_transaction_id`. Promoted from
 * Phase 4 D-80 (deferred Public surface) to Phase 5 D-110 — minimal,
 * single-purpose: the chain resolver is the first cross-module consumer.
 *
 * Read-only. Does NOT mutate pair_transaction_id (that's the listener's
 * job at import time).
 */
final class PairLookup
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function isPaired(int $txId, User $user): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->whereNotNull('pair_transaction_id')
            ->exists();
    }

    public function partnerId(int $txId, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->first(['pair_transaction_id']);

        if ($row === null || $row->pair_transaction_id === null) {
            return null;
        }

        return self::toInt($row->pair_transaction_id);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
```

**TransfersServiceProvider binding** (extend Modules/Transfers/Providers/TransfersServiceProvider.php with one new line in `register()`):
```php
public function register(): void
{
    $this->app->singleton(PairLookup::class);
}
```

---

### `Modules/Import/Public/Actions/ConfirmImport.php` (MODIFIED — dispatch ResolveChainLinksJob post-commit)

**Analog:** self — read lines 1-142 above.

**Two implementation options** (planner picks):

**Option A: Dispatch via injected `Illuminate\Contracts\Bus\Dispatcher`** (CANONICAL):
- Add `private readonly Dispatcher $bus` to constructor.
- After the `$this->db->connection()->transaction(...)` block (line 135 — after `$result` returns), call `$this->bus->dispatch(new ResolveChainLinksJob($user->id))`.
- Pitfall guard (RESEARCH Pitfall 3): dispatch AFTER the transaction returns, NEVER inside the closure. Redis queue does NOT share the SQLite transaction frame.

**Skeleton delta:**
```php
// Add to imports:
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;

// Add to constructor:
public function __construct(
    // ... existing deps ...
    private readonly Dispatcher $bus,
) {}

// After the transaction block returns (after line 135):
$this->cache->forget($importRunId);

// D-103: Dispatch resolver post-commit (NEVER inside transaction —
// RESEARCH Pitfall 3). The job is keyed unique-per-user via
// ShouldBeUniqueUntilProcessing, so a second confirm-while-resolving
// dispatch is silently dropped at the queue boundary.
$this->bus->dispatch(new ResolveChainLinksJob($user->id));

return $result;
```

**Pattern guard:** Never `Bus::dispatch(...)` facade. Never `dispatch(...)` global helper. Use the `Illuminate\Contracts\Bus\Dispatcher` interface verbatim.

---

### `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` (MODIFIED — add `nextIcsSettlement()`)

**Analog:** self — read whole file (204 lines above).

**Delta** — add a new method between `forByCurrency()` (line 143) and `toInt()` (line 188):
```php
/**
 * The "Next ICS settlement" dashboard tile (D-99 / D-100 / CHN-06).
 * Returns null when no open card_statement exists; the dashboard hides
 * the tile entirely rather than rendering a "—" placeholder.
 *
 * Forecast amount = open_balance_minor of the most-recent
 * card_statement whose state ∈ ('open', 'partially_settled') for the
 * user's ICS account. Forecast due-date = period_end + 5 calendar
 * days (constant per D-100; Phase 8 refines via recurring-cadence
 * inference).
 *
 * Cross-user safety: every query filters on $user->id first.
 */
public function nextIcsSettlement(User $user): ?CardStatementForecastTile
{
    $row = $this->db->connection()
        ->table('card_statements')
        ->join('accounts', 'accounts.id', '=', 'card_statements.account_id')
        ->where('card_statements.user_id', $user->id)
        ->where('accounts.kind', 'ics_card')
        ->whereIn('card_statements.state', ['open', 'partially_settled'])
        ->orderByDesc('card_statements.period_end')
        ->select(
            'card_statements.id',
            'card_statements.open_balance_minor',
            'card_statements.period_end',
            'card_statements.state',
        )
        ->first();

    if ($row === null) {
        return null;
    }

    $periodEnd = \Carbon\CarbonImmutable::parse(self::toString($row->period_end));

    return new CardStatementForecastTile(
        amount: Money::ofMinor(self::toInt($row->open_balance_minor), 'EUR'),
        dueDate: $periodEnd->addDays(5)->startOfDay(),
        statementId: self::toInt($row->id),
        state: self::toString($row->state),
    );
}
```

**Imports addition:**
```php
use Modules\Chains\Public\Dto\CardStatementForecastTile;
```

**Pattern reminder:** This extension follows the existing `for() / forByCurrency()` sibling-method shape on the same class. The new method mirrors the same DI-only + raw-query-builder + integer-coerce-helper idioms used by the existing methods. Cross-module DTO import (`Modules\Chains\Public\Dto\CardStatementForecastTile`) is permitted because `Chains/Public/` is explicitly cross-module-consumable.

---

### `tests/Contracts/BoundaryArchTest.php` (MODIFIED — three new invariants)

**Analog:** self — read whole file (98 lines above; pest-arch + grep-based hybrid file).

**Three new entries to add** (mirror the existing `noPaypalApiRoute` grep-based shape from lines 56-97):

**1. `Modules\Chains\Internal` is only used inside `Modules\Chains` (pest-arch):**
```php
arch('Modules\\Chains\\Internal is only used inside Modules\\Chains')
    ->expect('Modules\\Chains\\Internal')
    ->toOnlyBeUsedIn('Modules\\Chains');
```

**2. D-84 invariant — no resolver mutates transactions (grep-based):**
```php
it('no Modules/Chains/Internal/Resolvers/ file writes to transactions table (noResolverWritesTransactions)', function (): void {
    $hits = [];
    $resolversDir = base_path('Modules/Chains/Internal/Resolvers');
    if (! is_dir($resolversDir)) {
        return; // tolerate pre-Phase-5 state
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolversDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! preg_match('/\.php$/', $file->getPathname())) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        // Catch every shape of "this file writes to transactions":
        //   Transaction::query()->...->update(
        //   Transaction::where(
        //   ->table('transactions')->...->update(
        //   ->table('transactions')->...->insert(
        if (preg_match("/Transaction::query|Transaction::where|->table\(['\"]transactions['\"]\)\s*->[^;]*->(update|insert|delete)/", $stripped) === 1) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([], "Resolver files must not mutate the transactions table. Offenders:\n  ".implode("\n  ", $hits));
});
```

**3. D-95 invariant — only CardStatementStateMachine mutates card_statements.state (grep-based):**
```php
it('only CardStatementStateMachine writes card_statements.state (noOtherCardStatementStateMutator)', function (): void {
    $hits = [];
    $chainsDir = base_path('Modules/Chains');
    if (! is_dir($chainsDir)) {
        return;
    }
    $allowedFile = base_path('Modules/Chains/Internal/CardStatementStateMachine.php');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chainsDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! preg_match('/\.php$/', $file->getPathname())) {
            continue;
        }
        if ($file->getPathname() === $allowedFile) {
            continue; // the legal mutator
        }
        $contents = (string) file_get_contents($file->getPathname());
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match("/->table\(['\"]card_statements['\"]\)\s*->[^;]*->update\(\s*\[\s*['\"]state['\"]/", $stripped) === 1
            || preg_match('/CardStatement::query\(\)[^;]*->update\(\s*\[\s*[\'"]state[\'"]/', $stripped) === 1) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([], "Only CardStatementStateMachine may mutate card_statements.state. Offenders:\n  ".implode("\n  ", $hits));
});
```

**4. Cache facade carve-out for `ResolveChainLinksJob::uniqueVia()`** (already covered by the `'no Laravel facade usage in module code'` rule — need to ADD an explicit allow-list):
```php
// MODIFY the existing rule at line 32-34 to accept the single permitted carve-out:
arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules')
    ->ignoring([
        // Single permitted facade use: ResolveChainLinksJob::uniqueVia()
        // must return a Repository before the constructor's DI completes.
        // Laravel's queue infrastructure calls uniqueVia() at queue-push
        // time when no container resolution is possible. Documented on
        // the class. D-101 / RESEARCH Pattern 3.
        'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
    ]);
```

**Pattern note:** The `->ignoring(['ClassFqn'])` modifier is the pest-arch allow-list mechanism. Verify against pest-plugin-arch ^4.0 docs during Wave 0; if not supported, fall back to a separate `arch()` rule + a per-file grep skip.

---

### `bootstrap/providers.php` (MODIFIED — register ChainsServiceProvider)

**Analog:** self — 19 lines above.

**Single delta** (add import + provider entry, alphabetised before LedgerServiceProvider per project convention):
```php
use Modules\Chains\Providers\ChainsServiceProvider;   // <-- NEW import
// ...
return [
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
    IngestionServiceProvider::class,
    ImportServiceProvider::class,
    CategorizationServiceProvider::class,
    TransfersServiceProvider::class,
    ChainsServiceProvider::class,   // <-- NEW (registered last; consumers up-stack)
];
```

**Pattern note:** Phase 4's Transfers registration sat at the end of the list. Phase 5's Chains sits after Transfers because it consumes `Modules\Transfers\Public\Services\PairLookup`. Order matters when service-provider `register()` calls bind in dependency order; here the bindings are independent so the position is by convention only.

---

### `composer.json` (root — MODIFIED)

**Analog:** self — header read above.

**Three deltas:**

1. Add to `"require"`:
```json
"laravel/horizon": "^5.46",
"predis/predis": "^3.4",
```

2. Add to `"autoload-dev"."psr-4"`:
```json
"Modules\\Chains\\Tests\\": "Modules/Chains/tests/"
```

3. No change to `"extra"` — the project doesn't use Wikimedia's composer-merge-plugin in a way that auto-discovers module composer.json files (verified — each module's psr-4 is added manually to root composer.json). Phase 5 follows the same pattern.

**Pattern note:** Phase 4 Transfers entry already lives in `autoload-dev` (line 46 of root composer.json). Phase 5 follows the same shape.

---

### `config/horizon.php` (NEW — published by `php artisan horizon:install`)

**Analog:** `config/database.php` + `config/auth.php` (existing Laravel-shape config files in the project).

**Pattern note:** This file is generated by `php artisan horizon:install`, not hand-written. The Wave 0 deliverable is to RUN the install command + commit the resulting `config/horizon.php` + `app/Providers/HorizonServiceProvider.php` artefacts. Locked configuration tweaks per UI-SPEC § Registry Safety and D-101 discretion:
- Single-supervisor (`supervisor-1`) with `processes=1, balance='simple', tries=3, timeout=900`.
- Horizon dashboard route protected by `LoopbackOnly` middleware (Phase 1's existing middleware) + Fortify auth. `/horizon` lives in the same auth boundary as `/dashboard`.

---

### `app/Providers/HorizonServiceProvider.php` (NEW — published by `php artisan horizon:install`)

**Analog:** `Modules/Core/Internal/Providers/FortifyServiceProvider.php` (existing config-publish ServiceProvider with an auth-gate callback).

**Locked auth gate** (UI-SPEC § Registry Safety — D-101 discretion):
```php
protected function gate(): void
{
    \Laravel\Horizon\Horizon::auth(function ($request) {
        // Fortify session auth + LoopbackOnly middleware already cover
        // this — return true for any authenticated user. A finer-grained
        // admin gate is deferred to v2 (CONTEXT.md Deferred).
        return $request->user() !== null;
    });
}
```

---

### `.planning/PROJECT.md` (MODIFIED — atomic amendment)

**Analog:** self — Phase 4's atomic REQUIREMENTS.md ING-09 + ROADMAP SC #2 edit was the analog posture; Phase 5's PROJECT.md amendment mirrors that surface.

**Deltas to apply** (per D-101 / D-102):
1. Move "Laravel Horizon for this project" from `## What NOT to Use` to recommended stack with a note.
2. Move "Laravel Sail / Docker on macOS" amendment: keep the anti-Docker stance for app-wide bind-mount usage but carve out "Network-only services (Redis) are explicit exceptions".
3. Flip queue driver guidance: was `database`, becomes `redis`.
4. Add `predis/predis ^3.x` as a recommended package (and `phpredis` PECL as the alternative).
5. Add Docker daemon as a dev-prerequisite under `## Installation`.

**Pattern reminder:** Atomic doc amendments live in the same commit as Plan 05-01 (Wave 0) per Phase 4's posture.

---

### `README.md` (MODIFIED)

**Analog:** self.

**Deltas:**
1. Add a "Redis (Docker)" section under setup:
```bash
docker volume create diederik-redis-data
docker run --name diederik-redis -p 127.0.0.1:6379:6379 -v diederik-redis-data:/data -d redis:7-alpine redis-server --save 60 1
```
2. Add `php artisan horizon` second-terminal note under "Running locally".
3. Document `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`, `REDIS_CLIENT=predis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` env additions.

---

## Shared Patterns

### Authentication / Cross-User Safety

**Source:** `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` lines 74-78 (defensive `$event->transaction->user_id === $event->user->id` assertion) + `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` lines 80-86 (cross-user 404 via raw exists check) + `Modules/Categorization/Public/Actions/AssignCategory.php` (User parameter through `__invoke`).
**Apply to:** Every new Chains module Public service, Action, Resolver, Livewire SFC, State Machine, Job.

```php
// CurrentUser is the canonical DI source — never Auth::user(), never auth().
public function someMethod(CurrentUser $currentUser): void
{
    $user = $currentUser->user();

    // EVERY query SCOPED to user_id explicitly.
    SomeModel::query()
        ->where('user_id', $user->id)
        ->where('id', $someId)
        ->firstOrFail();   // raises NotFoundHttpException → 404
}
```

### Dispatcher Injection (NO facades)

**Source:** `Modules/Ledger/Public/Actions/RecordTransactions.php` line 47 + `Modules/Categorization/Public/Actions/AssignCategory.php` line 24 + `Modules/Import/Public/Actions/ConfirmImport.php` (Phase 5 modifier — adds `Illuminate\Contracts\Bus\Dispatcher`).
**Apply to:** `ConfirmImport` (`Bus\Dispatcher` for `ResolveChainLinksJob`), all future event emitters.

```php
use Illuminate\Contracts\Events\Dispatcher;   // for events
use Illuminate\Contracts\Bus\Dispatcher;       // for queue jobs

public function __construct(
    // ...
    private readonly \Illuminate\Contracts\Bus\Dispatcher $bus,
) {}

$this->bus->dispatch(new ResolveChainLinksJob($user->id));
```

NEVER `event(new ...)`. NEVER `Event::dispatch(...)`. NEVER `dispatch(new ...)` global helper. NEVER `Bus::dispatch(...)` facade. Single permitted exception: `ResolveChainLinksJob::uniqueVia()` (documented carve-out).

### DatabaseManager Injection (NO DB facade)

**Source:** Every `Public/Services/*Query.php` + every `Internal/Listeners/*.php` in the project. `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` line 63 + `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` line 48.
**Apply to:** Every Chains module service, resolver, state machine, query, action.

```php
use Illuminate\Database\DatabaseManager;

public function __construct(private readonly DatabaseManager $db) {}

// Constructor-inject for services/actions/listeners/state-machines.
// Method-inject on Livewire render() / action methods (no constructor).
```

NEVER `DB::table(...)`. NEVER `DB::transaction(...)`.

### Clock Injection

**Source:** `Modules/Core/Public/Contracts/Clock.php` (16-line interface) + `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` line 64 + `Modules/Ledger/Public/Actions/RecordTransactions.php` line 46.
**Apply to:** `CardStatementStateMachine`, `ConfirmChainLink`, `PaypalFundingResolver`, `IcsSettlementResolver` — every Chains module file that needs a `now()`-shaped value.

```php
use Modules\Core\Public\Contracts\Clock;

public function __construct(
    // ...
    private readonly Clock $clock,
) {}

$this->clock->now()->toDateTimeString()
```

NEVER `now()` / `Carbon::now()` / `CarbonImmutable::now()`.

### BelongsToUser trait (every domain model)

**Source:** `Modules/Core/Public/Concerns/BelongsToUser.php` (existing trait) + every model uses it (`Transaction.php` line 51, `StatementSummary.php` line 40, etc.).
**Apply to:** `ChainLink`, `CardStatement`, `CardStatementCredit`.

```php
use Modules\Core\Public\Concerns\BelongsToUser;

final class ChainLink extends Model
{
    use BelongsToUser;
    // ...
}
```

### Final readonly DTOs via `Spatie\LaravelData\Data`

**Source:** Every DTO in `Modules/Ledger/Public/Dto/*` (`StatementSummaryData`, `DashboardSummary`, `PerCurrencyTile`).
**Apply to:** Every Chains module DTO (`ChainTree`, `ChainTreeNode`, `CardStatementForecastTile`, `ChainLinkRow`, `StatementSettlement`).

```php
use Spatie\LaravelData\Data;

final class CardStatementForecastTile extends Data
{
    public function __construct(
        public readonly Money $amount,
        // ... all readonly ...
    ) {}
}
```

### Migration shape (anonymous-class + memoised DI-only exception)

**Source:** `Modules/Ledger/Database/Migrations/2026_05_15_010002_add_pair_transaction_id_to_transactions.php` lines 31-97 (canonical Phase 4 shape with memoised `$resolvedDb`).
**Apply to:** Every Phase 5 schema + data migration.

```php
private ?DatabaseManager $resolvedDb = null;

private function db(): DatabaseManager
{
    if ($this->resolvedDb === null) {
        /** @var DatabaseManager $db */
        $db = \Illuminate\Container\Container::getInstance()->make(DatabaseManager::class);
        $this->resolvedDb = $db;
    }
    return $this->resolvedDb;
}

private function schema(): Builder
{
    return $this->db()->connection($this->getConnection())->getSchemaBuilder();
}
```

The `Container::getInstance()->make(...)` call is the standing migration-layer exception to the DI-only rule. Every other Chains module file uses constructor DI.

### Enum-via-trigger CHECK constraint

**Source:** `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` lines 80-98 (canonical `transactions.type` BEFORE-INSERT/UPDATE trigger pair).
**Apply to:** `chain_links.kind`, `chain_links.state`, `card_statements.state`, `card_statement_credits.reason`.

Pattern: TWO triggers per enum (one BEFORE INSERT, one BEFORE UPDATE OF column), `RAISE(ABORT, 'Invalid <column> value')` body. **Never** use table-level CHECK (SQLite supports it but the project consistently uses triggers — locked for consistency).

### Larastan level 10 strict gates

**Source:** `phpstan.neon` (level 10 + canvural/larastan-strict-rules) — verified via existing patterns at `PairTransferCandidates::toInt`, `ThisPeriodAtAGlanceQuery::toInt` / `toString`.
**Apply to:** Every Chains module file.

- **`staticMethod.dynamicCall` rule:** prefer raw `$db->connection()->table(...)` query builder for boolean / count / whereBetween / whereIn / orderBy / get / first queries. Use Eloquent `Model::query()->where('user_id', $user->id)->where('id', $id)->firstOrFail()` for single-row reads where the result is used as a model.
- **`is_numeric() ? (int) $value : 0` helpers** as `toInt(mixed)` static private methods — canonical pattern at `PairTransferCandidates::toInt`.
- **`is_string($value) ? $value : ''`** helpers as `toString(mixed)` — canonical pattern at `ThisPeriodAtAGlanceQuery::toString`.
- **PHPDoc array shapes** declared everywhere (`array<int, string>`, `list<ChainTreeNode>`, etc.) — verified at `SourceTransactionDto`, `DashboardSummary`.

### Pest test layout

**Source:** `Modules/Transfers/tests/{Pest,TestCase}.php` + `Modules/Categorization/tests/Feature/AssignCategoryTest.php` (canonical per-user fixture setup).
**Apply to:** All Chains module tests.

- `Modules/Chains/tests/Pest.php` — verbatim copy of Transfers' shape.
- `Modules/Chains/tests/TestCase.php` — verbatim copy of Transfers' shape (namespace differs).
- `beforeEach` fixture setup with User + Account + ImportRun (mirror PairTransferCandidatesTest lines 36-73).
- Pest dataset for variant-driven tests (clean / overpaid / underpaid per D-107).
- Contract tests under `tests/Contracts/` (idempotency, no-mutate-transactions).

### Toast / dispatch pattern

**Source:** `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` line 151 (`$this->dispatch('toast', message: $message)`).
**Apply to:** `ChainReviewQueue`, `ChainDrawer` — error / success toasts on Confirm/Reject failure paths.

Pattern: Livewire `$this->dispatch('toast', message: '...')` + Alpine `x-on:toast.window` handler in `app.blade.php` layout. The failed-job toast is a `wire:poll` variant; Phase 5 plan-phase locks the polled query method.

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | queued job | event-driven (async) | First queued job in the project. No existing `implements ShouldQueue, ShouldBeUniqueUntilProcessing` class. The pattern is sourced verbatim from RESEARCH.md Pattern 3 + Laravel 12 official docs. The `uniqueVia()` facade exception requires a BoundaryArchTest carve-out documented above. |
| `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` | Blade view (Flux flyout) | view | First project use of `<flux:modal flyout>`. Closest invocation is `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` lines 22-25 (existing `<flux:radio.group>` in the same Flux UI library, but it's a different component). Skeleton sourced from RESEARCH.md Pattern 6 + UI-SPEC § Component Inventory verbatim. |

For both: the planner writes the file from RESEARCH.md guidance + UI-SPEC contract; there is no codebase pattern to copy verbatim. Both are explicit "first project use" Wave 0 deliverables and warrant snapshot baselines + smoke-test verification.

## Operational Workarounds Phase 5 Carries Forward

**`whereJsonContains` against SQLite (RESEARCH Pattern 5 + Pitfall):**
- Works with JSON1 extension (default in modern SQLite).
- Wave 0 verifies against the live driver; if unexpected behaviour, fall back to `whereRaw("json_extract(evidence, '$.signature_hash') = ?", [$signatureHash])`.
- Affects `ConfirmChainLink::__invoke()` auto-promotion query.

**Horizon `uniqueVia()` facade exception:**
- Single permitted facade use in the Chains module (`Cache::driver('redis')`).
- BoundaryArchTest's "no Laravel facade usage" rule gains an explicit allow-list entry.
- Document on the class.

**Phase 4 hand-off — D-106 PayPal NL "General Withdrawal":**
- The synthesised Wave 0 fixture (scenario-1) MUST include a PayPal NL "General Withdrawal" row whose deterministic IBAN match is exercised by the resolver. This closes Phase 4's deferred verification item in code without requiring a Phase 4 revisit.

**Cross-currency PayPal sweeps (Phase 4 carry-forward):**
- PairTransferCandidates' Layer-1 same-currency filter doesn't pair USD-funded PayPal→ASN sweeps.
- Phase 5's `PaypalFundingResolver` deterministic arm handles those via `rawPayload.events[]` inspection (D-106 surface).
- Listener-level Phase 4 limitation stays accepted; resolver-level Phase 5 surface closes it.

## Metadata

**Analog search scope:**
- `Modules/Transfers/*` (full module — the structural reference)
- `Modules/Categorization/*` (full module — full-feature provider + tests + Livewire shape)
- `Modules/Ledger/Models/*`, `Modules/Ledger/Public/{Actions,Services,Dto,Contracts,Concerns}/*`, `Modules/Ledger/Database/Migrations/2026_05_12_010005_*` (transactions table + triggers) + `Modules/Ledger/Database/Migrations/2026_05_15_010002_*` (Phase 4 migration shape) + `Modules/Ledger/Database/Migrations/2026_05_13_010005_*` (statement_summaries shape) + `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php`
- `Modules/Core/Public/{Contracts,Concerns,Services,Events}/*` + `Modules/Core/Internal/Http/Livewire/*`
- `Modules/Import/Public/{Actions,Events}/*`
- `bootstrap/providers.php`
- `composer.json`
- `tests/Contracts/{BoundaryArchTest,IdempotencyContractTest,UserIdColumnArchTest}.php`
- `scripts/{anonymize_paypal_csv,anonymize_ics_text,generate_tiny_ics_pdf}.php`
- `Modules/Ledger/Resources/views/livewire/{transaction-detail,transactions-list}.blade.php` (Flux usage patterns)
- `Modules/Core/Resources/views/livewire/{top-nav,dashboard}.blade.php`

**Files scanned:** 42 source files + 8 test / doc files = 50 files
**Pattern extraction date:** 2026-05-16

---

## PATTERN MAPPING COMPLETE

**Phase:** 5 — Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition)
**Files classified:** 38 (30 new + 8 modified)
**Analogs found:** 36 / 38

### Coverage
- Files with exact analog: 24
- Files with role-match analog: 12
- Files with no analog: 2 (`ResolveChainLinksJob` — first queued job; `chain-drawer.blade.php` — first `<flux:modal flyout>` use)

### Key Patterns Identified
- **`Modules/Transfers/` is the closest structural reference** — composer.json shape (D-110 promotion mirror), listener pattern with `DatabaseManager` raw query builder + symmetric-write idiom, `toInt(mixed)` helper, cross-user safety guard. `IcsSettlementResolver` and `PaypalFundingResolver` mirror `PairTransferCandidates` directly.
- **`Modules/Categorization/` provides the full-feature module shape** — Livewire components + migrations + routes + views + Public-contract bindings + per-user fixture test pattern.
- **`Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` is the canonical query analog** — every Chains Public read service (`ChainLinkQuery`, `CardStatementQuery`, and the extended `nextIcsSettlement()` method) follows its DI + raw-query-builder + `toInt/toString` coercion + DTO composition pattern.
- **Schema migration shape is locked Phase-4** — `2026_05_15_010002_add_pair_transaction_id_to_transactions.php` is the canonical anonymous-class + memoised `$resolvedDb` shape every Phase 5 migration mirrors.
- **Enum CHECK via BEFORE INSERT/UPDATE trigger pair** — the canonical Phase 1 idiom at `transactions_type_check_*` triggers extends to `chain_links.kind`, `chain_links.state`, `card_statements.state`, `card_statement_credits.reason`.
- **Two "first project use" items need extra plan-phase rigour** — `ResolveChainLinksJob` (queue infrastructure) and `<flux:modal flyout>` (Flux drawer primitive). Both warrant Wave 0 smoke tests + snapshot baselines.

### File Created
`/Users/wesselverheij/Development/diederik/.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md`

### Ready for Planning
Pattern mapping complete. Planner can now reference analog patterns in PLAN.md files via the per-file Pattern Assignments entries above. Every new file has a concrete analog file + line range to read first.
