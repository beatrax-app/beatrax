<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;

/*
 * Wave 0 RED stub (SC-2, GREEN in 13.3-06).
 * `Modules/Ledger/Internal/Http/Livewire/ReconcilePage.php` does not exist
 * yet — the standalone `/reconcile` surface per Open Question 1's
 * resolution (no account-detail page exists in the app today). This pins
 * the render contract: the cleared balance vs the entered/pre-filled
 * statement target, a discrepancy flag when they don't match, and that the
 * flow NEVER fabricates a balancing/adjustment transaction row (D-07,
 * flag-only, read-only).
 */

beforeEach(function (): void {
    $this->user = User::create(['username' => 'reconcile-flow-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-reconcile-flow-fixture',
        'kind' => 'asn',
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

    // D-07: never fabricates a balancing/adjustment row.
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
