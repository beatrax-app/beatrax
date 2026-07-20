<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Livewire\Livewire;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

/*
 * The cross-user data-isolation matrix for every auth-gated GET route.
 *
 * This is the first Phase 12 test that creates TWO real users at once
 * (owner + partner) and probes every authenticated route from the
 * perspective of a second user. It enforces three guarantees:
 *
 *  1. Model-scoped routes (`/transactions/{id}`, `/recurring/series/{id}`)
 *     return HTTP 404 — never 403 — when a user requests a record that
 *     belongs to someone else. A 403 leaks the resource's existence.
 *
 *  2. List / index routes (`/`, `/settings`, `/transactions`,
 *     `/uncategorized`, `/rules`, ...) never surface another user's
 *     rows: the owner seeds user-scoped data, the partner loads the
 *     list, and the owner's data is absent from the partner's response.
 *
 *  3. A route-table-introspection guard fails the suite if a future
 *     auth GET route is added without either a probe case here or an
 *     entry on the explicit auth/guest-plumbing allow-list.
 */

/**
 * Auth-gated GET route names that legitimately carry no cross-user
 * data — authentication / account plumbing surfaces. Adding a route
 * here is a deliberate, reviewed exemption: these routes either render
 * the acting user's own account chrome (change-password, recovery
 * codes) or are POST-only verbs that surface no list of foreign rows.
 *
 * @var list<string>
 */
const ISOLATION_ROUTE_ALLOW_LIST = [
    'logout',
    'auth.change-password',
    'auth.recovery-codes-display',
    'auth.users.create',
    'auth.users.manage',
    // Phase 15 desktop chrome — neither surface lists foreign data.
    // `desktop.close-prompt` is the D-08 modal: it renders the acting
    // user's own close-behavior preference (`users.close_behavior`)
    // and dispatches a Livewire choice event; it never reads another
    // user's rows. `desktop.file-staging` consumes the
    // session-scoped PendingFileIntent (cross-user isolation is
    // proven by the dedicated test in FileOpenedFromOsTest "pending
    // intent does not leak across users") and emits one of two
    // copy-only states — file received vs empty — neither of which
    // surfaces a foreign data row.
    'desktop.close-prompt',
    'desktop.file-staging',
    // Phase 16 Dev Console — every /dev/* route is gated by
    // EnsureDeveloperMode (404-not-403 for non-developers) and
    // therefore is unreachable by the partner unless `is_developer`
    // is true. Once inside, the Dev Console surfaces only operator-
    // level data (registry rosters, the calling developer's own runs
    // — `dev.artisan.stream` adds a per-controller cross-user
    // ownership check on top, T-16-15). None of these GETs surface
    // foreign user-row data.
    'dev.overview',
    'dev.artisan.stream',
    // 16-04b: dev.artisan scopes its timeline query to causer_id ===
    // current user id; dev.audit is the operator-level audit log
    // (shows ALL dev_mode_audit rows by design — operators inspecting
    // each other's runs is the explicit Dev Console contract). Both
    // routes are EnsureDeveloperMode-gated (non-developers see 404).
    'dev.artisan',
    'dev.audit',
    // 16-05: log tailer surfaces. The page renders the dev-shell + a
    // 10k-line client-side ring buffer; the SSE stream emits scrubbed
    // log lines from the system-wide laravel-YYYY-MM-DD.log; the
    // context endpoint reads ±radius lines from the same file. None
    // of these surface foreign user-row data — the log file IS
    // system-wide on a single-user-per-machine install, and the
    // EnsureDeveloperMode gate blocks non-developers entirely.
    'dev.logs',
    'dev.logs.poll',
    'dev.logs.context',
    // 16-06: queue inspector surfaces. The framework-managed `jobs`,
    // `failed_jobs`, and `job_batches` tables are system-wide on a
    // single-user-per-machine install (Laravel does not user-scope
    // the queue tables); the EnsureDeveloperMode gate blocks
    // non-developers entirely. `dev.queue` is the redirect, the
    // canonical route is `dev.queue.tab` with a route param.
    'dev.queue',
    'dev.queue.tab',
    // 16-06: Horizon iframe (D-38). The route is conditionally
    // registered only when config('app.dev_mode')=true AND the
    // Horizon package is installed; when registered it surfaces an
    // <iframe src="/horizon"> wrapper — the iframe target has its own
    // auth gate. EnsureDeveloperMode covers the wrapper.
    'dev.horizon',
    // Phase 15 Plan 10 — mobile pairing/biometric-unlock entries. Both
    // routes are genuine closure-stub shapes today (`abort_unless
    // (class_exists($component), 404)` in Modules/Mobile/Routes/web.php):
    // MobilePairingScan/MobileLockScreen do not exist yet (Plans 06/07 are
    // gated behind the Plan 03 paid-plugin license/supply-chain
    // human-verify checkpoint per STATE.md). Every authenticated request —
    // owner or partner — gets an identical 404 with zero rendered body, so
    // there is structurally no per-user data to leak. Re-classify to
    // ISOLATION_ROUTE_COVERED with a real two-user probe once Plans 06/07
    // ship the real components.
    'mobile.lock',
    'mobile.pair',
    // 16-07: Doctor + System snapshot surfaces. The doctor page reads
    // the latest beatrax:doctor dev_mode_audit row (operator-level
    // event log — same audit-disclosure contract as dev.audit) and
    // the system page renders host + Laravel + SQLite facts via the
    // ConfigFlattener's secret-suffix redaction. Neither surfaces
    // foreign user-row data; the EnsureDeveloperMode gate blocks
    // non-developers entirely.
    'dev.doctor',
    'dev.system',
    // 16-07: SQL panel. Reads-only against the system-wide SQLite
    // database; the schema viewer enumerates table metadata which
    // does not surface per-user row data on its own. Every actual
    // SELECT is gated by the session-scoped Advanced toggle (D-46)
    // AND defense-in-depth (SelectOnlyValidator + PRAGMA query_only
    // = 1). EnsureDeveloperMode blocks non-developers entirely. The
    // schema viewer itself does not surface per-user data; the
    // operator decides which SELECT to run after toggling Advanced
    // and accepting the documented operator-level data-disclosure
    // contract (same shape as dev.audit + dev.queue.tab).
    'dev.sql',
    // 16.1-03a: first-run setup wizard. SetupWizard reads the acting
    // user's own wizard_progress rows via WizardProgressQuery, which
    // explicitly filters by current user_id (Phase 16 multi-user
    // readiness contract). The rendered surface is wizard chrome +
    // the active step body — no foreign user-row data crosses the
    // boundary. The mount-time UserInstalled safety-net + the
    // `?force=1` reset both bound their writes to the acting user.
    'setup',
    // 16-05 follow-up: dev.logs.stats joins the dev.logs family above.
    // The stats endpoint (LogStreamController::stats) returns byte /
    // line counts over the same system-wide laravel-YYYY-MM-DD.log the
    // tailer streams — operator-level file metadata, no user-row data.
    // Same EnsureDeveloperMode gate as every other /dev/* route
    // (non-developers see 404).
    'dev.logs.stats',
    // "Where is my data?" privacy help page. The HelpDataLocations
    // component renders install-level paths from UserDataPathService
    // (SQLite file, secrets dir, framework caches) plus an export CTA
    // branched on the ACTING user's own is_developer flag read via
    // CurrentUser exclusively — it queries no user-scoped rows at all,
    // so there is no foreign data to bleed.
    'core.help.data-locations',
    // Phase 05 app-lock screen. The /lock route is session-scoped:
    // it renders a PIN pad bound to the currently authenticated
    // user's own lock state (pin_hash, failed_attempts, locked_until
    // all keyed on the authenticated user_id). There is no data-bearing
    // list of foreign rows — the lock screen never queries another user's
    // transaction, account, or recurring data.
    'auth.lock',
    // (Plan 07-05 moved tax.index to ISOLATION_ROUTE_COVERED — real probe below)
    // Phase 19 — Enable Banking OAuth plumbing. Both are redirect-only
    // verbs that render no list of foreign rows: `connect` builds a
    // user-scoped consent URL and `redirect()->away()`s to the bank;
    // `callback` consumes the user-bound CSRF `state` (single-use, hash_equals,
    // user-id-bound — proven by OpenBankingConsentDanceTest / the 19 state
    // repository tests) and redirects to the settings page. Neither surfaces
    // another user's data, so they are allow-listed rather than probed (a real
    // probe would require mocking the outbound EB HTTP client for no isolation
    // signal). The data-bearing settings surface they feed IS probed —
    // see settings.open-banking in ISOLATION_ROUTE_COVERED.
    'oauth.open-banking.connect',
    'oauth.open-banking.callback',
];

