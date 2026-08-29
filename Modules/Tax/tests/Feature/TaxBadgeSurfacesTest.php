<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;

uses(RefreshDatabase::class);

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

function badgeManualTx(DatabaseManager $db, int $userId, ?int $counterpartyId = null): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Cash '.$suffix,
        'slug' => 'cash-'.$suffix,
        'kind' => 'bank',
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

function badgeTx(DatabaseManager $db, int $userId, ?int $counterpartyId = null, string $bookedAt = '2026-06-01 00:00:00'): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Badge ASN '.$suffix,
        'slug' => 'badge-asn-'.$suffix,
        'kind' => 'bank',
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

/**
 * @return list<int>
 */
function badgeTxBatch(DatabaseManager $db, int $userId, int $count): array
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Batch ASN '.$suffix,
        'slug' => 'batch-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/batch-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'batch-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        // Newest first: i=0 is the most recent posted_at (top of page 1).
        $date = now()->subDays($i)->format('Y-m-d');
        $ids[] = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'batch-tx-'.$i.'-'.bin2hex(random_bytes(8))),
            'posted_at' => $date,
            'booked_at' => $date.' 00:00:00',
            'value_date' => $date,
            'amount_minor' => -1000 - $i,
            'currency' => 'EUR',
            'settled_amount_minor' => -1000 - $i,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Batch Vendor '.$i,
            'counterparty_id' => null,
            'counterparty_normalized' => 'batch-vendor-'.$i,
            'normalization_version' => 1,
            'description' => 'Batch tx '.$i,
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => $i + 1,
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $ids;
}

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

        // tax-tag tags optimistically; the toast waits for save / untag / batch.
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

    it('loads tax state for a batch of rows with a single whereIn', function (): void {
        $user = badgeUser('tx-list-batch-user');
        $db = app(DatabaseManager::class);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = badgeTx($db, $user->id);
        }
        badgeTag($db, $user->id, $ids[0]);
        badgeTag($db, $user->id, $ids[2]);

        // The single-whereIn claim is proven at the service level; this only
        // asserts each row renders the right state after the batch load.
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

        // Frozen to match the June-2026 fixtures: the current tax year is
        // seasonal, so a real calendar would rot this test.
        $clock = Mockery::mock(Clock::class);
        $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 6, 15));
        app()->instance(Clock::class, $clock);

        $cpId = badgeCp($db, $user->id, 'Batch Gym');
        $tx1 = badgeTx($db, $user->id, $cpId, '2026-06-01 00:00:00');
        $tx2 = badgeTx($db, $user->id, $cpId, '2026-06-02 00:00:00');
        $tx3 = badgeTx($db, $user->id, $cpId, '2026-06-03 00:00:00');

        // Tag tx1 — tx2 and tx3 remain untagged (count = 2, threshold met).
        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $tx1);

        expect($component->get('batchSuggestion'))->not->toBeNull();
        expect($component->get('batchSuggestion')['untaggedCount'])->toBeGreaterThanOrEqual(2);

        $component->call('applyBatchTag');

        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $tx2)
            ->exists())->toBeTrue();
        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $tx3)
            ->exists())->toBeTrue();

        expect($component->get('batchSuggestionDismissed'))->toBeTrue();
    });

    it('applyBatchTag applies the SAME category and note as the saved trigger tag', function (): void {
        $user = badgeUser('tx-list-batch-cat-user');
        $db = app(DatabaseManager::class);

        // Frozen to match the June-2026 fixtures.
        $clock = Mockery::mock(Clock::class);
        $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 6, 15));
        app()->instance(Clock::class, $clock);

        $cpId = badgeCp($db, $user->id, 'Batch Insurer');
        $tx1 = badgeTx($db, $user->id, $cpId, '2026-06-01 00:00:00');
        $tx2 = badgeTx($db, $user->id, $cpId, '2026-06-02 00:00:00');
        $tx3 = badgeTx($db, $user->id, $cpId, '2026-06-03 00:00:00');

        $catId = (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Zorgkosten',
            'short_name' => 'Zorg',
            'country_code' => 'nl',
            'corpus_key' => 'nl.zorgkosten',
            'status' => 'active',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tag, then pick a category and note and save: saving runs closePicker(),
        // which wipes the picker fields before the banner can be clicked.
        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $tx1);
        $component->set('pickerCategoryId', $catId);
        $component->set('pickerNote', 'trigger note');
        $component->call('saveTaxCategory');

        $component->call('applyBatchTag');

        foreach ([$tx2, $tx3] as $sibling) {
            $row = $db->connection()->table('tax_transaction_tags')
                ->where('user_id', $user->id)
                ->where('transaction_id', $sibling)
                ->first(['deduction_category_id', 'note']);

            expect($row)->not->toBeNull();
            expect((int) $row->deduction_category_id)->toBe($catId);
            expect($row->note)->toBe('trigger note');
        }
    });

    it('keeps tax state on previously-accumulated phone rows after loadMore', function (): void {
        $user = badgeUser('tx-list-loadmore-user');
        $db = app(DatabaseManager::class);

        // 51 rows → page 1 holds 50, loadMore() fetches the 51st.
        $ids = badgeTxBatch($db, $user->id, 51);
        $page1TxId = $ids[0]; // Newest row — guaranteed on page 1.

        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $page1TxId);
        $component->call('closePicker');
        $component->assertSee('data-testid="tax-badge-tagged-'.$page1TxId.'"', false);

        // loadMore advances to page 2 — the page-1 row only remains in the
        // accumulated phone list. Its tagged state must NOT reset to the
        // untagged ghost (which would enable a destructive stale re-tag).
        $component->call('loadMore');

        $component->assertSee('data-testid="tax-badge-tagged-'.$page1TxId.'"', false);
        $component->assertDontSee('data-testid="tax-badge-untagged-'.$page1TxId.'"', false);
    });

    it('renders the year-override row when the booked year differs from the tax year, and persists the override', function (): void {
        $user = badgeUser('tx-list-year-override-user');
        $db = app(DatabaseManager::class);

        // Freeze the clock: June 2026 → current tax year = 2026.
        $clock = Mockery::mock(Clock::class);
        $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 6, 15));
        app()->instance(Clock::class, $clock);

        // Row booked in a DIFFERENT year (2024) than the current tax year (2026).
        $txId = badgeTx($db, $user->id, null, '2024-03-10 00:00:00');

        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $txId);

        expect($component->get('pickerBookedYear'))->toBe(2024);
        expect($component->get('pickerTaxYear'))->toBe(2026);
        $component->assertSee('Assign to tax year');

        $component->set('pickerYearOverride', 2026);
        $component->call('saveTaxCategory');

        $override = $db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $txId)
            ->value('tax_year_override');

        expect((int) $override)->toBe(2026);
    });

    it('does not render the year-override row when booked year equals the tax year', function (): void {
        $user = badgeUser('tx-list-same-year-user');
        $db = app(DatabaseManager::class);

        $clock = Mockery::mock(Clock::class);
        $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 6, 15));
        app()->instance(Clock::class, $clock);

        $txId = badgeTx($db, $user->id, null, '2026-06-01 00:00:00');

        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $txId);

        expect($component->get('pickerBookedYear'))->toBe(2026);
        $component->assertDontSee('Assign to tax year');
    });

    it('applyBatchTag honours a snapshotted "No category" — it never falls through to live picker state from another row', function (): void {
        $user = badgeUser('tx-list-batch-nullcat-user');
        $db = app(DatabaseManager::class);
        $cpId = badgeCp($db, $user->id, 'Batch NoCat Gym');
        $tx1 = badgeTx($db, $user->id, $cpId, '2026-06-01 00:00:00');
        $tx2 = badgeTx($db, $user->id, $cpId, '2026-06-02 00:00:00');
        $tx3 = badgeTx($db, $user->id, $cpId, '2026-06-03 00:00:00');
        // Unrelated, already-tagged row from a different counterparty —
        // editing it opens the picker WITHOUT recomputing the suggestion.
        $txOther = badgeTx($db, $user->id, null, '2026-06-04 00:00:00');
        badgeTag($db, $user->id, $txOther);

        $catId = (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Unrelated Cat',
            'short_name' => 'Unrel',
            'status' => 'active',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Save the trigger tag with NO category (snapshot stores categoryId=null).
        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $tx1);
        $component->call('saveTaxCategory');

        // Pollute the live picker state via an unrelated row before applying.
        $component->dispatch('tax-edit-tag', id: $txOther);
        $component->set('pickerCategoryId', $catId);
        $component->set('pickerNote', 'unrelated note');

        $component->call('applyBatchTag');

        foreach ([$tx2, $tx3] as $sibling) {
            $row = $db->connection()->table('tax_transaction_tags')
                ->where('user_id', $user->id)
                ->where('transaction_id', $sibling)
                ->first(['deduction_category_id', 'note']);

            expect($row)->not->toBeNull();
            expect($row->deduction_category_id)->toBeNull();
            expect($row->note)->toBeNull();
        }
    });

    it('batch suggestion is keyed to the trigger transaction\'s booked year, not the seasonal current year', function (): void {
        $user = badgeUser('tx-list-batch-year-user');
        $db = app(DatabaseManager::class);

        // Wall clock says June 2026 → seasonal tax year would be 2026.
        $clock = Mockery::mock(Clock::class);
        $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 6, 15));
        app()->instance(Clock::class, $clock);

        $cpId = badgeCp($db, $user->id, 'Old History Gym');
        // Trigger + 2 siblings booked in 2024; 1 sibling booked in 2026.
        $trigger = badgeTx($db, $user->id, $cpId, '2024-02-01 00:00:00');
        $sib2024a = badgeTx($db, $user->id, $cpId, '2024-03-01 00:00:00');
        $sib2024b = badgeTx($db, $user->id, $cpId, '2024-04-01 00:00:00');
        $sib2026 = badgeTx($db, $user->id, $cpId, '2026-05-01 00:00:00');

        $component = Livewire::actingAs($user)->test(TransactionsList::class);
        $component->dispatch('tax-tag', id: $trigger);

        $suggestion = $component->get('batchSuggestion');
        expect($suggestion)->not->toBeNull();
        expect($suggestion['taxYear'])->toBe(2024);
        expect($suggestion['untaggedCount'])->toBe(2);

        $component->call('applyBatchTag');

        foreach ([$sib2024a, $sib2024b] as $sibling) {
            expect($db->connection()->table('tax_transaction_tags')
                ->where('user_id', $user->id)
                ->where('transaction_id', $sibling)
                ->exists())->toBeTrue();
        }
        expect($db->connection()->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $sib2026)
            ->exists())->toBeFalse();
    });

    it('opening the picker for another row resets note/category/year-override — no state bleed', function (): void {
        $user = badgeUser('tx-list-state-bleed-user');
        $db = app(DatabaseManager::class);
        $txA = badgeTx($db, $user->id);
        $txB = badgeTx($db, $user->id);
        badgeTag($db, $user->id, $txA);

        $component = Livewire::actingAs($user)->test(TransactionsList::class);

        // Edit row A and type values WITHOUT saving.
        $component->dispatch('tax-edit-tag', id: $txA);
        $component->set('pickerNote', 'row A note');
        $component->set('pickerYearOverride', 2025);

        // Ghost-tag row B (no closePicker in between) — fields must be clean.
        $component->dispatch('tax-tag', id: $txB);

        expect($component->get('taxPickerTxId'))->toBe($txB);
        expect($component->get('pickerNote'))->toBe('');
        expect($component->get('pickerCategoryId'))->toBeNull();
        expect($component->get('pickerYearOverride'))->toBeNull();
    });

    it('does not show another user\'s badge state (cross-user isolation)', function (): void {
        $owner = badgeUser('tx-list-owner');
        $partner = badgeUser('tx-list-partner');
        $db = app(DatabaseManager::class);

        $ownerTxId = badgeTx($db, $owner->id);
        badgeTag($db, $owner->id, $ownerTxId);

        $component = Livewire::actingAs($partner)->test(TransactionsList::class);
        $component->assertDontSee('data-testid="tax-badge-tagged-'.$ownerTxId.'"', false);
    });
});

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

        Livewire::actingAs($partner)->test(TransactionDetail::class, ['transactionId' => $ownerTxId])
            ->assertStatus(404);
    });
});

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

        $slug = $db->connection()->table('counterparties')
            ->where('id', $ownerCpId)
            ->value('slug');

        Livewire::actingAs($partner)->test(CounterpartyProfile::class, ['slug' => $slug])
            ->assertStatus(404);
    });
});

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

        $component = Livewire::actingAs($partner)->test(CashBookPage::class);
        $component->assertDontSee('data-testid="tax-badge-tagged-'.$ownerTxId.'"', false);
    });
});

