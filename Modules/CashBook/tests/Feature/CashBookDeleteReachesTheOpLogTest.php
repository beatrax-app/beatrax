<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cashbook-delete-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

function cashBookEntry(User $user, string $counterparty = 'Market'): int
{
    Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '80,00')
        ->set('date', '2026-06-05')
        ->set('counterparty', $counterparty)
        ->call('add')
        ->assertSet('error', '');

    return (int) DB::table('transactions')
        ->where('user_id', $user->id)
        ->where('counterparty_name', $counterparty)
        ->value('id');
}

it('refuses to delete a cash entry whose account has been reconciled', function (): void {
    $id = cashBookEntry($this->user);
    DB::table('transactions')->where('id', $id)->update(['status' => ClearedStatus::Reconciled->value]);

    Event::fake([TransactionMutated::class, TransactionSplitMutated::class]);

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('confirmDelete', $id)
        ->call('delete', $id)
        ->assertDispatched('toast', message: Lang::get('cashbook::cash-book.toast.reconciled_locked'));

    expect(DB::table('transactions')->where('id', $id)->exists())
        ->toBeTrue('a completed reconciliation was invalidated by a delete');

    Event::assertNotDispatched(TransactionMutated::class);
});

it('tells the reader in their own language why a reconciled cash entry stayed', function (): void {
    $id = cashBookEntry($this->user);
    DB::table('transactions')->where('id', $id)->update(['status' => ClearedStatus::Reconciled->value]);

    app()->setLocale('nl');

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('confirmDelete', $id)
        ->call('delete', $id)
        ->assertDispatched('toast', message: Lang::get('cashbook::cash-book.toast.reconciled_locked', [], 'nl'));
});

it('replicates a permitted cash-entry delete to the paired devices', function (): void {
    $id = cashBookEntry($this->user);

    Event::fake([TransactionMutated::class, TransactionSplitMutated::class]);

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('confirmDelete', $id)
        ->call('delete', $id)
        ->assertDispatched('toast', message: Lang::get('cashbook::cash-book.toast.removed'));

    expect(DB::table('transactions')->where('id', $id)->exists())->toBeFalse();

    // Without the op-log entry the row is gone here and survives on every
    // paired device for good.
    Event::assertDispatched(
        TransactionMutated::class,
        fn (TransactionMutated $e): bool => $e->transactionId === $id
            && $e->userId === $this->user->id
            && $e->mutationType === 'delete',
    );
});

it('tombstones each leg of a split cash entry it deletes', function (): void {
    $id = cashBookEntry($this->user, 'Split market');

    $categoryIds = array_map(
        fn (string $slug): int => (int) DB::table('categories')->insertGetId([
            'user_id' => $this->user->id,
            'name' => ucfirst($slug),
            'slug' => 'cb-split-'.$slug,
            'kind' => 'expense',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]),
        ['groceries', 'household'],
    );

    app(SaveTransactionSplit::class)->save($this->user, $id, [
        ['id' => null, 'category_id' => $categoryIds[0], 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $categoryIds[1], 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legIds = array_map('intval', DB::table('transaction_splits')
        ->where('transaction_id', $id)
        ->orderBy('id')
        ->pluck('id')
        ->all());

    expect($legIds)->toHaveCount(2);

    Event::fake([TransactionMutated::class, TransactionSplitMutated::class]);

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('confirmDelete', $id)
        ->call('delete', $id);

    Event::assertDispatchedTimes(TransactionSplitMutated::class, 2);

    foreach ($legIds as $legId) {
        Event::assertDispatched(
            TransactionSplitMutated::class,
            fn (TransactionSplitMutated $e): bool => $e->splitId === $legId
                && $e->transactionId === $id
                && $e->mutationType === 'delete',
        );
    }
});
