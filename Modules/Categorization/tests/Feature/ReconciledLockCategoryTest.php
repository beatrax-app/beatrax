<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\InlineCategoryPicker;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\TransactionMutated;

/*
 * D-08 reconciled lock gap: the AssignCategory -> UpdateTransactionCategory
 * path used by InlineCategoryPicker on the /transactions list had NO status
 * check, so a reconciled transaction's category could still be changed from
 * the list (and the write propagated into the sync op-log via
 * TransactionMutated). Every sibling mutator (TransactionDetail's
 * reclassifyCategory, SaveTransactionSplit, HandlesTaxTagging) already
 * enforces this warn-first "un-reconcile first" contract (WR-01 / U-1); this
 * closes the last gap.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'reconciled-cat-lock-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-reconciled-cat-lock-fixture',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0000000021',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/reconciled-cat-lock-fixture.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'reconciled-cat-lock-groceries',
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    $this->household = Category::create([
        'user_id' => null,
        'name' => 'Household',
        'slug' => 'reconciled-cat-lock-household',
        'kind' => 'expense',
        'display_order' => 70,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function reconciledCatLockTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah amsterdam',
        'counterparty_name' => 'AH Amsterdam',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $seq,
        'fingerprint' => hash('sha256', 'reconciled-cat-lock-'.$seq),
        'fingerprint_version' => 1,
        'category_id' => null,
        'status' => 'cleared',
    ], $overrides));
}

it('AssignCategory refuses to change the category of a reconciled transaction and emits no events', function (): void {
    $tx = reconciledCatLockTx($this->user, $this->account, $this->importRun, [
        'status' => 'reconciled',
        'category_id' => $this->groceries->id,
    ]);

    Event::fake([TransactionCategorized::class, TransactionMutated::class]);

    /** @var AssignsCategory $action */
    $action = $this->app->make(AssignsCategory::class);
    $affected = $action($tx->id, $this->household->id, $this->user);

    expect($affected)->toBe(0);
    expect(Transaction::find($tx->id)->category_id)->toBe($this->groceries->id);

    Event::assertNotDispatched(TransactionCategorized::class);
    Event::assertNotDispatched(TransactionMutated::class);
});

it('AssignCategory still categorizes a non-reconciled transaction', function (): void {
    $tx = reconciledCatLockTx($this->user, $this->account, $this->importRun, [
        'status' => 'cleared',
        'category_id' => $this->groceries->id,
    ]);

    Event::fake([TransactionCategorized::class, TransactionMutated::class]);

    /** @var AssignsCategory $action */
    $action = $this->app->make(AssignsCategory::class);
    $affected = $action($tx->id, $this->household->id, $this->user);

    expect($affected)->toBe(1);
    expect(Transaction::find($tx->id)->category_id)->toBe($this->household->id);

    Event::assertDispatched(TransactionCategorized::class);
    Event::assertDispatched(TransactionMutated::class);
});

it('InlineCategoryPicker refuses to change the category of a reconciled transaction and warns', function (): void {
    $tx = reconciledCatLockTx($this->user, $this->account, $this->importRun, [
        'status' => 'reconciled',
        'category_id' => $this->groceries->id,
    ]);

    Event::fake([TransactionCategorized::class, TransactionMutated::class]);

    Livewire::test(InlineCategoryPicker::class, ['transactionId' => $tx->id, 'categoryId' => $this->groceries->id])
        ->set('categoryId', $this->household->id)
        ->assertDispatched('toast')
        ->assertSet('categoryId', $this->groceries->id);

    expect(DB::table('transactions')->where('id', $tx->id)->value('category_id'))->toBe($this->groceries->id);

    Event::assertNotDispatched(TransactionCategorized::class);
    Event::assertNotDispatched(TransactionMutated::class);
});

it('InlineCategoryPicker still changes the category of a non-reconciled transaction', function (): void {
    $tx = reconciledCatLockTx($this->user, $this->account, $this->importRun, [
        'status' => 'cleared',
        'category_id' => $this->groceries->id,
    ]);

    Livewire::test(InlineCategoryPicker::class, ['transactionId' => $tx->id, 'categoryId' => $this->groceries->id])
        ->set('categoryId', $this->household->id)
        ->assertNotDispatched('toast');

    expect(DB::table('transactions')->where('id', $tx->id)->value('category_id'))->toBe($this->household->id);
});
