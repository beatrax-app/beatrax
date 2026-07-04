<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;

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
        'kind' => 'asn',
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

    $today = CarbonImmutable::now()->toDateString();

    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $this->tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);
});

it('deletes all leg rows and restores a single category_id on unsplit', function (): void {
    expect(TransactionSplit::query()->where('transaction_id', $this->tx->id)->count())->toBe(2);

    app(SaveTransactionSplit::class)->unsplit($this->user, $this->tx->id, $this->groceries->id);

    expect(TransactionSplit::query()->where('transaction_id', $this->tx->id)->count())->toBe(0);

    $refreshed = Transaction::query()->find($this->tx->id);
    expect($refreshed->category_id)->toBe($this->groceries->id);
});

it('rejects a surviving category that is not one of the current legs', function (): void {
    $fuel = Category::create(['user_id' => null, 'name' => 'Fuel', 'slug' => 'fuel', 'kind' => 'expense', 'display_order' => 3]);

    expect(fn () => app(SaveTransactionSplit::class)->unsplit($this->user, $this->tx->id, $fuel->id))
        ->toThrow(InvalidArgumentException::class);

    // Nothing changed — legs remain.
    expect(TransactionSplit::query()->where('transaction_id', $this->tx->id)->count())->toBe(2);
});

it('rolls up as a normal single-category transaction after unsplit', function (): void {
    app(SaveTransactionSplit::class)->unsplit($this->user, $this->tx->id, $this->household->id);

    $rows = DB::connection()
        ->table('transactions')
        ->where('id', $this->tx->id)
        ->where('user_id', $this->user->id)
        ->whereNotExists(function ($q): void {
            $q->select(DB::raw(1))
                ->from('transaction_splits')
                ->whereColumn('transaction_splits.transaction_id', 'transactions.id');
        })
        ->where('category_id', $this->household->id)
        ->get();

    expect($rows)->toHaveCount(1);
});
