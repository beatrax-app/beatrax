<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxTagQuery;

// The fixtures here book at an absolute date and TransactionsList queries a
// rolling recent(daysBack: 90) off the real clock, so the pair has an expiry
// date. TaxBadgeSurfacesTest reached its on 2026-08-31; this freezes the clock
// before the same arithmetic reaches this one.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function lsbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function lsbTx(DatabaseManager $db, int $userId, int $accountId, int $runId): int
{
    static $seq = 0;
    $seq++;

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'lsb-tx-'.$seq.'-'.bin2hex(random_bytes(4))),
        // TransactionsList renders TransactionListQuery::recent(daysBack: 90), so
        // an older date would silently drop off the page the badge assertions
        // read.
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'lsb-vendor',
        'counterparty_name' => 'LSB Vendor BV',
        'normalization_version' => 1,
        'description' => 'Leg-scoped badge fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $seq,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lsbSpendCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lsbLeg(DatabaseManager $db, int $userId, int $txId, int $categoryId, int $settledAmountMinor, int $sortOrder = 0): int
{
    return $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lsbDeductionCategory(DatabaseManager $db, int $userId, string $name = 'LSB Deduction'): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->user = lsbUser('leg-badge-fixture');
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-leg-badge-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000010',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/leg-badge-fixture.csv',
        'sha256' => str_pad('a', 64, '0'),
        'uploaded_at' => now(),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'previewed',
    ]);
});

it('forTransactionIds does not surface a leg-only tag as a whole-transaction tag', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $txId = lsbTx($db, $this->user->id, $this->account->id, $this->run->id);
    $groceries = lsbSpendCategory($db, $this->user->id, 'LSB Groceries');
    $legId = lsbLeg($db, $this->user->id, $txId, $groceries, -6000, 0);
    $catId = lsbDeductionCategory($db, $this->user->id);

    app(TagTransaction::class)->execute($this->user->id, $txId, $catId, null, null, $legId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $result = $query->forTransactionIds($this->user->id, [$txId]);

    expect($result)->not->toHaveKey($txId);
});

it('a leg-only-tagged transaction renders the untagged whole-tx badge on the row list, not the tagged pill', function (): void {
    // TransactionsList, not TransactionDetail: the detail page suppresses the
    // whole-tx badge section entirely once a split exists, so the row list is
    // the surface where a leg tag could falsely light the parent badge.
    $db = app(DatabaseManager::class);

    $txId = lsbTx($db, $this->user->id, $this->account->id, $this->run->id);
    $groceries = lsbSpendCategory($db, $this->user->id, 'LSB Groceries 2');
    $household = lsbSpendCategory($db, $this->user->id, 'LSB Household 2');
    lsbLeg($db, $this->user->id, $txId, $household, -2000, 1);
    $legId = lsbLeg($db, $this->user->id, $txId, $groceries, -6000, 0);
    $catId = lsbDeductionCategory($db, $this->user->id, 'LSB Deduction 2');

    app(TagTransaction::class)->execute($this->user->id, $txId, $catId, null, null, $legId);

    $component = Livewire::actingAs($this->user)->test(TransactionsList::class);

    $component->assertSee('data-testid="tax-badge-untagged-'.$txId.'"', false);
    $component->assertDontSee('data-testid="tax-badge-tagged-'.$txId.'"', false);
});

it('untag() on a leg-only-tagged transaction deletes zero rows and leaves the leg tag intact', function (): void {
    $db = app(DatabaseManager::class);

    $txId = lsbTx($db, $this->user->id, $this->account->id, $this->run->id);
    $groceries = lsbSpendCategory($db, $this->user->id, 'LSB Groceries 3');
    $legId = lsbLeg($db, $this->user->id, $txId, $groceries, -6000, 0);
    $catId = lsbDeductionCategory($db, $this->user->id, 'LSB Deduction 3');

    app(TagTransaction::class)->execute($this->user->id, $txId, $catId, null, null, $legId);

    // The path the parent row's "Remove tag" takes; it must match zero rows,
    // because no whole-transaction row exists.
    app(UntagTransaction::class)->execute($this->user->id, $txId);

    $legTagStillExists = DB::table('tax_transaction_tags')
        ->where('user_id', $this->user->id)
        ->where('transaction_id', $txId)
        ->where('transaction_split_id', $legId)
        ->exists();

    expect($legTagStillExists)->toBeTrue();
});

it('a genuine whole-transaction tag still lights the badge and untags successfully (regression)', function (): void {
    $db = app(DatabaseManager::class);

    $txId = lsbTx($db, $this->user->id, $this->account->id, $this->run->id);
    $catId = lsbDeductionCategory($db, $this->user->id, 'LSB Whole Deduction');

    app(TagTransaction::class)->execute($this->user->id, $txId, $catId, null, null);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $result = $query->forTransactionIds($this->user->id, [$txId]);
    expect($result)->toHaveKey($txId);

    app(UntagTransaction::class)->execute($this->user->id, $txId);

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(0);
});
