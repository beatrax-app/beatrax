<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Sync\Public\Events\TransactionMutated;

beforeEach(function (): void {
    $this->user = User::create(['username' => 'unreconcile-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-unreconcile-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000011',
        'default_currency' => 'EUR',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'unreconcile-fixture-groceries', 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'unreconcile-fixture-household', 'kind' => 'expense', 'display_order' => 2]);

    $this->run = $this->makeImportRun($this->user);
});

it('offers the way out on the page the reconciled-lock toast is raised from', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertSeeHtml('data-testid="unreconcile-section"')
        ->assertSeeHtml('data-testid="unreconcile-button"')
        ->assertSeeHtml('wire:click="startUnreconcile"')
        ->assertSee(Lang::get('ledger::detail.unreconcile.button'));
});

it('draws no way out on a row that is not locked', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->assertDontSeeHtml('data-testid="unreconcile-section"');
});

it('asks before it unlocks, and writes nothing while the question stands', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('startUnreconcile')
        ->assertSeeHtml('data-testid="unreconcile-confirm"')
        ->assertSeeHtml('wire:click="unreconcile"')
        ->assertSeeHtml('wire:click="cancelUnreconcile"')
        ->assertSee(Lang::get('ledger::detail.unreconcile.confirm_question'));

    expect(DB::table('transactions')->where('id', $tx->id)->value('status'))->toBe('reconciled');
});

it('leaves the row locked when the question is answered with no', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('startUnreconcile')
        ->call('cancelUnreconcile')
        ->assertDontSeeHtml('data-testid="unreconcile-confirm"')
        ->assertSeeHtml('data-testid="unreconcile-button"');

    expect(DB::table('transactions')->where('id', $tx->id)->value('status'))->toBe('reconciled');
});

it('unlocks the row when the question is answered with yes, and takes the section away with it', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('startUnreconcile')
        ->call('unreconcile')
        ->assertDispatched('toast')
        ->assertDontSeeHtml('data-testid="unreconcile-section"');

    expect(DB::table('transactions')->where('id', $tx->id)->value('status'))->toBe('cleared');
});

it('releases the edits the lock was refusing', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => 'reconciled',
        'type' => 'expense',
    ]);

    $page = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id]);

    $page->call('reclassify', 'income');
    expect(DB::table('transactions')->where('id', $tx->id)->value('type'))->toBe('expense');

    $page->call('startUnreconcile')->call('unreconcile');

    $page->call('reclassify', 'income');
    expect(DB::table('transactions')->where('id', $tx->id)->value('type'))->toBe('income');
});

it('reopens a fresh confirm rather than a stale one after the row is locked again', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    $page = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('startUnreconcile')
        ->call('unreconcile');

    DB::table('transactions')->where('id', $tx->id)->update(['status' => 'reconciled']);

    $page->call('$refresh')
        ->assertSeeHtml('data-testid="unreconcile-button"')
        ->assertDontSeeHtml('data-testid="unreconcile-confirm"');
});

it('never unlocks a row belonging to somebody else', function (): void {
    $otherUser = User::create(['username' => 'unreconcile-other', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $otherAccount = Account::create([
        'user_id' => $otherUser->id,
        'name' => 'ASN other',
        'slug' => 'asn-unreconcile-other',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000012',
        'default_currency' => 'EUR',
    ]);
    $otherRun = $this->makeImportRun($otherUser, str_repeat('b', 64));
    $otherTx = $this->makeTransaction($otherUser, $otherAccount, $otherRun, ['status' => 'reconciled']);

    Event::fake([TransactionMutated::class]);

    app(ReconciliationWriter::class)->unreconcile($this->user, $otherTx->id);

    expect(DB::table('transactions')->where('id', $otherTx->id)->value('status'))->toBe('reconciled');
    Event::assertNotDispatched(TransactionMutated::class);
});

it('refuses to load a foreign transaction before it can be unlocked at all', function (): void {
    $otherUser = User::create(['username' => 'unreconcile-foreign', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $otherAccount = Account::create([
        'user_id' => $otherUser->id,
        'name' => 'ASN foreign',
        'slug' => 'asn-unreconcile-foreign',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000013',
        'default_currency' => 'EUR',
    ]);
    $otherRun = $this->makeImportRun($otherUser, str_repeat('c', 64));
    $otherTx = $this->makeTransaction($otherUser, $otherAccount, $otherRun, ['status' => 'reconciled']);

    // Over real HTTP, not the Livewire harness: that harness does not
    // propagate a mount-time NotFoundHttpException.
    $this->get(route('transactions.show', ['transactionId' => $otherTx->id]))->assertNotFound();

    expect(DB::table('transactions')->where('id', $otherTx->id)->value('status'))->toBe('reconciled');
});

it('leaves the reconcile page balanced, because a row unlocked still counts as cleared', function (): void {
    $inWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-10', 'amount_minor' => -5000]);
    $statementDate = CarbonImmutable::parse('2026-06-15');

    $writer = app(ReconciliationWriter::class);
    $balances = app(AccountBalanceQuery::class);

    $writer->completeReconcile($this->user, $this->account->id, $statementDate);
    $matchedBefore = $balances->clearedBalanceAsOf($this->account->id, $this->user, $statementDate)->in('EUR');

    $writer->unreconcile($this->user, $inWindow->id);
    $matchedAfter = $balances->clearedBalanceAsOf($this->account->id, $this->user, $statementDate)->in('EUR');

    expect($matchedAfter)->toBe($matchedBefore)->toBe(-5000);

    Livewire::test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', '-50.00')
        ->set('statementDate', '2026-06-15')
        ->assertSet('error', '')
        ->call('confirmReconcile')
        ->assertSet('error', '');

    expect(DB::table('transactions')->where('id', $inWindow->id)->value('status'))->toBe('reconciled');
});
