<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;

/*
 * Feature tests for the manual reclassify action on
 * `/transactions/{id}`. The Livewire `reclassify` action owns the
 * user-override path: single-click type change, atomic break-pair
 * invariant on non-transfer reclassify, and cross-user 404 safety
 * inherited from the detail page's existing user-scoping.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $asnAccount */
    $asnAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->asnAccount = $asnAccount;

    /** @var Account $icsAccount */
    $icsAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'ICS-CARD')
        ->firstOrFail();
    $this->icsAccount = $icsAccount;

    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('changesType — reclassifies a single unpaired row and persists the new type', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'income',
        'amount_minor' => 50000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Mystery sender',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->set('reclassifyType', 'expense')
        ->call('reclassify', 'expense')
        ->assertDispatched('toast');

    $tx->refresh();
    expect($tx->type)->toBe('expense');
    expect($tx->pair_transaction_id)->toBeNull();
})->group('phase-4');

it('breaksPair — reclassifying a paired transfer to a non-transfer clears pair_transaction_id on BOTH rows', function (): void {
    // Two rows pre-paired (mirrors the listener's terminal state).
    $asnRow = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -10000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Settlement to ICS',
        'counterparty_iban' => 'ICS-CARD',
    ]);
    $icsRow = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 10000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'iDEAL bulk settlement',
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $asnRow->pair_transaction_id = $icsRow->id;
    $asnRow->save();
    $icsRow->pair_transaction_id = $asnRow->id;
    $icsRow->save();

    Livewire::test(TransactionDetail::class, ['transactionId' => $asnRow->id])
        ->call('reclassify', 'expense')
        ->assertDispatched('toast');

    $asnRow->refresh();
    $icsRow->refresh();

    expect($asnRow->type)->toBe('expense');
    expect($asnRow->pair_transaction_id)->toBeNull();
    // Partner row's pair_transaction_id must be cleared atomically.
    expect($icsRow->pair_transaction_id)->toBeNull();
    // Partner's own type is preserved — reclassify only un-pairs, never re-types the partner.
    expect($icsRow->type)->toBe('transfer_in');
})->group('phase-4');

it('preservesPairOnTransferToTransfer — reclassifying transfer_out to transfer_in keeps the pair intact', function (): void {
    $asnRow = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -10000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_iban' => 'ICS-CARD',
    ]);
    $icsRow = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 10000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);
    $asnRow->pair_transaction_id = $icsRow->id;
    $asnRow->save();
    $icsRow->pair_transaction_id = $asnRow->id;
    $icsRow->save();

    Livewire::test(TransactionDetail::class, ['transactionId' => $asnRow->id])
        ->call('reclassify', 'transfer_in');

    $asnRow->refresh();
    $icsRow->refresh();

    expect($asnRow->type)->toBe('transfer_in');
    expect($asnRow->pair_transaction_id)->toBe($icsRow->id);
    expect($icsRow->pair_transaction_id)->toBe($asnRow->id);
})->group('phase-4');

it('crossUser404 — User B cannot reclassify User A\'s transaction', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'income',
        'amount_minor' => 50000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $intruder = User::query()->create([
        'email' => 'intruder@diederik.test',
        'password' => 'intruder-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($intruder);

    // The detail-page HTTP route itself must return 404 for a cross-user
    // request — this is the canonical user-facing invariant that mirrors
    // the existing cross-user test on the same detail page surface.
    $this->get(route('transactions.show', $tx->id))->assertStatus(404);

    // Defence-in-depth at the action layer: mount() refuses to seat the
    // Livewire component because the row is unreachable under the
    // intruder's user_id scope, so the call chain raises before any DB
    // write happens. We assert SOME exception is raised (Livewire wraps
    // mount() throwables for the test harness) and that the row stays
    // untouched.
    expect(
        fn () => Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
            ->call('reclassify', 'expense'),
    )->toThrow(Exception::class);

    // Original row stays untouched.
    $tx->refresh();
    expect($tx->type)->toBe('income');
})->group('phase-4');

it('rejectsInvalidType — calling reclassify with an out-of-allow-list type raises InvalidArgumentException', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    expect(
        fn () => Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
            ->call('reclassify', 'bogus_type'),
    )->toThrow(InvalidArgumentException::class);

    $tx->refresh();
    expect($tx->type)->toBe('expense');
})->group('phase-4');

it('emptyTypeIsNoOp — passing an empty string produces no DB write and raises InvalidArgumentException', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    // Empty string is not in Transaction::TYPES, so it is rejected by the
    // same allow-list guard as any other invalid value. The Blade `disabled`
    // attribute on Save prevents this in normal UX; the action itself
    // remains defensive.
    expect(
        fn () => Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
            ->call('reclassify', ''),
    )->toThrow(InvalidArgumentException::class);

    $tx->refresh();
    expect($tx->type)->toBe('expense');
})->group('phase-4');

it('rendersReclassifyDropdownOnDetailPage — the /transactions/{id} page renders the Reclassify control', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('Reclassify', false);
    $response->assertSee('wire:click="reclassify', false);
    // Allow-listed types appear in the dropdown options (except the
    // transaction's own current type, which is filtered out).
    $response->assertSee('income', false);
    $response->assertSee('transfer_in', false);
    $response->assertDontSee('value="expense"', false);
})->group('phase-4');
