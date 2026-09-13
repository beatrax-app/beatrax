<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;

// The list ended on `t.id`, and the page shows 25 rows: with more entries than
// that, which ones the reader is shown at all was decided by a number each
// device counts for itself. Six coffees typed on one day filled page one one
// way on the desktop and another on the phone.

function sameRankUser(): User
{
    return User::query()->create([
        'username' => 'cashbook-rank-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function sameRankAdd(User $user, string $counterparty): int
{
    Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '2,50')
        ->set('date', '2026-06-05')
        ->set('counterparty', $counterparty)
        ->call('add')
        ->assertSet('error', '');

    return (int) DB::table('transactions')
        ->where('user_id', $user->id)
        ->where('source_format', 'manual')
        ->orderByDesc('id')
        ->value('id');
}

it('ranks two same-day entries by what both devices hold, not by this device\'s id', function (): void {
    $user = sameRankUser();

    $first = sameRankAdd($user, 'Alpha');
    $second = sameRankAdd($user, 'Beta');

    expect($second)->toBeGreaterThan($first);

    // The lower id is now the later instant, so the two orderings disagree.
    DB::table('transactions')->where('id', $first)->update(['booked_at' => '2026-06-05 18:00:00']);
    DB::table('transactions')->where('id', $second)->update(['booked_at' => '2026-06-05 09:00:00']);

    $entries = Livewire::actingAs($user)->test(CashBookPage::class)->viewData('entries');

    expect(array_map(static fn (object $row): int => (int) $row->id, $entries->items()))
        ->toBe([$first, $second]);
});
