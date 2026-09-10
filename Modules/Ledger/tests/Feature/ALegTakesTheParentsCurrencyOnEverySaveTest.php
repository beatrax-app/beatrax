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
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Sync\Public\Events\TransactionSplitMutated;

uses(RefreshDatabase::class);

// A leg took settled_currency at insert and never again: insertLeg() wrote the
// column and updateLeg()'s field list left it out. A parent whose currency was
// corrected kept legs priced in the old one, and re-balancing the split -- the
// one action that looks like it would put them right -- could not.

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(3));

    $this->user = User::create([
        'username' => 'legcur-'.$suffix,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'legcur-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/legcur.xml',
        'sha256' => hash('sha256', 'legcur-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'lc-g-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'lc-h-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'legcur-tx-'.$suffix),
        'fingerprint_version' => 1,
    ]);
});

it('rewrites an existing legs currency when the parent changed its own', function (): void {
    $action = app(SaveTransactionSplit::class);

    $action->save($this->user, (int) $this->tx->id, [
        ['id' => null, 'category_id' => (int) $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => null, 'category_id' => (int) $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    $ids = DB::table('transaction_splits')->where('transaction_id', $this->tx->id)
        ->orderBy('sort_order')->pluck('id')->all();

    expect(DB::table('transaction_splits')->whereIn('id', $ids)->pluck('settled_currency')->unique()->all())
        ->toBe(['EUR']);

    DB::table('transactions')->where('id', $this->tx->id)
        ->update(['settled_currency' => 'USD']);

    // The same two legs, by id, re-balanced against the corrected parent.
    $action->save($this->user, (int) $this->tx->id, [
        ['id' => $ids[0], 'category_id' => (int) $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => $ids[1], 'category_id' => (int) $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    expect(DB::table('transaction_splits')->whereIn('id', $ids)->pluck('settled_currency')->unique()->all())
        ->toBe(['USD']);
});

// Adding the column to the write also puts it in the dirty-diff, and the diff
// reads a column the query did not select as null -- which would have made
// every ordinary leg edit look like a currency change to every paired device.
it('does not call the currency dirty when nothing about it moved', function (): void {
    $action = app(SaveTransactionSplit::class);

    $action->save($this->user, (int) $this->tx->id, [
        ['id' => null, 'category_id' => (int) $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => null, 'category_id' => (int) $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    $ids = DB::table('transaction_splits')->where('transaction_id', $this->tx->id)
        ->orderBy('sort_order')->pluck('id')->all();

    // Faked before the action is resolved: one built earlier holds the real
    // dispatcher, and asserting against a fake it never had passes for nothing.
    Event::fake([TransactionSplitMutated::class]);

    app(SaveTransactionSplit::class)->save($this->user, (int) $this->tx->id, [
        ['id' => $ids[0], 'category_id' => (int) $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => $ids[1], 'category_id' => (int) $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    Event::assertNotDispatched(TransactionSplitMutated::class);
});
