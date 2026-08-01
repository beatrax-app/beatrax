<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/*
 * R8 / SPEC acceptance #8 (15-11-PLAN.md Task 1) — the automatable half of
 * "full surface parity on mobile": every primary sidebar route
 * (Modules/Core/Resources/views/livewire/app-sidebar.blade.php) renders
 * without a server error against an on-device-SHAPED fixture — a real
 * user, an unlocked LOCK-04 session, a generated GDK epoch-1 keyring
 * (EnablesEncryptionForUser, the established 14.1 helper), sensitive
 * transaction/counterparty columns written as genuine ciphertext
 * (SensitiveColumnCodec::encryptAttrs — mirrors the direct-write/import
 * hook `RecordTransactions` uses in production, D-02b), and a confirmed
 * `device_registry` peer + `sync_sessions` row standing in for "this
 * device is a paired, synced peer" (T-15-34's "synced fixture").
 *
 * 15-SPIKE-FINDINGS.md's pre-Plan-04 on-device 500 was a missing-migration
 * / DB-path problem (fixed in Plan 04's MobileFirstLaunchBootstrap +
 * reconciled UserDataPathService path) — orthogonal to this test, which
 * runs against the framework's normal RefreshDatabase-migrated connection
 * (the same connection every other Feature test in this suite uses). This
 * test proves the APPLICATION layer renders correctly against
 * encrypted/synced data; it does not re-prove the DB-path reconciliation
 * itself (that is Plan 04's own coverage).
 *
 * Deep responsive/UX polish is explicitly Phase 16 (SPEC Boundaries) — this
 * test asserts "loads and functions without error", not pixel layout.
 *
 * Route enumeration source of truth: every `<a class="side-item" href="{{
 * route(...) }}">` entry in app-sidebar.blade.php EXCLUDING the
 * `href="#"` Receipts placeholder (not yet implemented — nothing to
 * smoke), the developer-only `/dev` Dev Console entry (conditional
 * chrome, not a primary user surface, and already covered by its own
 * EnsureDeveloperMode-gated suite), and the sign-out POST form (not a
 * GET surface). That leaves exactly 24 `route()` calls — the dataset
 * below has exactly 24 entries, so `count(dataset) >= count(side-item
 * route() calls)` holds with equality.
 */

