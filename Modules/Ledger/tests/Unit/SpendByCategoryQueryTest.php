<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'spend-by-category',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'sbc-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456780',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/sbc.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'sbc-groceries', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'sbc-household', 'kind' => 'expense', 'display_order' => 2]);

    $this->period = new Period(
        start: CarbonImmutable::parse('2026-07-01'),
        endExclusive: CarbonImmutable::parse('2026-08-01'),
        label: 'July 2026',
    );
});

function spendCatTx(int $userId, int $accountId, int $runId, int $settledMinor, ?int $categoryId, string $currency = 'EUR', ?string $type = null): Transaction
{
    static $i = 200000;
    $i++;
    $postedAt = '2026-07-'.sprintf('%02d', min(28, ($i % 28) + 1));

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $type ?? ($settledMinor < 0 ? 'expense' : 'income'),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => $currency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $currency,
        'counterparty_name' => "SBC{$i}",
        'counterparty_normalized' => "sbc{$i}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    return $tx;
}

function spendCatLeg(Transaction $transaction, int $categoryId, int $settledMinor, ?int $userId = null): TransactionSplit
{
    return TransactionSplit::create([
        'user_id' => $userId,
        'transaction_id' => $transaction->id,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $transaction->settled_currency,
        'note' => null,
        'sort_order' => 0,
    ]);
}

it('counts split legs, not the parent, for a split transaction', function (): void {
    $tx = spendCatTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);
    spendCatLeg($tx, $this->groceries->id, -6000);
    spendCatLeg($tx, $this->household->id, -2000);

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR');

    expect($result[$this->groceries->id])->toBe(6000);
    expect($result[$this->household->id])->toBe(2000);
    expect(array_sum($result))->toBe(8000); // exactly the parent's total, never 16000
});

it('rolls up a plain unsplit transaction via its own category as before', function (): void {
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -5000, $this->groceries->id);

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR');

    expect($result[$this->groceries->id])->toBe(5000);
});

it('combines legs and unsplit parents in the same category without double-counting', function (): void {
    $split = spendCatTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);
    spendCatLeg($split, $this->groceries->id, -6000);
    spendCatLeg($split, $this->household->id, -2000);

    spendCatTx($this->user->id, $this->account->id, $this->run->id, -5000, $this->groceries->id);

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR');

    expect($result[$this->groceries->id])->toBe(11000);
    expect($result[$this->household->id])->toBe(2000);
    expect(array_sum($result))->toBe(13000); // = 8000 + 5000, never doubled
});

it('ignores a split parent vestigial category_id entirely — leg presence is the only signal', function (): void {
    $tx = spendCatTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);
    spendCatLeg($tx, $this->groceries->id, -3000);
    spendCatLeg($tx, $this->household->id, -5000);

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR');

    expect($result[$this->groceries->id])->toBe(3000); // only the leg, not leg + parent
    expect($result[$this->household->id])->toBe(5000);
    expect(array_sum($result))->toBe(8000);
});

it('keys forUserAndPeriodByCurrency by "categoryId|currency" across currencies', function (): void {
    $split = spendCatTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);
    spendCatLeg($split, $this->groceries->id, -6000);
    spendCatLeg($split, $this->household->id, -2000);

    spendCatTx($this->user->id, $this->account->id, $this->run->id, -1500, $this->groceries->id, 'USD');

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency($this->user->id, $this->period);

    expect($result[$this->groceries->id.'|EUR'])->toBe(6000);
    expect($result[$this->household->id.'|EUR'])->toBe(2000);
    expect($result[$this->groceries->id.'|USD'])->toBe(1500);
});

it('falls back to the parent category when legs do not sum to the parent', function (): void {
    // The legs sum to −6000 against a −8000 parent. A per-leg LWW replay where
    // one leg's delete op won leaves exactly this shape.
    $tx = spendCatTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);
    spendCatLeg($tx, $this->household->id, -6000);

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR');

    // Fail safe to pre-split behaviour: the parent's own amount, leg ignored.
    expect($result[$this->groceries->id])->toBe(8000);
    expect($result)->not->toHaveKey($this->household->id);
    expect(array_sum($result))->toBe(8000);
});

it('falls back to the parent category for a non-summing split in forUserAndPeriodByCurrency too', function (): void {
    $tx = spendCatTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);
    spendCatLeg($tx, $this->household->id, -6000);

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency($this->user->id, $this->period);

    expect($result[$this->groceries->id.'|EUR'])->toBe(8000);
    expect($result)->not->toHaveKey($this->household->id.'|EUR');
});

it('surfaces uncategorized unsplit spend under id 0 only when includeUncategorized is true', function (): void {
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -1000, null);

    $excluded = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR');
    expect($excluded)->not->toHaveKey(0);

    $included = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR', includeUncategorized: true);
    expect($included[0])->toBe(1000);
});

// Spend is `type = expense`, the definition Reports and the dashboard's OUT
// tile both hold. Selecting on the amount's sign instead let a transfer to the
// reader's own card sit in "Top spending" as EUR325.00, and made "This month
// vs last" read EUR2,818.11 against the EUR2,459.11 the OUT tile on the same
// page reported for the same month.
it('leaves an internal transfer out of category spend', function (): void {
    $transfers = Modules\Ledger\Models\Category::create(['user_id' => null, 'name' => 'Transfers (internal)', 'slug' => 'sbc-transfers', 'kind' => 'transfer', 'display_order' => 3]);
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -5000, $this->groceries->id);
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -22500, $transfers->id, 'EUR', 'transfer_out');

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR', includeUncategorized: true);

    expect($result)->not->toHaveKey($transfers->id)
        ->and(array_sum($result))->toBe(5000);
});

it('leaves a fee and an adjustment out of category spend', function (): void {
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -5000, $this->groceries->id);
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -150, null, 'EUR', 'fee');
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -750, null, 'EUR', 'adjustment');

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriod($this->user->id, $this->period, 'EUR', includeUncategorized: true);

    expect(array_sum($result))->toBe(5000);
});

it('leaves an internal transfer out of the per-currency map too', function (): void {
    $transfers = Modules\Ledger\Models\Category::create(['user_id' => null, 'name' => 'Transfers (internal)', 'slug' => 'sbc-transfers-cur', 'kind' => 'transfer', 'display_order' => 4]);
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -5000, $this->groceries->id);
    spendCatTx($this->user->id, $this->account->id, $this->run->id, -22500, $transfers->id, 'EUR', 'transfer_out');

    $result = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency($this->user->id, $this->period);

    expect($result)->not->toHaveKey($transfers->id.'|EUR')
        ->and($result[$this->groceries->id.'|EUR'])->toBe(5000);
});
