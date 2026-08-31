<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// delete() refuses any id that is not the one confirmDelete() was asked about,
// which is the whole confirmation step: the row's only other control is an
// amount, and the delete has no undo. A payload applies its updates BEFORE its
// calls, so naming the same id in both halves satisfied that comparison
// outright — the entry went, and the confirm strip was never rendered.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cashbook-delete-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    Livewire::test(CashBookPage::class)
        ->set('amount', '80,00')
        ->set('date', '2026-06-05')
        ->set('counterparty', 'Market')
        ->call('add')
        ->assertSet('error', '');

    $this->entryId = (int) DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->where('counterparty_name', 'Market')
        ->value('id');
});

function cashBookPageSnapshot(): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) test()->get('/cash')->assertOk()->getContent(),
        'cashbook.cash-book-page',
    );
}

it('refuses a payload that answers its own confirmation', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        cashBookPageSnapshot(),
        ['deletingEntryId' => $this->entryId],
        [['path' => '', 'method' => 'delete', 'params' => [$this->entryId]]],
    )->assertForbidden();

    $this->assertDatabaseHas('transactions', ['id' => $this->entryId]);
});

it('still deletes the entry once confirmDelete has named it on an earlier request', function (): void {
    $confirmed = LivewireRoundTrip::tamper(
        $this,
        cashBookPageSnapshot(),
        [],
        [['path' => '', 'method' => 'confirmDelete', 'params' => [$this->entryId]]],
    )->assertOk();

    $carried = $confirmed->json('components.0.snapshot');
    expect($carried)->toBeString();

    LivewireRoundTrip::tamper(
        $this,
        (string) $carried,
        [],
        [['path' => '', 'method' => 'delete', 'params' => [$this->entryId]]],
    )->assertOk();

    $this->assertDatabaseMissing('transactions', ['id' => $this->entryId]);
});