// Tag and Delete sit in the same row on a phone. Delete is an x-core::emoji-action
// and Tag was a hand-rolled 18px pill, so one row carried two shapes for one idea.
it('draws the touch Tag action as the emoji action every sole icon action is', function (): void {
    $html = $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="true" />',
        ['transaction' => ['id' => 7, 'taxTagged' => false, 'taxCategoryShortName' => null]],
    );

    $html->assertSee('emoji-action', false)
        ->assertDontSee('tax-badge--untagged', false);
});

// The phone card's action line is a row of status chips, and the emoji action is
// a 36px bordered square among 20px capsules -- which the coarse-pointer floor
// then took to 44 while the capsules beside it stayed at 20. A control that
// belongs to its neighbours more than to the icon rule follows its neighbours.
it('draws the untagged badge as a chip when its neighbours are chips', function (): void {
    $html = $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="true" :chipRow="true" />',
        ['transaction' => ['id' => 7, 'taxTagged' => false, 'taxCategoryShortName' => null]],
    );

    $html->assertSee('tax-badge--untagged', false)
        ->assertSee('tax-badge--shown', false)
        ->assertDontSee('emoji-action', false);
});

// A chip carries its word, so it is not an icon-only action and the emoji rule
// never reaches it. Without this the branch could quietly become a lone glyph
// again, which is the shape the rule exists to forbid.
it('gives that chip the word rather than a lone glyph', function (): void {
    $html = $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="true" :chipRow="true" />',
        ['transaction' => ['id' => 7, 'taxTagged' => false, 'taxCategoryShortName' => null]],
    );

    // As the chip's own text, not merely somewhere in the markup: the emoji
    // branch's "Tag as tax-relevant" aria-label contains the word too, which
    // makes a plain assertSee pass on the shape this test exists to rule out.
    $html->assertSee('>'.trans('tax::badge.tag').'</button>', false);
});

