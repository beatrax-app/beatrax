<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;

beforeEach(function (): void {
    $this->user = User::create(['username' => 'answer-order-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-answer-order-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000021',
        'default_currency' => 'EUR',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'answer-order-groceries', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'answer-order-household', 'kind' => 'expense', 'display_order' => 2]);

    $this->run = $this->makeImportRun($this->user);
});

/** @return int the character offset of a wire:click in the rendered page */
function answerOrderOffset(string $html, string $call): int
{
    $needle = 'wire:click="'.$call.'"';
    $at = strpos($html, $needle);

    expect($at)->not->toBeFalse("the page never drew {$needle}");

    return (int) $at;
}

// The button nearest the thumb must not be the one that cannot be taken back.
// All three pairs on this page put the destructive answer first; the shared
// x-core::confirm-strip already orders them the other way round.
it('draws cancel before confirm on the delete question', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    $html = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])->html();

    $cancelAt = strpos($html, 'confirmDelete = false');

    expect($cancelAt)->not->toBeFalse('the page never drew the delete cancel');

    expect(answerOrderOffset($html, 'deleteTransaction'))->toBeGreaterThan((int) $cancelAt);
});

it('draws cancel before confirm on the unsplit question', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -8000,
        'settled_amount_minor' => -8000,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $html = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->call('unsplit')
        ->assertSeeHtml('data-testid="split-unsplit-confirm"')
        ->html();

    expect(answerOrderOffset($html, 'confirmUnsplitAction'))
        ->toBeGreaterThan(answerOrderOffset($html, 'cancelUnsplit'));
});

it('draws cancel before confirm on the last-category question', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -8000,
        'settled_amount_minor' => -8000,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $html = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->call('removeLeg', 1)
        ->assertSeeHtml('data-testid="split-remove-to-one-confirm"')
        ->html();

    expect(answerOrderOffset($html, 'confirmRemoveToOneAction'))
        ->toBeGreaterThan(answerOrderOffset($html, 'cancelRemoveToOne'));
});
