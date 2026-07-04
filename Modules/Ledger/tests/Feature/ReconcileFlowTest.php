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

it('computes the difference on the in-window cleared balance only, so a statement that balances for its window is reconcilable without a fabricated number (CR-01)', function (): void {
    // A cleared row inside the statement window and another cleared row
    // posted AFTER the (past) statement date. The un-bounded balance would
    // be -80,00; the in-window balance is -50,00, which is what the
    // statement's closing balance actually reflects.
    $inWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -5000, 'posted_at' => '2026-06-10']);
    $afterWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'amount_minor' => -3000, 'posted_at' => '2026-06-20']);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '-50,00')
        // The difference/isMatched gate is computed on the in-window balance,
        // NOT the un-bounded -80,00 total — so the true statement value matches.
        ->assertViewHas('differenceMinor', 0)
        ->assertViewHas('isMatched', true)
        ->call('confirmReconcile')
        ->assertDispatched('toast');

    // The in-window row is locked; the post-date row stays cleared; no
    // fabricated balancing row is created.
    expect(DB::table('transactions')->where('id', $inWindow->id)->value('status'))->toBe('reconciled');
    expect(DB::table('transactions')->where('id', $afterWindow->id)->value('status'))->toBe('cleared');
    expect(DB::table('transactions')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('reports an honest toast when a matched statement locks zero in-window rows (WR-04)', function (): void {
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
