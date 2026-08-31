<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;

beforeEach(function (): void {
    $this->user = User::create(['username' => 'lock-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-lock-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000004',
        'default_currency' => 'EUR',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'lock-fixture-groceries', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'lock-fixture-household', 'kind' => 'expense', 'display_order' => 2]);

    $this->run = $this->makeImportRun($this->user);

    Event::fake([TransactionMutated::class, TransactionSplitMutated::class]);
});

it('reclassify refuses to change the type of a reconciled transaction', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled', 'type' => 'expense']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('reclassify', 'income');

    expect(Transaction::query()->find($tx->id)->type)->toBe('expense');
});

it('toggleLegTax refuses to change a reconciled transaction\'s leg tax state', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -8000,
        'settled_amount_minor' => -8000,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    DB::table('transactions')->where('id', $tx->id)->update(['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('toggleLegTax', 0)
        ->assertDispatched('toast');

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $tx->id)->count())->toBe(0);
});

it('saveNote refuses to change the note of a reconciled transaction', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);
    DB::table('transactions')->where('id', $tx->id)->update(['note' => 'original']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->set('note', 'changed')
        ->call('saveNote');

    expect(DB::table('transactions')->where('id', $tx->id)->value('note'))->toBe('original');
});

it('reassignCounterparty refuses to change the counterparty of a reconciled transaction', function (): void {
    $counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'lock-fixture-new-counterparty',
        'display_name' => 'New Counterparty',
    ]);

    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('reassignCounterparty', $counterparty->id);

    expect(Transaction::query()->find($tx->id)->counterparty_id)->toBeNull();
});

it('deleteTransaction refuses to delete a reconciled transaction', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('deleteTransaction');

    expect(Transaction::query()->find($tx->id))->not->toBeNull();
});

it('SaveTransactionSplit::save refuses to edit legs of a reconciled transaction', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -8000,
        'settled_amount_minor' => -8000,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    DB::table('transactions')->where('id', $tx->id)->update(['status' => 'reconciled']);

    $legsBefore = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->pluck('settled_amount_minor', 'id')->all();
    $firstLegId = array_key_first($legsBefore);

    expect(
        fn () => app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
            ['id' => $firstLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -7000, 'note' => null],
            ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -1000, 'note' => null],
        ]),
    )->toThrow(InvalidArgumentException::class);

    $legsAfter = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->pluck('settled_amount_minor', 'id')->all();
    expect($legsAfter)->toBe($legsBefore);
});

it('SaveTransactionSplit::unsplit refuses to collapse a reconciled transaction\'s legs', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -8000,
        'settled_amount_minor' => -8000,
        'category_id' => null,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    DB::table('transactions')->where('id', $tx->id)->update(['status' => 'reconciled']);

    expect(
        fn () => app(SaveTransactionSplit::class)->unsplit($this->user, $tx->id, $this->groceries->id),
    )->toThrow(InvalidArgumentException::class);

    expect(TransactionSplit::query()->where('transaction_id', $tx->id)->count())->toBe(2);
});
