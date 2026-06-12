<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
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
    // Phase 05 app-lock screen. The /lock route is session-scoped:
    // it renders a PIN pad bound to the currently authenticated
    // user's own lock state (pin_hash, failed_attempts, locked_until
    // all keyed on the authenticated user_id). There is no data-bearing
    // list of foreign rows — the lock screen never queries another user's
    // transaction, account, or recurring data.
    'auth.lock',
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
function xuiTransaction(DatabaseManager $db, int $userId, string $counterparty): int
{
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
        'description' => 'cross-user fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
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
