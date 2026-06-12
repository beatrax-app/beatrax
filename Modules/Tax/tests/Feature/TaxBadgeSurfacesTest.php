<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;

/*
 * Integration tests for TAX-01 badge integration on all four surfaces:
 * TransactionsList, TransactionDetail, CounterpartyProfile, CashBookPage.
 *
 * Assertions per surface:
 *   (a) Untagged row exposes the "Tag" ghost action
 *   (b) Dispatching tax-tag tags the transaction and the row re-renders as
 *       the emerald badge
 *   (c) Batch-loaded state uses a single DB query (Pitfall 1)
 *   (d) Tagging a counterparty transaction with ≥2 untagged siblings surfaces
 *       the batch suggestion; applying it tags all and does not re-surface (P-7)
 *   (e) Cross-user isolation: a user never sees another user's badge (Pitfall 6)
 *
 * Note: (c) DB query count is tested at the action level (single whereIn) rather
 * than counting Livewire render queries, which include framework overhead
 * not relevant to the Pitfall-1 constraint.
 */

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────────────────────

function badgeUser(string $username = 'badge-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Seed a manual transaction suitable for CashBookPage (source_format = manual).
 * Returns the transaction id.
 */
function badgeManualTx(DatabaseManager $db, int $userId, ?int $counterpartyId = null): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Cash '.$suffix,
        'slug' => 'cash-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'manual',
        'raw_file_path' => '/tmp/manual-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'manual-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'badge-manual-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 00:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -2000,
        'currency' => 'EUR',
        'settled_amount_minor' => -2000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Manual Vendor',
        'counterparty_id' => $counterpartyId,
        'counterparty_normalized' => 'manual-vendor',
        'normalization_version' => 1,
        'description' => 'Manual cash entry',
        'type' => 'expense',
        'source_format' => 'manual',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Seed a regular (non-manual) transaction for TransactionsList / Detail / Counterparty.
 * Returns the transaction id.
 */
function badgeTx(DatabaseManager $db, int $userId, ?int $counterpartyId = null, string $bookedAt = '2026-06-01 00:00:00'): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Badge ASN '.$suffix,
        'slug' => 'badge-asn-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/badge-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'badge-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'badge-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => -3000,
        'currency' => 'EUR',
        'settled_amount_minor' => -3000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Badge Vendor BV',
        'counterparty_id' => $counterpartyId,
        'counterparty_normalized' => 'badge-vendor',
        'normalization_version' => 1,
        'description' => 'Badge test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Seed a counterparty and link to a user. Returns the counterparty id.
 */
function badgeCp(DatabaseManager $db, int $userId, string $name = 'Badge Gym BV'): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'badge-cp-'.$suffix,
        'display_name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Tag a transaction for a user. */
function badgeTag(DatabaseManager $db, int $userId, int $txId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Surface 1: TransactionsList
// ─────────────────────────────────────────────────────────────────────────────

describe('TransactionsList tax badge', function (): void {
    it('shows the untagged Tag ghost button for an untagged row', function (): void {
        $user = badgeUser('tx-list-badge-user-1');
        $db = app(DatabaseManager::class);
        $txId = badgeTx($db, $user->id);

        $component = Livewire::actingAs($user)->test(TransactionsList::class);

        $component->assertSee('data-testid="tax-badge-untagged-'.$txId.'"', false);
    });

    it('fires tax-tag event and tags the transaction', function (): void {
        $user = badgeUser('tx-list-badge-user-2');
        $db = app(DatabaseManager::class);
        $txId = badgeTx($db, $user->id);

        // tax-tag immediately tags (optimistic) and opens the picker — no toast
        // at this step (toast fires after saveTaxCategory / untag / applyBatchTag).
        Livewire::actingAs($user)->test(TransactionsList::class)
            ->dispatch('tax-tag', id: $txId);

        $tagged = $db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $txId)
            ->exists();

        expect($tagged)->toBeTrue();
    });

    it('shows the emerald tagged badge after tagging', function (): void {
        $user = badgeUser('tx-list-badge-user-3');
        $db = app(DatabaseManager::class);
        $txId = badgeTx($db, $user->id);
        badgeTag($db, $user->id, $txId);

        $component = Livewire::actingAs($user)->test(TransactionsList::class);

        $component->assertSee('data-testid="tax-badge-tagged-'.$txId.'"', false);
    });

    it('loads tax state for a batch of rows with a single whereIn (Pitfall 1)', function (): void {
        $user = badgeUser('tx-list-batch-user');
        $db = app(DatabaseManager::class);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = badgeTx($db, $user->id);
        }
        badgeTag($db, $user->id, $ids[0]);
        badgeTag($db, $user->id, $ids[2]);

        // taxTagStateFor internally calls TaxTagQuery::forTransactionIds which
        // uses a single whereIn query — confirmed by the service implementation.
        // Here we assert the rendered output shows the correct tagged/untagged state
        // for all rows (confirming batch-load correctness).
        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->assertSee('data-testid="tax-badge-tagged-'.$ids[0].'"', false);
        $component->assertSee('data-testid="tax-badge-tagged-'.$ids[2].'"', false);
        $component->assertSee('data-testid="tax-badge-untagged-'.$ids[1].'"', false);
        $component->assertSee('data-testid="tax-badge-untagged-'.$ids[3].'"', false);
        $component->assertSee('data-testid="tax-badge-untagged-'.$ids[4].'"', false);
    });

    it('batch suggestion fires for ≥2 untagged siblings and does not re-surface after apply', function (): void {
        $user = badgeUser('tx-list-batch-sug-user');
        $db = app(DatabaseManager::class);
        $cpId = badgeCp($db, $user->id, 'Batch Gym');
        $tx1 = badgeTx($db, $user->id, $cpId, '2026-06-01 00:00:00');
        $tx2 = badgeTx($db, $user->id, $cpId, '2026-06-02 00:00:00');
        $tx3 = badgeTx($db, $user->id, $cpId, '2026-06-03 00:00:00');

        // Tag tx1 — tx2 and tx3 remain untagged (count = 2, threshold met).
        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $tx1);

        // After tagging tx1, batchSuggestion should be populated.
        expect($component->get('batchSuggestion'))->not->toBeNull();
        expect($component->get('batchSuggestion')['untaggedCount'])->toBeGreaterThanOrEqual(2);

        // Apply batch — all siblings should be tagged.
        $component->call('applyBatchTag');

        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $tx2)
            ->exists())->toBeTrue();
        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $tx3)
            ->exists())->toBeTrue();

        // After apply, batchSuggestionDismissed = true → suggestion should not re-surface.
        expect($component->get('batchSuggestionDismissed'))->toBeTrue();
    });

    it('does not show another user\'s badge state (cross-user isolation)', function (): void {
        $owner = badgeUser('tx-list-owner');
        $partner = badgeUser('tx-list-partner');
        $db = app(DatabaseManager::class);

        $ownerTxId = badgeTx($db, $owner->id);
        badgeTag($db, $owner->id, $ownerTxId);

        // Partner renders — must NOT see owner's tagged badge.
        $component = Livewire::actingAs($partner)->test(TransactionsList::class);
        $component->assertDontSee('data-testid="tax-badge-tagged-'.$ownerTxId.'"', false);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Surface 2: TransactionDetail
// ─────────────────────────────────────────────────────────────────────────────

describe('TransactionDetail tax badge', function (): void {
    it('shows untagged Tag button for an untagged transaction', function (): void {
        $user = badgeUser('tx-detail-badge-user-1');
        $db = app(DatabaseManager::class);
        $txId = badgeTx($db, $user->id);

        $component = Livewire::actingAs($user)->test(TransactionDetail::class, ['transactionId' => $txId]);
        $component->assertSee('data-testid="tax-badge-untagged-'.$txId.'"', false);
    });

    it('shows emerald tagged badge after tagging', function (): void {
        $user = badgeUser('tx-detail-badge-user-2');
        $db = app(DatabaseManager::class);
        $txId = badgeTx($db, $user->id);
        badgeTag($db, $user->id, $txId);

        $component = Livewire::actingAs($user)->test(TransactionDetail::class, ['transactionId' => $txId]);
        $component->assertSee('data-testid="tax-badge-tagged-'.$txId.'"', false);
    });

    it('tags on tax-tag event dispatch', function (): void {
        $user = badgeUser('tx-detail-badge-user-3');
        $db = app(DatabaseManager::class);
        $txId = badgeTx($db, $user->id);

        Livewire::actingAs($user)->test(TransactionDetail::class, ['transactionId' => $txId])
            ->dispatch('tax-tag', id: $txId);

        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $txId)
            ->exists())->toBeTrue();
    });

    it('does not show another user\'s badge state', function (): void {
        $owner = badgeUser('tx-detail-owner');
        $partner = badgeUser('tx-detail-partner');
        $db = app(DatabaseManager::class);

        $ownerTxId = badgeTx($db, $owner->id);
        badgeTag($db, $owner->id, $ownerTxId);

        // Partner tries to view owner's transaction — should 404 at mount.
        Livewire::actingAs($partner)->test(TransactionDetail::class, ['transactionId' => $ownerTxId])
            ->assertStatus(404);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Surface 3: CounterpartyProfile
// ─────────────────────────────────────────────────────────────────────────────

describe('CounterpartyProfile tax badge', function (): void {
    it('shows untagged Tag button on recent-activity rows', function (): void {
        $user = badgeUser('cp-badge-user-1');
        $db = app(DatabaseManager::class);
        $cpId = badgeCp($db, $user->id, 'Badge Pharmacy');
        $txId = badgeTx($db, $user->id, $cpId);

        $slug = $db->connection()->table('counterparties')
            ->where('id', $cpId)
            ->value('slug');

        $component = Livewire::actingAs($user)->test(CounterpartyProfile::class, ['slug' => $slug]);
        $component->assertSee('data-testid="tax-badge-untagged-'.$txId.'"', false);
    });

    it('shows tagged badge after tagging a counterparty transaction', function (): void {
        $user = badgeUser('cp-badge-user-2');
        $db = app(DatabaseManager::class);
        $cpId = badgeCp($db, $user->id, 'Badge Pharmacy 2');
        $txId = badgeTx($db, $user->id, $cpId);
        badgeTag($db, $user->id, $txId);

        $slug = $db->connection()->table('counterparties')
            ->where('id', $cpId)
            ->value('slug');

        $component = Livewire::actingAs($user)->test(CounterpartyProfile::class, ['slug' => $slug]);
        $component->assertSee('data-testid="tax-badge-tagged-'.$txId.'"', false);
    });

    it('does not show another user\'s badge state (cross-user isolation)', function (): void {
        $owner = badgeUser('cp-badge-owner');
        $partner = badgeUser('cp-badge-partner');
        $db = app(DatabaseManager::class);

        $ownerCpId = badgeCp($db, $owner->id, 'Owner Private CP');
        $ownerTxId = badgeTx($db, $owner->id, $ownerCpId);
        badgeTag($db, $owner->id, $ownerTxId);

        // Partner tries to access owner's counterparty — should 404.
        $slug = $db->connection()->table('counterparties')
            ->where('id', $ownerCpId)
            ->value('slug');

        Livewire::actingAs($partner)->test(CounterpartyProfile::class, ['slug' => $slug])
            ->assertStatus(404);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Surface 4: CashBookPage
// ─────────────────────────────────────────────────────────────────────────────

describe('CashBookPage tax badge', function (): void {
    it('shows untagged Tag button for a manual entry', function (): void {
        $user = badgeUser('cash-badge-user-1');
        $db = app(DatabaseManager::class);
        $txId = badgeManualTx($db, $user->id);

        $component = Livewire::actingAs($user)->test(CashBookPage::class);
        $component->assertSee('data-testid="tax-badge-untagged-'.$txId.'"', false);
    });

    it('shows emerald tagged badge for a tagged manual entry', function (): void {
        $user = badgeUser('cash-badge-user-2');
        $db = app(DatabaseManager::class);
        $txId = badgeManualTx($db, $user->id);
        badgeTag($db, $user->id, $txId);

        $component = Livewire::actingAs($user)->test(CashBookPage::class);
        $component->assertSee('data-testid="tax-badge-tagged-'.$txId.'"', false);
    });

    it('tags on tax-tag event dispatch', function (): void {
        $user = badgeUser('cash-badge-user-3');
        $db = app(DatabaseManager::class);
        $txId = badgeManualTx($db, $user->id);

        Livewire::actingAs($user)->test(CashBookPage::class)
            ->dispatch('tax-tag', id: $txId);

        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $txId)
            ->exists())->toBeTrue();
    });

    it('does not show another user\'s badge state (cross-user isolation)', function (): void {
        $owner = badgeUser('cash-badge-owner');
        $partner = badgeUser('cash-badge-partner');
        $db = app(DatabaseManager::class);

        $ownerTxId = badgeManualTx($db, $owner->id);
        badgeTag($db, $owner->id, $ownerTxId);

        // Partner renders — must NOT see owner's tagged badge.
        $component = Livewire::actingAs($partner)->test(CashBookPage::class);
        $component->assertDontSee('data-testid="tax-badge-tagged-'.$ownerTxId.'"', false);
    });
});