it('keeps the word on the hover-reveal surface, where there is room for it', function (): void {
    $html = $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="false" />',
        ['transaction' => ['id' => 7, 'taxTagged' => false, 'taxCategoryShortName' => null]],
    );

    $html->assertSee('tax-badge--untagged', false);
});

// The phone picker re-declared .bottom-sheet inline and had drifted three ways
// from it: a layer below the sheet layer, a shorter panel, and a different dark
// surface. The class in app.css is the definition — an inline copy of it is the
// defect, so this asserts the picker consumes the definition.
it('opens the tax picker on the shared bottom sheet rather than an inline copy of it', function (): void {
    $user = badgeUser('sheet-user');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    badgeManualTx($db, (int) $user->id);

    $html = (string) Livewire::actingAs($user)->test(CashBookPage::class)->html();

    // The desktop popover and the phone panel are both dialogs carrying this
    // label; the phone panel is the second, so anchor on the LAST one.
    $labelAt = strrpos($html, 'aria-label="'.Lang::get('tax::picker.dialog_aria').'"');
    expect($labelAt)->not->toBeFalse('the picker dialog must render');

    // Walk back to the opening tag the label sits on.
    $tagAt = strrpos(substr($html, 0, (int) $labelAt), '<div');
    $panelTag = substr($html, (int) $tagAt, (int) $labelAt - (int) $tagAt);

    expect($panelTag)->toContain('class="bottom-sheet"')
        ->and($html)->toContain('class="bottom-sheet-scrim"')
        ->and($html)->not->toContain('max-height: 70vh')
        ->and($html)->not->toContain('rgba(0,0,0,0.4)')
        ->and($html)->not->toContain('border-radius: 12px 12px 0 0');
});
