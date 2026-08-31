<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;

// The statement figure is typed in the ACCOUNT's own denomination — the page
// labels the field and the difference with that currency's symbol — and was
// parsed at a hundredth regardless, so a yen reader typing what the statement
// says was told their account was off by a hundredfold and could never
// complete a reconcile.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'yen-reconcile-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Tokyo',
        'slug' => 'tokyo-yen-reconcile',
        'kind' => 'bank',
        'iban' => 'JP00YENRECONCILE',
        'default_currency' => Currency::Jpy->value,
    ]);
    $this->run = $this->makeImportRun($this->user);
});

it('matches a yen statement against the yen figure that was typed', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -5000,
        'currency' => Currency::Jpy->value,
        'settled_amount_minor' => -5000,
        'settled_currency' => Currency::Jpy->value,
        'posted_at' => '2026-06-10',
    ]);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '-5000')
        ->assertViewHas('differenceMinor', 0)
        ->assertViewHas('isMatched', true)
        ->call('confirmReconcile')
        ->assertSet('error', '');

    expect(DB::table('transactions')->where('id', $tx->id)->value('status'))
        ->toBe(ClearedStatus::Reconciled->value);
});
