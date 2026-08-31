<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// Twenty-six identical entries, not thirty distinguishable ones. A different
// counterparty per row is the one thing that keeps every fingerprint apart, and
// six €2.50 coffees on one day is the case a cash book exists for. Twenty-six
// against a 25-row page also puts exactly one entry on page two, which is the
// n % 25 == 1 boundary a delete strands the reader on.
function ecerEntryIds(int $userId): array
{
    return DB::table('transactions')
        ->where('user_id', $userId)
        ->where('source_format', 'manual')
        ->orderByDesc('posted_at')
        ->orderByDesc('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cashbook-paging-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $component = Livewire::actingAs($this->user)->test(CashBookPage::class);

    for ($i = 1; $i <= 26; $i++) {
        $component
            ->set('amount', '2,50')
            ->set('date', '2026-06-05')
            ->set('counterparty', 'Kiosk')
            ->call('add')
            ->assertSet('error', '')
            ->assertDispatched('toast', message: Lang::get('cashbook::cash-book.toast.added'));
    }
});

it('writes a row for every identical entry it said it had added', function (): void {
    expect(DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->where('source_format', 'manual')
        ->count())->toBe(26);
});

it('reaches a cash entry that falls past the first page of the list', function (): void {
    $ids = ecerEntryIds($this->user->id);
    $oldest = $ids[25];

    $component = Livewire::actingAs($this->user)->test(CashBookPage::class);

    $component->assertDontSee('wire:key="manual-'.$oldest.'"', false);

    // A control the reader can actually reach, not only a page the test can
    // ask for.
    $component->assertSee('wire:click="nextPage', false);

    $component->call('gotoPage', 2)->assertSee('wire:key="manual-'.$oldest.'"', false);
});

it('deletes a cash entry drawn on a later page of the list', function (): void {
    $id = ecerEntryIds($this->user->id)[25];

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('gotoPage', 2)
        ->call('confirmDelete', $id)
        ->call('delete', $id);

    expect(DB::table('transactions')->where('id', $id)->exists())->toBeFalse();
});

it('returns to the head of the list when an entry is added from a later page', function (): void {
    $component = Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('gotoPage', 2)
        ->set('amount', '9,99')
        ->set('date', '2026-06-05')
        ->set('counterparty', 'Kiosk')
        ->call('add');

    $newest = ecerEntryIds($this->user->id)[0];

    $component->assertSee('wire:key="manual-'.$newest.'"', false);
});

// Deleting the only entry on the last page left the reader on ?page=2 reading
// "No manual entries yet." above a ledger holding 25 rows, with no pagination
// control and no in-page way back. It survived a reload.
it('does not strand the reader on the page the last delete emptied', function (): void {
    $id = ecerEntryIds($this->user->id)[25];

    $component = Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('gotoPage', 2)
        ->call('confirmDelete', $id)
        ->call('delete', $id);

    $component->assertDontSee(Lang::get('cashbook::cash-book.no_entries'))
        ->assertSee('wire:key="manual-'.ecerEntryIds($this->user->id)[0].'"', false);
});

it('does not strand a reader who arrives on a page that no longer exists', function (): void {
    $id = ecerEntryIds($this->user->id)[25];
    DB::table('transactions')->where('id', $id)->delete();

    Livewire::actingAs($this->user)
        ->test(CashBookPage::class)
        ->call('gotoPage', 2)
        ->assertDontSee(Lang::get('cashbook::cash-book.no_entries'))
        ->assertSee('wire:key="manual-'.ecerEntryIds($this->user->id)[0].'"', false);
});
