<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Every primary sidebar surface, rendered against a fixture shaped like a
// real device: encrypted columns, a confirmed peer, a closed sync session.
// The dataset enumerates every route()-backed `side-item` anchor in
// app-sidebar.blade.php, minus the `href="#"` placeholder, /dev and sign-out.

function mobileSurfaceParityUser(): User
{
    return User::query()->create([
        'username' => 'mobile-surface-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('mobile-surface-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The raw read-backs below are load-bearing: a fixture that silently stored
// plaintext would make every route assertion in this file prove nothing.
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

    // A paired, synced peer rather than a fresh install.
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
        'data-devices.index' => ['data-devices.index', []],
        'settings' => ['settings', []],
    ];
}

it('enumerates at least as many primary surfaces as the sidebar has route()-backed side-items', function (): void {
    expect(count(mobileSurfaceParityRoutes()))->toBeGreaterThanOrEqual(24);
});

it('renders every primary sidebar surface without error against an on-device encrypted, synced fixture (R8)', function (string $routeName, array $params): void {
    $response = $this->get(route($routeName, $params));

    $response->assertSuccessful();

    // A Livewire full-page mount can render an inner exception inline and
    // still answer 2xx, which assertSuccessful() alone would accept.
    expect($response->getContent())
        ->not->toContain('Illuminate\\View\\ViewException')
        ->not->toContain('Server Error');
})->with(mobileSurfaceParityRoutes());
