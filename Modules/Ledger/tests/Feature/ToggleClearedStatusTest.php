<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\TransactionMutated;

/*
 * Wave 0 RED stub (SC-1, GREEN in 13.3-03). `TransactionDetail` has no
 * `toggleCleared()` method yet. Per RESEARCH.md Pattern 2 (mirrors the
 * existing `saveNote()` raw-update + dispatch-after-commit shape), the
 * toggle flips cleared<->uncleared, is scoped by `user_id` (a cross-user
 * transactionId never seats the component — same 404 guard as every other
 * TransactionDetail mutator), and refuses to mutate a `reconciled` row
 * (D-08, warn-first per Claude's Discretion).
 */

beforeEach(function (): void {
    $this->user = User::create(['username' => 'toggle-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-toggle-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000003',
        'default_currency' => 'EUR',
    ]);

    $this->run = $this->makeImportRun($this->user);

    // Suppress SyncCaptureListener — the toggle's own capture wiring is not
    // this stub's concern (covered separately by MergeRulesRegistryStatusTest
    // / StatusConcurrentEditConvergenceTest).
    Event::fake([TransactionMutated::class]);
});

it('flips a cleared transaction to uncleared', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('toggleCleared');

    expect(Transaction::query()->find($tx->id)->status)->toBe('uncleared');
});

it('flips an uncleared transaction to cleared', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'uncleared']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('toggleCleared');

    expect(Transaction::query()->find($tx->id)->status)->toBe('cleared');
});

it('bumps updated_at when it flips the status (IN-02)', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    // Force a stale updated_at that the raw QB toggle must overwrite.
    DB::table('transactions')->where('id', $tx->id)->update(['updated_at' => '2000-01-01 00:00:00']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('toggleCleared');

    expect(DB::table('transactions')->where('id', $tx->id)->value('updated_at'))
        ->not->toBe('2000-01-01 00:00:00');
});

it('a cross-user transaction id never seats the component, so nothing is toggled', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    $intruder = User::create(['username' => 'toggle-intruder', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->actingAs($intruder);

    expect(
        fn () => Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
            ->call('toggleCleared'),
    )->toThrow(Exception::class);

    // Raw query builder — bypasses the BelongsToUser global scope, which is
    // now filtering by the intruder's user_id and would otherwise return null.
    expect(DB::table('transactions')->where('id', $tx->id)->value('status'))->toBe('cleared');
});

it('refuses to toggle a reconciled row', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'reconciled']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('toggleCleared');

    expect(Transaction::query()->find($tx->id)->status)->toBe('reconciled');
});