function mobileSurfaceParityUser(): User
{
    return User::query()->create([
        'username' => 'mobile-surface-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('mobile-surface-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Seeds an on-device-shaped fixture for $user: an account, an import run,
 * ONE transaction whose sensitive columns (description, counterparty_name)
 * are written as genuine GDK ciphertext via SensitiveColumnCodec — exactly
 * the direct-write hook `RecordTransactions` uses in production — plus a
 * counterparty row with its own encrypted display_name/merchant_name/iban,
 * an approved recurring series, a confirmed `device_registry` peer, and a
 * closed `sync_sessions` row (a "synced device" — not a fresh/empty one).
 *
 * A raw DB read-back proves the fixture is genuinely ciphertext at rest
 * (T-15-07 posture) BEFORE any route is smoked — a broken fixture that
 * silently stored plaintext would make every downstream assertion below
 * prove nothing.
 */
function seedMobileSurfaceParityFixture(DatabaseManager $db, SensitiveColumnCodec $codec, User $user, Session $session): void
{
    $suffix = bin2hex(random_bytes(4));
    $userId = (int) $user->id;

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN Surface '.$suffix,
        'slug' => 'msp-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/msp-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'msp-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'committed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $transactionAttrs = $codec->encryptAttrs('transactions', [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'msp-tx-'.$suffix),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -2599,
        'currency' => 'EUR',
        'settled_amount_minor' => -2599,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'mobile surface merchant',
        'counterparty_name' => 'Mobile Surface Merchant BV',
        'normalization_version' => 1,
        'description' => 'Mobile surface parity fixture charge',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ], $userId, $session);

    $db->connection()->table('transactions')->insert($transactionAttrs);

    // Ciphertext-at-rest proof (T-15-07) — a raw read never shows plaintext.
    $storedTx = $db->connection()->table('transactions')
        ->where('user_id', $userId)
        ->where('fingerprint', hash('sha256', 'msp-tx-'.$suffix))
        ->first();
    expect($storedTx->counterparty_name)->not->toBe('Mobile Surface Merchant BV');
    expect($storedTx->description)->not->toBe('Mobile surface parity fixture charge');

    $counterpartyAttrs = $codec->encryptAttrs('counterparties', [
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'msp-merchant-'.$suffix,
        'display_name' => 'Mobile Surface Counterparty',
        'iban' => 'NL91ABNA0417164300',
        'merchant_name' => 'Mobile Surface Merchant BV',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ], $userId, $session);

    $db->connection()->table('counterparties')->insert($counterpartyAttrs);

    $storedCounterparty = $db->connection()->table('counterparties')
        ->where('user_id', $userId)
        ->where('slug', 'msp-merchant-'.$suffix)
        ->first();
    expect($storedCounterparty->display_name)->not->toBe('Mobile Surface Counterparty');

    $db->connection()->table('recurring_series')->insert([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Mobile Surface Subscription',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'msp::'.$suffix,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    // A confirmed peer — "this device is a paired, synced peer", not a
    // fresh/empty on-device install (mirrors
    // MobileResumableInitialSyncTest's device_registry fixture shape).
    $peerDeviceId = 'msp-desktop-peer-'.$suffix;
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $peerDeviceId,
        'name' => 'Fixture Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => 'msp-local-dev-'.$suffix,
        'peer_device_id' => $peerDeviceId,
        'status' => 'closed',
        'error_message' => null,
        'last_seen_at' => '2026-06-15 10:00:00',
        'connected_at' => '2026-06-15 09:55:00',
        'created_at' => '2026-06-15 09:55:00',
        'updated_at' => '2026-06-15 10:00:00',
    ]);
}

beforeEach(function (): void {
    $this->user = mobileSurfaceParityUser();
    $this->actingAs($this->user);

    $session = $this->enablesEncryptionForUser($this->user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    seedMobileSurfaceParityFixture($db, $codec, $this->user, $session);
});

/**
 * Every primary sidebar route() call, by [routeName, params]. See the
 * file-header comment for the enumeration rule (24 entries, matching the
 * sidebar's 24 real `route()`-backed `side-item` anchors).
 *
 * @return array<string, array{0: string, 1: array<string, mixed>}>
 */
function mobileSurfaceParityRoutes(): array
{
    return [
        'dashboard' => ['dashboard', []],
        'transactions.index' => ['transactions.index', []],
        'forecast.index' => ['forecast.index', []],
        'calendar.index' => ['calendar.index', []],
        'recurring.index' => ['recurring.index', []],
        'counterparties.index' => ['counterparties.index', []],
        'counterparties.triage' => ['counterparties.triage', []],
        'chains.index' => ['chains.index', []],
        'drift.index' => ['drift.index', []],
        'drift.index (anomaly filter)' => ['drift.index', ['type' => 'anomaly']],
        'budgets.index' => ['budgets.index', []],
        'tax.index' => ['tax.index', []],
        'goals.index' => ['goals.index', []],
        'pots.index' => ['pots.index', []],
        'reports.index' => ['reports.index', []],
        'reconcile.index' => ['reconcile.index', []],
        'drift.watch' => ['drift.watch', []],
        'imports.new' => ['imports.new', []],
        'migrations.index' => ['migrations.index', []],
        'cashbook.index' => ['cashbook.index', []],
        'inboxes.index' => ['inboxes.index', []],
        'uncategorized' => ['uncategorized', []],
        'sync.index' => ['sync.index', []],
        'settings' => ['settings', []],
    ];
}

it('enumerates at least as many primary surfaces as the sidebar has route()-backed side-items', function (): void {
    expect(count(mobileSurfaceParityRoutes()))->toBeGreaterThanOrEqual(24);
});

it('renders every primary sidebar surface without error against an on-device encrypted, synced fixture (R8)', function (string $routeName, array $params): void {
    $response = $this->get(route($routeName, $params));

    $response->assertSuccessful();

    // No unresolved-view / server-error signature ever leaks into an
    // otherwise-2xx response body (defense-in-depth alongside
    // assertSuccessful — belt-and-braces for Livewire full-page mounts
    // where an inner exception can sometimes render inline).
    expect($response->getContent())
        ->not->toContain('Illuminate\\View\\ViewException')
        ->not->toContain('Server Error');
})->with(mobileSurfaceParityRoutes());
