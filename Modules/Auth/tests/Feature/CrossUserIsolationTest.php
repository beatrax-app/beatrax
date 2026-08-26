<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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

// Auth-gated GET routes bearing no cross-user data; each entry carries its
// reviewed reason.
/**
 * @var list<string>
 */
const ISOLATION_ROUTE_ALLOW_LIST = [
    'logout',
    // A GET to /logout answers with the sign-in screen and reads nothing: it
    // exists because the mobile shell re-requests the URL a sign-out was posted
    // to, and met a 405 -- a stack trace with no navigation off it, on a phone.
    // It logs nobody out either, or an <img> could.
    'logout.landing',
    'auth.change-password',
    'auth.recovery-codes-display',
    'auth.users.create',
    'auth.users.manage',
    // The acting user's own close-behaviour preference; and the session-scoped
    // file intent, whose isolation FileOpenedFromOsTest proves directly.
    'desktop.close-prompt',
    'desktop.file-staging',
    // Every /dev/* route is EnsureDeveloperMode-gated (404, never 403) and
    // surfaces operator-level data only — registry rosters, the calling
    // developer's own runs — never another user's rows.
    'dev.overview',
    'dev.artisan.stream',
    // dev.audit deliberately shows every operator's runs: mutual visibility is
    // the Dev Console's contract, not a leak.
    'dev.artisan',
    'dev.audit',
    // The log file is system-wide on a single-user-per-machine install, so the
    // tailer, its poll and its context window carry no user rows.
    'dev.logs',
    'dev.logs.poll',
    'dev.logs.context',
    // Laravel does not user-scope the queue tables; they are system-wide here.
    'dev.queue',
    'dev.queue.tab',
    // Registered only when dev mode is on and Horizon is installed, and then
    // only as an <iframe> wrapper whose target keeps its own auth gate.
    'dev.horizon',
    // Operator-level: the latest doctor audit row, and host / framework /
    // SQLite facts with the secret-suffix redaction applied.
    'dev.doctor',
    'dev.system',
    // Schema metadata only. Running an actual SELECT needs the session-scoped
    // Advanced toggle, and it is the operator who chooses the statement.
    'dev.sql',
    // The wizard reads the acting user's own wizard_progress rows, and both the
    // mount-time safety net and the ?force=1 reset bound their writes to them.
    'setup',
    // Byte and line counts over the same system-wide log file as dev.logs.
    'dev.logs.stats',
    // Install-level paths plus a CTA branched on the acting user's own
    // is_developer flag; it queries no user-scoped rows at all.
    'core.help.data-locations',
    // A PIN pad keyed on the authenticated user's own lock state; the lock
    // screen never reads a transaction, account or recurring row.
    'auth.lock',
    // Redirect-only verbs: one builds a user-scoped consent URL, the other
    // consumes the user-bound single-use CSRF state. Probing them would only
    // exercise a mocked HTTP client; the settings surface they feed is probed.
    'oauth.open-banking.connect',
    'oauth.open-banking.callback',
];

