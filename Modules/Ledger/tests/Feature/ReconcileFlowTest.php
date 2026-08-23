<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;

beforeEach(function (): void {
    $this->user = User::create(['username' => 'reconcile-flow-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-reconcile-flow-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000007',
        'default_currency' => 'EUR',
    ]);
    $this->run = $this->makeImportRun($this->user);
});

it('renders the cleared balance and flags a non-zero discrepancy against the entered statement balance', function (): void {
    $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -5000]);
    $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'uncleared', 'amount_minor' => -1000]);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', '-60,00')
        ->call('checkDiscrepancy')
        ->assertSee('discrepancy', false);

    // The flow never fabricates a balancing row.
    expect(DB::table('transactions')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('completing a reconcile with zero discrepancy locks the cleared rows and creates no balancing row', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -5000, 'posted_at' => '2026-06-10']);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', '-50,00')
        ->set('statementDate', '2026-06-15')
        ->call('confirmReconcile');

    expect(DB::table('transactions')->where('user_id', $this->user->id)->count())->toBe(1);
    expect(DB::table('transactions')->where('id', $tx->id)->value('status'))->toBe('reconciled');
});

it('computes the difference on the in-window cleared balance only, so a statement that balances for its window is reconcilable without a fabricated number', function (): void {
    // Unbounded, the cleared balance is -80,00; in-window it is -50,00, which
    // is what the statement's closing balance reflects.
    $inWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -5000, 'posted_at' => '2026-06-10']);
    $afterWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -3000, 'posted_at' => '2026-06-20']);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '-50,00')
        ->assertViewHas('differenceMinor', 0)
        ->assertViewHas('isMatched', true)
        ->call('confirmReconcile')
        ->assertDispatched('toast');

    expect(DB::table('transactions')->where('id', $inWindow->id)->value('status'))->toBe('reconciled');
    expect(DB::table('transactions')->where('id', $afterWindow->id)->value('status'))->toBe('cleared');
    expect(DB::table('transactions')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('reports an honest toast when a matched statement locks zero in-window rows', function (): void {
    // Only a post-date cleared row exists, so the in-window cleared balance is
    // 0 and a 0,00 target matches — but there is nothing to lock.
    $afterWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -3000, 'posted_at' => '2026-06-20']);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '0,00')
        ->assertViewHas('isMatched', true)
        ->call('confirmReconcile')
        ->assertDispatched('toast', message: 'Nothing to lock for this statement date.');

    expect(DB::table('transactions')->where('id', $afterWindow->id)->value('status'))->toBe('cleared');
});

it('refuses to reconcile before the form names an account', function (): void {
    // accountId is the mount argument, so an unset one is the state the page
    // opens in from the sidebar rather than from an account row.
    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class)
        ->set('statementBalance', '-50,00')
        ->set('statementDate', '2026-06-15')
        ->call('confirmReconcile')
        ->assertSet('error', 'Choose an account first.');
});

it('refuses to reconcile on a balance or date it cannot read', function (): void {
    $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -5000]);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', 'not a number')
        ->set('statementDate', '2026-06-15')
        ->call('confirmReconcile')
        ->assertSet('error', 'Enter a valid statement balance and date.');

    expect(DB::table('transactions')->where('status', 'reconciled')->count())->toBe(0);
});

// A printed statement is denominated in one currency, the account's own. On an
// account also holding euro rows, the difference was computed against both
// added together and the figure on screen was labelled with the reader's base
// currency rather than the statement's.
it('answers in the currency the statement is printed in, not in every currency the account holds', function (): void {
    $usdAccount = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Revolut USD',
        'slug' => 'revolut-usd-reconcile-flow-fixture',
        'kind' => 'bank',
        'iban' => 'GB00REVOUSD0000001',
        'default_currency' => Currency::Usd->value,
    ]);

    $dollars = $this->makeTransaction($this->user, $usdAccount, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -5000,
        'currency' => Currency::Usd->value,
        'settled_amount_minor' => -5000,
        'settled_currency' => Currency::Usd->value,
        'posted_at' => '2026-06-10',
    ]);
    $euros = $this->makeTransaction($this->user, $usdAccount, $this->run, [
        'status' => 'cleared',
        'amount_minor' => -3000,
        'settled_amount_minor' => -3000,
        'posted_at' => '2026-06-10',
    ]);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $usdAccount->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '-50,00')
        ->assertViewHas('statementCurrency', Currency::Usd->value)
        ->assertViewHas('clearedBalanceMinor', -5000)
        ->assertViewHas('differenceMinor', 0)
        ->assertViewHas('isMatched', true)
        ->call('confirmReconcile')
        ->assertDispatched('toast');

    expect(DB::table('transactions')->where('id', $dollars->id)->value('status'))->toBe('reconciled');
    expect(DB::table('transactions')->where('id', $euros->id)->value('status'))->toBe('reconciled');
});
