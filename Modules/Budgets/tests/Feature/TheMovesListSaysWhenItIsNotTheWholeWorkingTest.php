<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// The grid's `Moved` term is the fold's sum over every move the envelope made
// this period; the list under it stops at ten. Eleven moves printed ten lines
// adding to -62.00 under a column reading -66.00, with nothing saying a line
// was missing — the same thing the pots card's history once did.
beforeEach(function (): void {
    App::setLocale('en');

    $this->user = User::create([
        'username' => 'moveslist-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $this->from = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'moveslist-from-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->to = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'moveslist-to-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->from->id, $this->period->start, 100000);
});

function moveslistMakeMoves(int $count): void
{
    $writer = app(EnvelopeWriter::class);
    for ($i = 1; $i <= $count; $i++) {
        $writer->move(test()->user, test()->from->id, test()->to->id, test()->period->start, 100 * $i, 'move '.$i);
    }
}

it('says how many of its moves the list is showing when it cannot show them all', function (): void {
    moveslistMakeMoves(11);

    $component = Livewire::test(BudgetsPage::class);

    $shown = $component->viewData('recentMoves')[$this->from->id];
    $counts = $component->viewData('moveCounts');

    expect(count($shown))->toBe(10)
        ->and($counts[$this->from->id])->toBe(11)
        ->and(array_sum(array_map(static fn (object $move): int => $move->amountMinor, $shown)))
        ->not->toBe($component->viewData('rows')[$this->from->id]->netMovedMinor);

    $component->assertSee(Lang::get('budgets::messages.history.truncated', ['shown' => 10, 'count' => 11]));
});

it('says nothing where the list is the whole of it', function (): void {
    moveslistMakeMoves(10);

    $component = Livewire::test(BudgetsPage::class);

    $shown = $component->viewData('recentMoves')[$this->from->id];

    expect(count($shown))->toBe(10)
        ->and($component->viewData('moveCounts')[$this->from->id])->toBe(10)
        ->and(array_sum(array_map(static fn (object $move): int => $move->amountMinor, $shown)))
        ->toBe($component->viewData('rows')[$this->from->id]->netMovedMinor);

    $component->assertDontSee(Lang::get('budgets::messages.history.truncated', ['shown' => 10, 'count' => 10]));
});
