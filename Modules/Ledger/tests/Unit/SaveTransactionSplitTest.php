<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Sync\Public\Events\TransactionSplitMutated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'household', 'kind' => 'expense', 'display_order' => 2]);
});

function splitTx(int $userId, int $accountId, int $runId, int $settledMinor, string $type = 'expense', ?int $categoryId = null): Transaction
{
    static $i = 100000;
    $i++;
    $today = CarbonImmutable::now()->toDateString();

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $type,
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "M{$i}",
        'counterparty_normalized' => "m{$i}",
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

it('rejects a split whose legs do not sum to the parent settled_amount_minor, inside the DB transaction', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    expect(fn () => app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -1000, 'note' => null],
    ]))->toThrow(SplitSumMismatchException::class);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
});

it('persists a valid split that sums exactly to the parent, re-reading each leg independently', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => 'weekly shop'],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legs = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
    expect($legs)->toHaveCount(2);
    expect((int) $legs->sum('settled_amount_minor'))->toBe(-8000);
    expect($legs[0]->category_id)->toBe($this->groceries->id);
    expect($legs[0]->note)->toBe('weekly shop');
    expect($legs[1]->category_id)->toBe($this->household->id);
    expect($legs[1]->note)->toBeNull();
});

it('rejects a zero-amount leg', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    expect(fn () => app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -8000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => 0, 'note' => null],
    ]))->toThrow(InvalidArgumentException::class);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
});

it('rejects an opposite-sign leg', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    expect(fn () => app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -9000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => 1000, 'note' => null],
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects transfer_in and transfer_out transactions', function (string $type): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000, $type);

    expect(fn () => app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]))->toThrow(InvalidArgumentException::class);
})->with(['transfer_in', 'transfer_out']);

it('accepts every non-transfer transaction type', function (string $type): void {
    $sign = $type === 'income' ? 1 : -1;
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, $sign * 8000, $type);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => $sign * 6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => $sign * 2000, 'note' => null],
    ]);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(2);
})->with(['expense', 'income', 'fee', 'refund', 'adjustment']);

it('rejects a leg whose category is not visible to the user', function (): void {
    $otherUser = User::create(['username' => 'other', 'password' => 'x', 'period_start_day' => 1]);
    $foreignCategory = Category::create(['user_id' => $otherUser->id, 'name' => 'Secret', 'slug' => 'secret', 'kind' => 'expense', 'display_order' => 3]);

    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    expect(fn () => app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $foreignCategory->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]))->toThrow(InvalidArgumentException::class);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(0);
});

it('canonicalises an empty-string note to null on write', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => ''],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => ''],
    ]);

    $legs = TransactionSplit::query()->where('transaction_id', $tx->id)->get();
    foreach ($legs as $leg) {
        expect($leg->note)->toBeNull();
    }
});

it('does not churn a note op when the stored value is empty string and the incoming value is null', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legs = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('sort_order')->get();
    $groceriesLegId = (int) $legs[0]->id;
    $householdLegId = (int) $legs[1]->id;

    // Simulate a sync replay that stored an empty-string note on one leg.
    DB::connection()->table('transaction_splits')->where('id', $groceriesLegId)->update(['note' => '']);

    Event::fake([TransactionSplitMutated::class]);

    // '' stored against null incoming must not count as a change, or the two
    // devices churn ops at each other forever.
    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => $householdLegId, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    Event::assertNotDispatched(TransactionSplitMutated::class);
});

it('preserves leg primary keys on edit: unchanged/edited legs keep their id, added legs INSERT, removed legs DELETE', function (): void {
    $tx = splitTx($this->user->id, $this->account->id, $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $original = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
    $groceriesLegId = $original[0]->id;
    $householdLegId = $original[1]->id;

    $fuel = Category::create(['user_id' => null, 'name' => 'Fuel', 'slug' => 'fuel', 'kind' => 'expense', 'display_order' => 4]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => $householdLegId, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
        ['id' => null, 'category_id' => $fuel->id, 'settled_amount_minor' => -1000, 'note' => null],
    ]);

    $after = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
    expect($after)->toHaveCount(3);

    $groceriesAfter = $after->firstWhere('id', $groceriesLegId);
    $householdAfter = $after->firstWhere('id', $householdLegId);
    expect($groceriesAfter)->not->toBeNull();
    expect($groceriesAfter->settled_amount_minor)->toBe(-5000);
    expect($householdAfter)->not->toBeNull();
    expect($householdAfter->settled_amount_minor)->toBe(-2000);

    $fuelLegId = $after->firstWhere('category_id', $fuel->id)->id;

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -7000, 'note' => null],
        ['id' => $fuelLegId, 'category_id' => $fuel->id, 'settled_amount_minor' => -1000, 'note' => null],
    ]);

    $final = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
    expect($final)->toHaveCount(2);
    expect($final->firstWhere('id', $householdLegId))->toBeNull();
    expect($final->firstWhere('id', $groceriesLegId)->settled_amount_minor)->toBe(-7000);
    expect($final->firstWhere('id', $fuelLegId)->settled_amount_minor)->toBe(-1000);
});
