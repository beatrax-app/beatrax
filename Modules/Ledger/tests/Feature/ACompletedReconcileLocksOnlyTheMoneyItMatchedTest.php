<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;

// An account holds one line per currency it has settled in — a PayPal wallet
// and a card billed abroad both do, and AccountBalanceQuery groups by
// settled_currency for exactly that reason. Every question the reconcile screen
// asks is narrowed to the statement's own line: the cleared balance is read
// ->in($statementCurrency), the reachability bound filters on it, and the typed
// figure is parsed at its scale. The write at the end of that screen was not,
// so matching the euro line locked every cleared dollar row up to the same day
// into a terminal state no import can revise and only a row-by-row un-reconcile
// can undo.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'two-line-reconcile',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Wallet',
        'slug' => 'wallet-two-line-reconcile',
        'kind' => 'bank',
        'iban' => 'NL00TWOLINERECONCILE',
        'default_currency' => Currency::Eur->value,
    ]);
    $this->run = $this->makeImportRun($this->user);

    $this->euroRow = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -5000,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => -5000,
        'settled_currency' => Currency::Eur->value,
        'posted_at' => '2026-06-10',
    ]);
    $this->dollarRow = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -9999,
        'currency' => Currency::Usd->value,
        'settled_amount_minor' => -9999,
        'settled_currency' => Currency::Usd->value,
        'posted_at' => '2026-06-10',
    ]);
});

it('leaves a cleared row in another currency alone when the statement line matches', function (): void {
    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '-50.00')
        ->assertViewHas('differenceMinor', 0)
        ->call('confirmReconcile')
        ->assertSet('error', '');

    expect(DB::table('transactions')->where('id', $this->euroRow->id)->value('status'))
        ->toBe(ClearedStatus::Reconciled->value)
        ->and(DB::table('transactions')->where('id', $this->dollarRow->id)->value('status'))
        ->toBe(ClearedStatus::Cleared->value);
});

// The count is the screen's own promise about what Complete will do, and the
// toast repeats it back afterwards. Counting a line the balance above it never
// summed named rows the reader has no statement for.
it('counts only the rows the statement is denominated in', function (): void {
    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->assertViewHas('lockableCount', 1);
});