/**
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
    'community.index',
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
    'tax.index',
    // op_log_quarantine has no BelongsToUser global scope, so this panel's
    // user-id filter is hand-applied and has to be probed rather than assumed.
    'dev.sync-health',
    'reconcile.index',
    'migrations.index',
    // Carries no per-entity id, but is probed for reachability anyway.
    'migrations.new',
    'migrations.preview',
    'migrations.results',
    'reports.export',
    // `?report={id}` is the IDOR surface; both routes read saved_reports rows.
    'reports.index',
    'reports.library',
    'data-devices.index',
    'mobile.setup',
    'mobile.setup.done',
    // The pairing scanner reads device_registry and pairing_tokens, both of
    // which carry other devices' rows; the PIN pad is probed for reachability.
    'mobile.pair',
    'mobile.lock',
    'notifications.index',
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
        'kind' => 'bank',
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

// `type` must be one of the trigger-enforced set: merchant, personal, bank,
// government, self_account, unknown.
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

// The staging-categories row is what stops `PreviewSummaryBuilder::forRun()`
// throwing `MigrationRunNotParsedException`.
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

function xuiAccount(DatabaseManager $db, int $userId, string $name): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'xui-acct-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00XACC'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

// Enough `definition` shape to render; the probes assert on name, not figures.
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
    // The fixtures book into May 2026 and the pages scope to the current
    // period, so an unpinned clock silently emptied every assertion.
    $this->travelTo(CarbonImmutable::parse('2026-05-19 12:00:00'));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

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

it('seeds two distinct users — a developer owner and a non-developer partner', function (): void {
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
    // A partner row of their own, so this asserts isolation rather than an
    // empty page.
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
    // The partner has no transactions, so the dashboard's first-run logic
    // redirects to /imports/new — hence the two accepted statuses.
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
    // Candidate state with a NULL to-endpoint is the trigger-permitted shape
    // for `funded_by_card_hint`.
    $this->db->connection()->table('chain_links')->insert([
        'user_id' => $this->owner->id,
        'from_transaction_id' => $this->ownerTransactionId,
        'to_transaction_id' => null,
        'kind' => 'funded_by_card_hint',
        'state' => 'candidate',
        'confidence' => 0.7,
        'resolver' => 'auto',
        'evidence' => '{"card_last4":"4242"}',
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
    // Two observed amounts, because the drift-watch query skips a series with
    // fewer than two occurrence points.
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

    // The full-page GET renders only layout chrome under test, so the content
    // assertions have to run against the component render.
    $this->actingAs($this->partner)
        ->get('/settings/aliases')
        ->assertOk();

    Livewire::actingAs($this->partner)
        ->test(AliasesSettingsPage::class)
        ->assertSee('Partner Visible Alias')
        ->assertDontSee('Owner Secret Alias');
});

it('does not bleed the owner shared-list counts into the partner community page', function (): void {
    xuiTransaction($this->db, $this->owner->id, 'OWNER COMMUNITY MERCHANT BV', description: 'OWNER COMMUNITY DESCRIPTION QZ42');

    $this->actingAs($this->partner)
        ->get('/community')
        ->assertOk()
        ->assertDontSee('OWNER COMMUNITY DESCRIPTION QZ42')
        ->assertDontSee('OWNER COMMUNITY MERCHANT BV');
});

it('does not bleed the owner mystery descriptions into the partner mystery-merchants page', function (): void {
    // No alias and no corpus match, so this description becomes a mystery card
    // on the owner's page.
    xuiTransaction($this->db, $this->owner->id, 'OWNER MYSTERY MERCHANT BV', description: 'OWNER MYSTERY DESCRIPTION XJ91');

    $this->actingAs($this->partner)
        ->get('/community/mystery-merchants')
        ->assertOk()
        ->assertSee('Mystery merchants')
        ->assertDontSee('OWNER MYSTERY DESCRIPTION XJ91');
});

it('does not bleed the owner tagged transactions into the partner tax page', function (): void {
    $ownerSuffix = bin2hex(random_bytes(4));
    $ownerAccountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->owner->id,
        'name' => 'Owner Tax Account '.$ownerSuffix,
        'slug' => 'xui-tax-acct-'.$ownerSuffix,
        'kind' => 'bank',
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

    // A tagged item of the partner's own, so /tax is not merely empty.
    $partnerSuffix = bin2hex(random_bytes(4));
    $partnerAccountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->partner->id,
        'name' => 'Partner Tax Account '.$partnerSuffix,
        'slug' => 'xui-tax-p-'.$partnerSuffix,
        'kind' => 'bank',
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

    // Both fixtures book into 2026, hence the year param.
    $this->actingAs($this->partner)
        ->get('/tax?year=2026')
        ->assertOk()
        ->assertDontSee('OWNER SECRET DEDUCTIBLE MERCHANT BV')
        ->assertDontSee('Owner Secret Category')
        ->assertDontSee('owner-secret-note');
});

it('does not bleed the owner quarantine rows into a second developer\'s sync-health panel', function (): void {
    // The page only surfaces quarantine rows from the last seven days, so the
    // fixture is seeded off the same Clock it reads. A hardcoded date here
    // rotted past the window and silently stopped asserting anything.
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

    $seedQuarantine($this->owner->id, 'owner-secret-device-xj91', 'owner_secret_reason');

    // A second developer: the isolation here is developer-to-developer, not
    // developer-to-partner.
    $devPartner = xuiUser('sync-health-dev-partner', developer: true);
    $seedQuarantine($devPartner->id, 'dev-partner-device', 'dev_partner_reason');

    $this->actingAs($devPartner)
        ->get('/dev/sync-health')
        ->assertOk()
        ->assertSee('dev-partner-device')
        ->assertDontSee('owner-secret-device-xj91')
        ->assertDontSee('owner_secret_reason');

    // Defence in depth: a non-developer never reaches the panel at all.
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

it('returns 404 (never 403) when the partner requests the owner migration preview', function (): void {
    $runId = xuiMigrationRun($this->db, $this->owner->id, 'Owner Migration Export.zip');

    $response = $this->actingAs($this->partner)->get("/migrations/{$runId}/preview");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('returns 404 (never 403) when the partner requests the owner migration results', function (): void {
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

it('renders /migrations/new for any authenticated user — no per-entity id, no data to leak', function (): void {
    $this->actingAs($this->partner)->get('/migrations/new')->assertOk();
});

it('does not bleed the owner peer device id into the partner /sync status surface', function (): void {
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
        ->get('/data-devices')
        ->assertOk()
        ->assertSee('partner-visible-peer-device')
        ->assertDontSee('owner-secret-peer-device');
});

it('does not bleed the owner initial-sync progress cursor into the partner /mobile/setup screen', function (): void {
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
        ->assertSee('12 records')
        ->assertDontSee('77 records');
});

// The redirect is the assertion: MobilePairingScan::mount() sends anyone who
// already has a confirmed peer to /data-devices, so an unscoped read of
// device_registry would bounce the partner off a screen they must reach.
it('does not let the owner paired device divert the partner away from /mobile/pair', function (): void {
    $this->db->connection()->table('device_registry')->insert([
        'user_id' => $this->owner->id,
        'device_id' => 'owner-secret-pair-peer',
        'name' => 'Owner Secret Desk',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => '2026-05-19 09:55:00',
        'confirmed_at' => '2026-05-19 09:55:00',
        'created_at' => '2026-05-19 09:55:00',
        'updated_at' => '2026-05-19 09:55:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/mobile/pair')
        ->assertOk()
        ->assertDontSee('Owner Secret Desk');
});

// The PIN pad reads only the acting user's own lock config, so this probes
// reachability and pins that nothing user-scoped reaches the markup.
it('renders /mobile/lock for the partner without the owner lock state', function (): void {
    $this->db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $this->owner->id,
        'cold_start_biometric_enrolled' => 1,
        'created_at' => '2026-05-19 09:55:00',
        'updated_at' => '2026-05-19 09:55:00',
    ]);

    $this->db->connection()->table('device_registry')->insert([
        'user_id' => $this->owner->id,
        'device_id' => 'owner-secret-lock-peer',
        'name' => 'Owner Secret Handset',
        'ed25519_public_key_hex' => str_repeat('e', 64),
        'x25519_public_key_hex' => str_repeat('f', 64),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => '2026-05-19 09:55:00',
        'confirmed_at' => '2026-05-19 09:55:00',
        'created_at' => '2026-05-19 09:55:00',
        'updated_at' => '2026-05-19 09:55:00',
    ]);

    $this->actingAs($this->partner)
        ->get('/mobile/lock')
        ->assertOk()
        ->assertDontSee('Owner Secret Handset')
        ->assertDontSee('owner-secret-lock-peer');
});

it('does not bleed the owner peer device name or record count into the partner /mobile/setup/done screen', function (): void {
    $seedPeer = function (int $userId, string $deviceId, string $name): void {
        $this->db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $name,
            'ed25519_public_key_hex' => str_repeat('a', 64),
            'x25519_public_key_hex' => str_repeat('b', 64),
            'safety_number_words' => '',
            'is_self' => 0,
            'paired_at' => '2026-07-11T09:55:00Z',
            'confirmed_at' => '2026-07-11T09:55:00Z',
            'created_at' => '2026-07-11T09:55:00Z',
            'updated_at' => '2026-07-11T09:55:00Z',
        ]);
    };

    $seedPeer($this->owner->id, 'owner-peer-device-done', 'Owner Secret Laptop');
    $seedPeer($this->partner->id, 'partner-peer-device-done', 'Partner Visible Laptop');

    $this->db->connection()->table('mobile_sync_progress')->insert([
        'user_id' => $this->owner->id,
        'peer_device_id' => 'owner-peer-device-done',
        'records_expected' => 4242,
        'records_applied' => 4242,
        'last_hlc_l' => 4242,
        'last_hlc_c' => 0,
        'phase' => 'complete',
        'created_at' => '2026-07-11T09:55:00Z',
        'updated_at' => '2026-07-11T10:00:00Z',
    ]);

    $this->actingAs($this->partner)
        ->get('/mobile/setup/done')
        ->assertOk()
        ->assertSee('Partner Visible Laptop')
        ->assertDontSee('Owner Secret Laptop')
        ->assertDontSee('4242');
});

it('does not bleed the owner spend into the partner reports CSV export', function (): void {
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

it('does not bleed the owner saved report into the partner reports library index', function (): void {
    xuiSavedReport($this->db, $this->owner->id, 'Owner Secret Saved Report');
    xuiSavedReport($this->db, $this->partner->id, 'Partner Visible Saved Report');

    $this->actingAs($this->partner)
        ->get('/reports/library')
        ->assertOk()
        ->assertSee('Partner Visible Saved Report')
        ->assertDontSee('Owner Secret Saved Report');
});

it('does not restore the owner saved report definition when the partner opens it by id', function (): void {
    // A foreign id falls back to the empty default rather than 404ing, which
    // would confirm the id exists. The contract is that the owner's report
    // name never renders, not any particular status.
    $ownerReportId = xuiSavedReport($this->db, $this->owner->id, 'Owner Secret Builder Report');

    $this->actingAs($this->partner)
        ->get('/reports?report='.$ownerReportId)
        ->assertOk()
        ->assertDontSee('Owner Secret Builder Report');
});

it('does not bleed the owner notification into the partner /notifications inbox (notifications.index)', function (): void {
    // The notifications columns are encryption-registered, but no encryption
    // session is active for these fixtures, so a raw insert stores and reads
    // back as plaintext.
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

it('does not bleed the owner open-banking connection into the partner settings surface (settings.open-banking)', function (): void {
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
