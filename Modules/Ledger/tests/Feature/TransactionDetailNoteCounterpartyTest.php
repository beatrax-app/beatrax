<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Sync\Public\Events\TransactionMutated;

/*
 * Feature tests for the note editor, counterparty reassignment, and delete
 * affordances added in Plan 11-04 (Task 2).
 *
 * All tests use Event::fake([TransactionMutated::class]) to suppress the
 * SyncCaptureListener — the Sync behaviour is covered separately in
 * Modules/Sync/tests/Feature/OpLogCaptureWiringTest.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-14 12:00:00'));

    $this->asnAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    $this->run = $this->makeImportRun($this->fixtureUser);

    // Suppress SyncCaptureListener — Sync capture requires OpLogWriter with
    // device creds not available in this test context.
    Event::fake([TransactionMutated::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// ── Note editor ───────────────────────────────────────────────────────────────

it('saveNote — sets transactions.note and dispatches TransactionMutated(edit,{note})', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -2500,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->set('note', 'My test note')
        ->call('saveNote')
        ->assertSet('noteSaved', true);

    $tx->refresh();
    expect($tx->note)->toBe('My test note');

    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e) use ($tx): bool {
        return $e->transactionId === $tx->id
            && $e->mutationType === 'edit'
            && ($e->dirtyFields['note'] ?? null) === 'My test note';
    });
})->group('phase-11');

it('saveNote — blank note stores NULL and dispatches {note: null}', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    // Plan 13.4-04 (SetTransactionNote's write-only-on-change guard):
    // blanking an ALREADY-null note is a genuine no-op — seed a real
    // note first so this test exercises an actual null-clearing change.
    DB::table('transactions')
        ->where('id', $tx->id)
        ->update(['note' => 'Existing note']);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->set('note', '   ')  // whitespace-only
        ->call('saveNote');

    $tx->refresh();
    expect($tx->note)->toBeNull();

    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e): bool {
        return $e->mutationType === 'edit'
            && array_key_exists('note', $e->dirtyFields)
            && $e->dirtyFields['note'] === null;
    });
})->group('phase-11');

it('saveNote — loads existing note into the wire:model on mount', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -500,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    // Set an initial note via DB.
    DB::table('transactions')
        ->where('id', $tx->id)
        ->update(['note' => 'Pre-existing note']);

    $component = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id]);
    $component->assertSet('note', 'Pre-existing note');
})->group('phase-11');

// ── Counterparty reassignment ─────────────────────────────────────────────────

it('reassignCounterparty — sets counterparty_id and dispatches TransactionMutated(edit,{counterparty_id})', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -3000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    // Create a counterparty owned by the same user.
    $cpId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'display_name' => 'Albert Heijn',
        'slug' => 'albert-heijn-test-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('reassignCounterparty', $cpId);

    $fresh = DB::table('transactions')
        ->where('id', $tx->id)
        ->first();
    expect((int) $fresh->counterparty_id)->toBe($cpId);

    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e) use ($tx, $cpId): bool {
        return $e->transactionId === $tx->id
            && $e->mutationType === 'edit'
            && ($e->dirtyFields['counterparty_id'] ?? null) === $cpId;
    });
})->group('phase-11');

it('reassignCounterparty — silent no-op for counterparty owned by another user', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    // Create a user B and a counterparty owned by user B.
    $otherUserId = (int) DB::table('users')->insertGetId([
        'username' => 'other-user-cp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $crossUserCpId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $otherUserId,
        'display_name' => 'Other User CP',
        'slug' => 'other-user-cp-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $originalCpId = $tx->counterparty_id;

    // Should be a silent no-op — no write, no event.
    Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('reassignCounterparty', $crossUserCpId);

    $fresh = DB::table('transactions')
        ->where('id', $tx->id)
        ->first();
    expect($fresh->counterparty_id)->toBe($originalCpId);

    // No TransactionMutated event dispatched.
    Event::assertNotDispatched(TransactionMutated::class);
})->group('phase-11');

// ── Delete ─────────────────────────────────────────────────────────────────────

it('deleteTransaction — removes the row and dispatches TransactionMutated(delete)', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -5000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    $txId = $tx->id;

    Livewire::test(TransactionDetail::class, ['transactionId' => $txId])
        ->call('deleteTransaction');

    // Row must be gone.
    $row = DB::table('transactions')->where('id', $txId)->first();
    expect($row)->toBeNull();

    // TransactionMutated(delete) dispatched.
    Event::assertDispatched(TransactionMutated::class, function (TransactionMutated $e) use ($txId): bool {
        return $e->transactionId === $txId && $e->mutationType === 'delete';
    });
})->group('phase-11');

// ── Blade rendering ────────────────────────────────────────────────────────────

it('renders the note textarea on the transaction detail page', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('note-editor', false);
    $response->assertSee('note-textarea', false);
    $response->assertSee('Save note', false);
})->group('phase-11');

it('renders the delete button on the transaction detail page', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('delete-section', false);
    $response->assertSee('Delete', false);
    $response->assertSee('deleteTransaction', false);
})->group('phase-11');