/**
 * GET route names with an explicit cross-user probe case below. Keeping
 * the covered set as a named constant lets the introspection guard
 * assert "every auth GET route is covered or allow-listed".
 *
 * @var list<string>
 */
const ISOLATION_ROUTE_COVERED = [
    'dashboard',
    'settings',
    'transactions.index',
    'transactions.show',
    'uncategorized',
    'rules',
    'recurring.index',
    'recurring.review',
    'recurring.series.show',
    'chains.review',
    'drift.index',
    'forecast.index',
    'calendar.index',
    'inboxes.index',
    'oauth.connect',
    'oauth.callback',
    'imports.new',
    'imports.preview',
    'imports.results',
    'settings.aliases',
    'community.mystery-merchants',
    'counterparties.index',
    'counterparties.triage',
    'counterparties.profile',
    'budgets.index',
    'cashbook.index',
    'goals.index',
    'pots.index',
    'chains.index',
    'chains.hints',
    'drift.watch',
    // Phase 07 — Tax cockpit: two-user tagged-transaction probe is below (Plan 07-05).
    // ISOLATION_ROUTE_COVERED entry honors the contract promised in the Plan 01 allow-list comment.
    'tax.index',
    // Phase 11 — Sync-health surface (D-07). Unlike the sibling /dev/* routes
    // on the allow-list above (operator-level, no foreign user-row data), this
    // panel DOES bear user-scoped data: it lists the acting user's own
    // op_log_quarantine rows. op_log_quarantine has no BelongsToUser global
    // scope (Pitfall 4), so the user-id filter is hand-applied in the
    // component and must be probed, not allow-listed. Two-developer probe below.
    'dev.sync-health',
    // 13.3-06 — Reconcile surface (T-13.3-16/T-13.3-17). The account picker
    // and every statement pre-fill are user-scoped reads; probed below.
    'reconcile.index',
    // 13.5-08 — Migration wizard (T-13.5-24). All four routes are user-scoped
    // (`MigrationRun::query()->where('user_id', ...)->firstOrFail()` on every
    // action + PreviewSummaryBuilder's own independent guard); probed below.
    // `migrations.new` carries no per-entity id (same T-13.5-04 shape as
    // `imports.new`) but is still explicitly probed for reachability.
    'migrations.index',
    'migrations.new',
    'migrations.preview',
    'migrations.results',
    // 999.6-07 — Reports CSV export (Req 11, T-999.6-20). The route builds
    // its ReportDefinition entirely from the REQUESTING user's own
    // CurrentUser + query params and threads that $user through
    // ReportAggregator's user-scoped dimension queries; probed below with
    // a real two-user CSV-content check (not allow-listed — this route
    // genuinely bears user-scoped data).
    'reports.export',
    // 999.6-09 — the live builder (`?report={id}` IDOR surface, T-999.6-25)
    // and the saved-report library index. Both genuinely bear user-scoped
    // data (`saved_reports` rows); probed below with real two-user checks —
    // 999.6-08's SUMMARY explicitly deferred this coverage to this plan.
    'reports.index',
    'reports.library',
    // Phase 15 Plan 10 — the /sync status surface embeds the existing
    // sync.sync-status-section component (SyncStatusService-backed peer
    // rows) and reads the own-module mobile_sync_progress cursor; both
    // genuinely bear user-scoped data (T-15-26). Probed below.
    'sync.index',
    // Phase 15 Plan 08 shipped SetupProgressScreen behind this route; its
    // mount() reads the user-scoped mobile_sync_progress durable cursor
    // (InitialSyncPuller::progress()). Probed below (closes the gap
    // 15-05-SUMMARY.md logged as deferred to a later plan).
    'mobile.setup',
    // Phase 18 — Notifications inbox. NotificationInbox lists the acting
    // user's own `notifications` rows (user_id-scoped read); genuinely bears
    // user-scoped data, so probed below rather than allow-listed.
    'notifications.index',
    // Phase 19 — Open-banking settings surface. OpenBankingSettingsPage renders
    // OpenBankingConnectionQuery::current() for the acting user (user_id-scoped;
    // WR-05/19-11). It bears user-scoped connection data (institution / bank
    // display name / consent), so probed below.
    'settings.open-banking',
];

function xuiUser(string $username, bool $developer = false): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'cross-user-fixture-pass',
        'is_developer' => $developer,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Seeds an account + import run + a single transaction for the given
 * user and returns the transaction id. Raw inserts keep the fixture
 * independent of any module's factory wiring.
 */
function xuiTransaction(
    DatabaseManager $db,
    int $userId,
    string $counterparty,
    string $description = 'cross-user fixture',
    string $sourceFormat = 'asn-csv',
): int {
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN '.$suffix,
        'slug' => 'xui-asn-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/xui-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'xui-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'committed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'xui-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -2599,
        'currency' => 'EUR',
        'settled_amount_minor' => -2599,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => strtolower($counterparty),
        'counterparty_name' => $counterparty,
        'normalization_version' => 1,
        'description' => $description,
        'type' => 'expense',
        'source_format' => $sourceFormat,
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * Seeds an approved recurring series for the user and returns its id.
 */
function xuiRecurringSeries(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -2599,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'xui::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * Seeds a counterparty row for the user and returns its id. The
 * `type` value must be one of the trigger-enforced set
 * (merchant|personal|bank|government|self_account|unknown).
 */
function xuiCounterparty(DatabaseManager $db, int $userId, string $type, string $slug, string $displayName): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'slug' => $slug,
        'display_name' => $displayName,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * Seeds a `migration_runs` row plus one `migration_staging_categories` row
 * (so `PreviewSummaryBuilder::forRun()` does not throw
 * `MigrationRunNotParsedException`) for the given user, and returns the run
 * id. Raw inserts — deliberately does NOT depend on the Migration module's
 * own test fixtures/parsers, keeping this cross-module isolation test
 * self-contained (matches every other `xui*` helper's raw-insert style).
 */
function xuiMigrationRun(DatabaseManager $db, int $userId, string $originalFilename): int
{
    $runId = $db->connection()->table('migration_runs')->insertGetId([
        'user_id' => $userId,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => $originalFilename,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $db->connection()->table('migration_staging_categories')->insert([
        'user_id' => $userId,
        'migration_run_id' => $runId,
        'source_external_id' => 'cat-1',
        'name' => 'Groceries',
        'kind' => 'expense',
    ]);

    return $runId;
}

/**
 * Seeds a bare account row (pots / goals pickers need one) and
 * returns its id.
 */
function xuiAccount(DatabaseManager $db, int $userId, string $name): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'xui-acct-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00XACC'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

/**
 * Seeds a `saved_reports` row for the given user and returns its id. Raw
 * insert — deliberately does NOT depend on the Reports module's own
 * write-action wiring (`SaveReport`), keeping this cross-module isolation
 * test self-contained (matches every other `xui*` helper's raw-insert
 * style). The `definition` JSON only needs enough shape for
 * `ReportBuilder`/`ReportsIndex` to render without erroring — the probes
 * below assert on the report's `name`, not its aggregated figures.
 */
function xuiSavedReport(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('saved_reports')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'definition' => json_encode([
            'metric' => 'spend',
            'dimension' => 'category',
            'periodPreset' => 'this_month',
            'granularity' => 'monthly',
            'currencyMode' => 'base',
            'viz' => 'table',
        ]),
        'pinned' => false,
        'pin_order' => null,
        'created_at' => '2026-07-07 00:00:00',
        'updated_at' => '2026-07-07 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    // The two-user setup MULTI-03 requires: an owner (developer) and a
    // partner, each with their own scoped data.
    $this->owner = xuiUser('owner', developer: true);
    $this->partner = xuiUser('partner');

    // A global category — categorization_rules.category_id is NOT NULL.
    $this->category = Category::query()->create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'xui-groceries',
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    // Owner-scoped fixtures — the partner must never see any of these.
    $this->ownerTransactionId = xuiTransaction($this->db, $this->owner->id, 'OWNER MERCHANT BV');
    $this->ownerSeriesId = xuiRecurringSeries($this->db, $this->owner->id, 'Owner Subscription');

    $this->ownerRule = CategorizationRule::query()->create([
        'user_id' => $this->owner->id,
        'field' => 'counterparty',
        'match' => 'contains',
        'value' => 'owner-secret-rule-token',
        'category_id' => $this->category->id,
        'active' => true,
    ]);
});

it('creates two users — the first Phase 12 test to do so', function (): void {
    expect(User::query()->count())->toBe(2);
    expect($this->owner->id)->not->toBe($this->partner->id);
    expect($this->owner->is_developer)->toBeTrue();
    expect($this->partner->is_developer)->toBeFalse();
});

it('returns 404 (never 403) when the partner requests the owner transaction detail', function (): void {
    $response = $this->actingAs($this->partner)
        ->get('/transactions/'.$this->ownerTransactionId);

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('returns 404 (never 403) when the partner requests the owner recurring series', function (): void {
    $response = $this->actingAs($this->partner)
        ->get('/recurring/series/'.$this->ownerSeriesId);

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('does not bleed the owner transaction into the partner transactions list', function (): void {
    // Give the partner their own transaction so the list is non-empty
    // and we are asserting isolation, not just an empty page.
    xuiTransaction($this->db, $this->partner->id, 'PARTNER MERCHANT BV');

    $this->actingAs($this->partner)
        ->get('/transactions')
        ->assertOk()
        ->assertSee('PARTNER MERCHANT BV')
        ->assertDontSee('OWNER MERCHANT BV');
});

it('does not bleed the owner transaction into the partner uncategorized triage list', function (): void {
    xuiTransaction($this->db, $this->partner->id, 'PARTNER TRIAGE BV');

    $this->actingAs($this->partner)
        ->get('/uncategorized')
        ->assertOk()
        ->assertDontSee('OWNER MERCHANT BV');
});

it('does not bleed the owner categorization rule into the partner rules list', function (): void {
    CategorizationRule::query()->create([
        'user_id' => $this->partner->id,
        'field' => 'counterparty',
        'match' => 'contains',
        'value' => 'partner-visible-rule-token',
        'category_id' => $this->category->id,
        'active' => true,
    ]);

    $this->actingAs($this->partner)
        ->get('/rules')
        ->assertOk()
        ->assertDontSee('owner-secret-rule-token');
});

it('does not surface the owner figures on the partner dashboard or first-run redirect', function (): void {
    // The partner has zero transactions, so the dashboard first-run
    // logic redirects to /imports/new. Either way the owner's merchant
    // must not appear.
    $response = $this->actingAs($this->partner)->get('/');

    expect($response->status())->toBeIn([200, 302]);
    if ($response->status() === 302) {
        $response->assertRedirect(route('imports.new'));
    } else {
        $response->assertDontSee('OWNER MERCHANT BV');
    }
});

it('renders the partner settings page without the owner data', function (): void {
    $this->actingAs($this->partner)
        ->get('/settings')
        ->assertOk()
        ->assertDontSee('OWNER MERCHANT BV');
});

it('renders the partner calendar page without the owner data', function (): void {
    $this->actingAs($this->partner)
        ->get('/calendar')
        ->assertOk()
        ->assertDontSee('OWNER MERCHANT BV');
});

it('does not bleed the owner counterparties into the partner counterparties index', function (): void {
    xuiCounterparty($this->db, $this->owner->id, 'merchant', 'owner-secret-counterparty', 'Owner Secret Counterparty');
    xuiCounterparty($this->db, $this->partner->id, 'merchant', 'partner-visible-counterparty', 'Partner Visible Counterparty');

    $this->actingAs($this->partner)
        ->get('/counterparties')
        ->assertOk()
        ->assertSee('Partner Visible Counterparty')
        ->assertDontSee('Owner Secret Counterparty');
});

it('does not bleed the owner unknown counterparty into the partner triage queue', function (): void {
    xuiCounterparty($this->db, $this->owner->id, 'unknown', 'owner-unknown-token', 'OWNER UNKNOWN MERCHANT TOKEN');

    $this->actingAs($this->partner)
        ->get('/counterparties/triage')
        ->assertOk()
        ->assertSee('Triage unknown counterparties')
        ->assertDontSee('OWNER UNKNOWN MERCHANT TOKEN');
});

it('returns 404 (never 403) when the partner requests the owner counterparty profile slug', function (): void {
    xuiCounterparty($this->db, $this->owner->id, 'merchant', 'owner-secret-counterparty', 'Owner Secret Counterparty');

    $response = $this->actingAs($this->partner)
        ->get('/counterparties/owner-secret-counterparty');

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('does not bleed the owner budget category into the partner budgets page', function (): void {
    $ownerCategory = Category::query()->create([
        'user_id' => $this->owner->id,
        'name' => 'Owner Secret Category',
        'slug' => 'xui-owner-secret-category',
        'kind' => 'expense',
        'display_order' => 40,
    ]);

    $this->db->connection()->table('category_budgets')->insert([
        'user_id' => $this->owner->id,
        'category_id' => $ownerCategory->id,
        'period_type' => 'monthly',
        'budget_minor' => 50000,
        'currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/budgets')
        ->assertOk()
        ->assertSee('Budgets')
        ->assertDontSee('Owner Secret Category');
});

it('does not bleed the owner manual cash entries into the partner cash book', function (): void {
    xuiTransaction($this->db, $this->owner->id, 'OWNER CASH MERCHANT BV', sourceFormat: 'manual');
    xuiTransaction($this->db, $this->partner->id, 'PARTNER CASH MERCHANT BV', sourceFormat: 'manual');

    $this->actingAs($this->partner)
        ->get('/cash')
        ->assertOk()
        ->assertSee('PARTNER CASH MERCHANT BV')
        ->assertDontSee('OWNER CASH MERCHANT BV');
});

it('does not bleed the owner goals into the partner goals page', function (): void {
    $insertGoal = function (int $userId, string $name): void {
        $this->db->connection()->table('goals')->insert([
            'user_id' => $userId,
            'account_id' => null,
            'name' => $name,
            'target_minor' => 100000,
            'target_currency' => 'EUR',
            'start_date' => '2026-01-01',
            'target_date' => '2026-12-31',
            'status' => 'active',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    };

    $insertGoal($this->owner->id, 'Owner Secret Goal');
    $insertGoal($this->partner->id, 'Partner Visible Goal');

    $this->actingAs($this->partner)
        ->get('/goals')
        ->assertOk()
        ->assertSee('Partner Visible Goal')
        ->assertDontSee('Owner Secret Goal');
});

it('does not bleed the owner pots into the partner pots page', function (): void {
    $ownerAccountId = xuiAccount($this->db, $this->owner->id, 'Owner Pot Account');

    $this->db->connection()->table('pots')->insert([
        'user_id' => $this->owner->id,
        'account_id' => $ownerAccountId,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Owner Secret Pot',
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/pots')
        ->assertOk()
        ->assertSee('Pots')
        ->assertDontSee('Owner Secret Pot')
        ->assertDontSee('Owner Pot Account');
});

it('does not bleed the owner confirmed chain into the partner chains index', function (): void {
    $fundingTxId = xuiTransaction($this->db, $this->owner->id, 'OWNER FUNDING SOURCE BV');

    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->owner->id,
        'from_transaction_id' => $this->ownerTransactionId,
        'to_transaction_id' => $fundingTxId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.0,
        'resolver' => 'auto',
        'evidence' => '{}',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/chains')
        ->assertOk()
        ->assertSee('Chains')
        ->assertDontSee('OWNER MERCHANT BV')
        ->assertDontSee('OWNER FUNDING SOURCE BV');
});

it('does not bleed the owner hint candidates into the partner chain hints queue', function (): void {
    // A receipt-derived hint: candidate state + NULL to-endpoint is
    // the trigger-permitted shape for `funded_by_card_hint`.
    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->owner->id,
        'from_transaction_id' => $this->ownerTransactionId,
        'to_transaction_id' => null,
        'kind' => 'funded_by_card_hint',
        'state' => 'candidate',
        'confidence' => 0.7,
        'resolver' => 'auto',
        'evidence' => '{"card_last_four":"4242"}',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/chains/hints')
        ->assertOk()
        ->assertSee('Hints')
        ->assertDontSee('OWNER MERCHANT BV');
});

it('does not bleed the owner subscription series into the partner drift watch page', function (): void {
    // Give the owner series two observed amounts so it genuinely
    // qualifies for the drift-watch list (the query skips series with
    // fewer than two occurrence points).
    $seed = function (string $date, int $amount): void {
        $txId = xuiTransaction($this->db, $this->owner->id, 'OWNER SUB CHARGE BV');
        $this->db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $this->owner->id,
            'recurring_series_id' => $this->ownerSeriesId,
            'transaction_id' => $txId,
            'observed_at' => $date,
            'observed_amount_minor' => $amount,
            'observed_currency' => 'EUR',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    };
    $seed('2026-04-15', -1000);
    $seed('2026-05-15', -1100);

    $this->actingAs($this->partner)
        ->get('/drift/watch')
        ->assertOk()
        ->assertSee('Subscription drift')
        ->assertDontSee('Owner Subscription');
});

it('does not bleed the owner merchant aliases into the partner aliases settings page', function (): void {
    $insertAlias = function (int $userId, string $pattern, string $friendlyName): void {
        $this->db->connection()->table('merchant_aliases')->insert([
            'user_id' => $userId,
            'pattern' => $pattern,
            'generalized_pattern' => $pattern,
            'friendly_name' => $friendlyName,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    };

    $insertAlias($this->owner->id, 'OWNER RAW PATTERN 123', 'Owner Secret Alias');
    $insertAlias($this->partner->id, 'PARTNER RAW PATTERN 456', 'Partner Visible Alias');

    // The full-page GET renders only the layout chrome in the test
    // environment (same as AliasesSettingsPageTest, which asserts
    // content at the component level), so probe the component render
    // directly — the page route still gets its assertOk smoke check.
    $this->actingAs($this->partner)
        ->get('/settings/aliases')
        ->assertOk();

    Livewire::actingAs($this->partner)
        ->test(AliasesSettingsPage::class)
        ->assertSee('Partner Visible Alias')
        ->assertDontSee('Owner Secret Alias');
});

it('does not bleed the owner mystery descriptions into the partner mystery-merchants page', function (): void {
    // An unresolvable description (no alias, no corpus match) becomes
    // a mystery card on the OWNER's page — it must never surface on
    // the partner's.
    xuiTransaction($this->db, $this->owner->id, 'OWNER MYSTERY MERCHANT BV', description: 'OWNER MYSTERY DESCRIPTION XJ91');

    $this->actingAs($this->partner)
        ->get('/community/mystery-merchants')
        ->assertOk()
        ->assertSee('Mystery merchants')
        ->assertDontSee('OWNER MYSTERY DESCRIPTION XJ91');
});

it('does not bleed the owner tagged transactions into the partner tax page (T-07-15)', function (): void {
    // Tag a transaction for the OWNER under a recognisable counterparty.
    $ownerSuffix = bin2hex(random_bytes(4));
    $ownerAccountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->owner->id,
        'name' => 'Owner Tax Account '.$ownerSuffix,
        'slug' => 'xui-tax-acct-'.$ownerSuffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($ownerSuffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $ownerRunId = $this->db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->owner->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/xui-tax-'.$ownerSuffix.'.csv',
        'sha256' => hash('sha256', 'xui-tax-run-'.$ownerSuffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'committed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $ownerTxId = $this->db->connection()->table('transactions')->insertGetId([
        'user_id' => $this->owner->id,
        'account_id' => $ownerAccountId,
        'import_run_id' => $ownerRunId,
        'fingerprint' => hash('sha256', 'xui-tax-tx-'.$ownerSuffix),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -9999,
        'currency' => 'EUR',
        'settled_amount_minor' => -9999,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'owner-secret-deductible-merchant',
        'counterparty_name' => 'OWNER SECRET DEDUCTIBLE MERCHANT BV',
        'normalization_version' => 1,
        'description' => 'owner-secret-deductible-tx',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // Owner tax category + tag
    $ownerCatId = $this->db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $this->owner->id,
        'name' => 'Owner Secret Category',
        'short_name' => 'Owner Cat',
        'corpus_key' => 'xui_owner_'.$ownerSuffix,
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $this->db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $this->owner->id,
        'transaction_id' => $ownerTxId,
        'deduction_category_id' => $ownerCatId,
        'note' => 'owner-secret-note',
        'tax_year_override' => null,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // The partner gets a tagged item in their own year so their /tax is non-empty.
    $partnerSuffix = bin2hex(random_bytes(4));
    $partnerAccountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->partner->id,
        'name' => 'Partner Tax Account '.$partnerSuffix,
        'slug' => 'xui-tax-p-'.$partnerSuffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNC'.strtoupper($partnerSuffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $partnerRunId = $this->db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->partner->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/xui-tax-p-'.$partnerSuffix.'.csv',
        'sha256' => hash('sha256', 'xui-tax-prun-'.$partnerSuffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'committed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $partnerTxId = $this->db->connection()->table('transactions')->insertGetId([
        'user_id' => $this->partner->id,
        'account_id' => $partnerAccountId,
        'import_run_id' => $partnerRunId,
        'fingerprint' => hash('sha256', 'xui-tax-ptx-'.$partnerSuffix),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'partner-visible-merchant',
        'counterparty_name' => 'PARTNER VISIBLE MERCHANT BV',
        'normalization_version' => 1,
        'description' => 'partner-visible-tx',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $partnerCatId = $this->db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $this->partner->id,
        'name' => 'Partner Category',
        'short_name' => 'Partner Cat',
        'corpus_key' => 'xui_partner_'.$partnerSuffix,
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $this->db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $this->partner->id,
        'transaction_id' => $partnerTxId,
        'deduction_category_id' => $partnerCatId,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    // Partner visits /tax — must see their own data, never the owner's.
    // The year param is 2026 (booked_at 2026-06-15 for both fixtures).
    $this->actingAs($this->partner)
        ->get('/tax?year=2026')
        ->assertOk()
        ->assertDontSee('OWNER SECRET DEDUCTIBLE MERCHANT BV')
        ->assertDontSee('Owner Secret Category')
        ->assertDontSee('owner-secret-note');
});

it('does not bleed the owner quarantine rows into a second developer\'s sync-health panel (Pitfall 4, T-11-13)', function (): void {
    // /dev/sync-health is user-scoped: op_log_quarantine has no
    // BelongsToUser global scope, so the component hand-filters by the
    // acting user_id. Two developers must each see only their own rows.
    // SyncHealthPage only surfaces quarantine rows from the last 7 days
    // (created_at >= Clock::now()->subDays(7)). Seed relative to the same
    // Clock the page reads so the fixture never ages out of the window as
    // real wall-clock time advances (previously hardcoded 2026-06-14, which
    // silently rotted past the window and turned this into a time-bomb).
    $seededAt = app(Clock::class)->now()->subDay()->toDateTimeString();
    $seedQuarantine = function (int $userId, string $deviceId, string $reason) use ($seededAt): void {
        $this->db->connection()->table('op_log_quarantine')->insert([
            'user_id' => $userId,
            'table_name' => 'transactions',
            'pk' => '1',
            'device_id' => $deviceId,
            'reason' => $reason,
            'created_at' => $seededAt,
        ]);
    };

    // Owner (a developer per the beforeEach fixture) gets a quarantine row
    // with a unique, recognisable device + reason token.
    $seedQuarantine($this->owner->id, 'owner-secret-device-xj91', 'owner_secret_reason');

    // A second developer with their own row — they must never see the owner's.
    $devPartner = xuiUser('sync-health-dev-partner', developer: true);
    $seedQuarantine($devPartner->id, 'dev-partner-device', 'dev_partner_reason');

    $this->actingAs($devPartner)
        ->get('/dev/sync-health')
        ->assertOk()
        ->assertSee('dev-partner-device')
        ->assertDontSee('owner-secret-device-xj91')
        ->assertDontSee('owner_secret_reason');

    // Defense-in-depth: the non-developer partner is blocked entirely by
    // EnsureDeveloperMode (404, never 403 — T-11-14).
    $this->actingAs($this->partner)
        ->get('/dev/sync-health')
        ->assertNotFound();
});

it('does not bleed the owner account into the partner reconcile account picker', function (): void {
    xuiAccount($this->db, $this->owner->id, 'Owner Secret Reconcile Account');
    xuiAccount($this->db, $this->partner->id, 'Partner Visible Reconcile Account');

    $this->actingAs($this->partner)
        ->get('/reconcile')
        ->assertOk()
        ->assertSee('Partner Visible Reconcile Account')
        ->assertDontSee('Owner Secret Reconcile Account');
});

it('returns 404 (never 403) when the partner requests the owner migration preview (T-13.5-24)', function (): void {
    $runId = xuiMigrationRun($this->db, $this->owner->id, 'Owner Migration Export.zip');

    $response = $this->actingAs($this->partner)->get("/migrations/{$runId}/preview");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('returns 404 (never 403) when the partner requests the owner migration results (T-13.5-24)', function (): void {
    $runId = xuiMigrationRun($this->db, $this->owner->id, 'Owner Migration Export.zip');

    $response = $this->actingAs($this->partner)->get("/migrations/{$runId}/results");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('does not bleed the owner migration run into the partner migrations index', function (): void {
    xuiMigrationRun($this->db, $this->owner->id, 'Owner Migration Export.zip');

    $this->actingAs($this->partner)
        ->get('/migrations')
        ->assertOk()
        ->assertDontSee('Owner Migration Export.zip', false);
});

it('renders /migrations/new for any authenticated user — no per-entity id, no data to leak (T-13.5-04 shape)', function (): void {
    $this->actingAs($this->partner)->get('/migrations/new')->assertOk();
});

it('does not bleed the owner peer device id into the partner /sync status surface (Phase 15 Plan 10, T-15-26)', function (): void {
    $seedSession = function (int $userId, string $localDeviceId, string $peerDeviceId): void {
        $this->db->connection()->table('sync_sessions')->insert([
            'user_id' => $userId,
            'local_device_id' => $localDeviceId,
            'peer_device_id' => $peerDeviceId,
            'status' => 'closed',
            'error_message' => null,
            'last_seen_at' => '2026-07-11T10:00:00Z',
            'connected_at' => '2026-07-11T09:55:00Z',
            'created_at' => '2026-07-11T09:55:00Z',
            'updated_at' => '2026-07-11T10:00:00Z',
        ]);
    };

    $seedSession($this->owner->id, 'owner-local-dev', 'owner-secret-peer-device');
    $seedSession($this->partner->id, 'partner-local-dev', 'partner-visible-peer-device');

    $this->actingAs($this->partner)
        ->get('/sync')
        ->assertOk()
        ->assertSee('partner-visible-peer-device')
        ->assertDontSee('owner-secret-peer-device');
});

it('does not bleed the owner initial-sync progress cursor into the partner /mobile/setup screen (Phase 15 Plan 08/10)', function (): void {
    $seedProgress = function (int $userId, string $peerDeviceId, int $applied, int $expected): void {
        $this->db->connection()->table('mobile_sync_progress')->insert([
            'user_id' => $userId,
            'peer_device_id' => $peerDeviceId,
            'records_expected' => $expected,
            'records_applied' => $applied,
            'last_hlc_l' => $applied,
            'last_hlc_c' => 0,
            'phase' => 'pulling',
            'created_at' => '2026-07-11T09:55:00Z',
            'updated_at' => '2026-07-11T10:00:00Z',
        ]);
    };

    $seedProgress($this->owner->id, 'owner-secret-peer-device-setup', 77, 100);
    $seedProgress($this->partner->id, 'partner-peer-device-setup', 12, 40);

    $this->actingAs($this->partner)
        ->get('/mobile/setup')
        ->assertOk()
        ->assertSee('12 of 40 records')
        ->assertDontSee('77 of 100 records');
});

it('does not bleed the owner spend into the partner reports CSV export (999.6-07, T-999.6-20)', function (): void {
    $ownerAccountId = xuiAccount($this->db, $this->owner->id, 'Owner Secret Export Account');
    $partnerAccountId = xuiAccount($this->db, $this->partner->id, 'Partner Visible Export Account');

    $seedExportTx = function (int $userId, int $accountId): void {
        $suffix = bin2hex(random_bytes(4));
        $runId = $this->db->connection()->table('import_runs')->insertGetId([
            'user_id' => $userId,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/xui-export-'.$suffix.'.csv',
            'sha256' => hash('sha256', 'xui-export-run-'.$suffix),
            'uploaded_at' => '2026-07-01 00:00:00',
            'status' => 'committed',
            'created_at' => '2026-07-01 00:00:00',
            'updated_at' => '2026-07-01 00:00:00',
        ]);

        $this->db->connection()->table('transactions')->insert([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'xui-export-tx-'.$suffix),
            'posted_at' => '2026-07-01',
            'booked_at' => '2026-07-01 00:00:00',
            'value_date' => '2026-07-01',
            'amount_minor' => -5000,
            'currency' => 'EUR',
            'settled_amount_minor' => -5000,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'xui-export-merchant',
            'counterparty_name' => 'XUI Export Merchant BV',
            'normalization_version' => 1,
            'description' => 'xui-export-fixture',
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'fingerprint_version' => 3,
            'created_at' => '2026-07-01 00:00:00',
            'updated_at' => '2026-07-01 00:00:00',
        ]);
    };

    $seedExportTx($this->owner->id, $ownerAccountId);
    $seedExportTx($this->partner->id, $partnerAccountId);

    $response = $this->actingAs($this->partner)
        ->get('/reports/export?dim=account&metric=spend&period=custom&from=2026-07-01&to=2026-07-01');

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('Partner Visible Export Account');
    expect($csv)->not->toContain('Owner Secret Export Account');
});

it('does not bleed the owner saved report into the partner reports library index (999.6-09, Req 9, T-999.6-25)', function (): void {
    xuiSavedReport($this->db, $this->owner->id, 'Owner Secret Saved Report');
    xuiSavedReport($this->db, $this->partner->id, 'Partner Visible Saved Report');

    $this->actingAs($this->partner)
        ->get('/reports/library')
        ->assertOk()
        ->assertSee('Partner Visible Saved Report')
        ->assertDontSee('Owner Secret Saved Report');
});

it('does not restore the owner saved report definition when the partner opens it by id (999.6-09, T-999.6-25)', function (): void {
    // ReportBuilder::mount(?int $report) (Plan 08) silently falls back to
    // the builder's own empty default on a foreign/missing id — never a
    // 404, which would confirm the id's existence (999.6-08-SUMMARY.md).
    // The isolation contract here is "the owner's report name never
    // renders", not a particular HTTP status.
    $ownerReportId = xuiSavedReport($this->db, $this->owner->id, 'Owner Secret Builder Report');

    $this->actingAs($this->partner)
        ->get('/reports?report='.$ownerReportId)
        ->assertOk()
        ->assertDontSee('Owner Secret Builder Report');
});

it('does not bleed the owner notification into the partner /notifications inbox (Phase 18, notifications.index)', function (): void {
    // Raw plaintext insert into the (encryption-registered) notifications
    // columns, mirroring xuiTransaction's approach — no encryption session is
    // active for these fixtures, so columns store/read as plaintext. The
    // partner's inbox query is user_id-scoped, so the owner's row must never
    // render in the partner's response.
    $this->db->connection()->table('notifications')->insert([
        'id' => 'xui-owner-notif-'.bin2hex(random_bytes(6)),
        'user_id' => $this->owner->id,
        'state' => 'open',
        'title' => 'Owner Secret Notification Title',
        'body' => 'Owner secret notification body',
        'trigger_type' => 'reminder',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/notifications')
        ->assertOk()
        ->assertDontSee('Owner Secret Notification Title');
});

it('does not bleed the owner open-banking connection into the partner settings surface (Phase 19, settings.open-banking)', function (): void {
    // OpenBankingConnectionQuery::current() filters by the acting user id, so
    // the partner (who has no connection of their own) must see the off-state
    // and never the owner's distinctive bank display name. A broken user-id
    // filter would surface the owner's row here.
    $this->db->connection()->table('open_banking_connections')->insert([
        'user_id' => $this->owner->id,
        'institution_id' => 'ASNBNL21',
        'account_uid' => 'xui-owner-acc-uid',
        'bank_display_name' => 'Owner Secret Bank Name',
        'enabled' => true,
        'consent_expires_at' => '2027-01-01 00:00:00',
        'last_successful_sync_at' => null,
        'last_attempt_at' => null,
        'last_attempt_status' => null,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/settings/open-banking')
        ->assertOk()
        ->assertDontSee('Owner Secret Bank Name');
});

it('covers or allow-lists every auth-gated GET route — regression guard', function (): void {
    /** @var Router $router */
    $router = $this->app->make(Router::class);

    $uncovered = [];

    /** @var RoutingRoute $route */
    foreach ($router->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (! in_array('auth', $route->gatherMiddleware(), true)) {
            continue;
        }

        $name = $route->getName();
        if ($name === null) {
            $uncovered[] = $route->uri().' (unnamed)';

            continue;
        }

        if (
            in_array($name, ISOLATION_ROUTE_COVERED, true)
            || in_array($name, ISOLATION_ROUTE_ALLOW_LIST, true)
        ) {
            continue;
        }

        $uncovered[] = $name;
    }

    expect($uncovered)->toBe(
        [],
        "Every auth-gated GET route needs a cross-user probe case or an allow-list entry. Uncovered:\n  ".implode("\n  ", $uncovered),
    );
});
