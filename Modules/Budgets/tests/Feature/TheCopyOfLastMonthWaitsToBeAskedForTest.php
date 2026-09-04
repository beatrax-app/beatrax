<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

// Filling a month from the one before it is a plan the reader never made, and
// the grid gives no sign afterwards which figures were theirs. Drawing the offer
// is what a render may do; writing it is what the button is for, and only the
// button's own test ever pressed it.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'copyoffer-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'copyoffer-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->current = app(PeriodQuery::class)->current();
    $this->previous = app(PeriodQuery::class)->previous($this->current);
});

function copyOfferAssignmentCount(int $userId, Period $period): int
{
    return DB::table('envelope_assignments')
        ->where('user_id', $userId)
        ->where('period_start', $period->start->toDateString())
        ->count();
}

it('draws the offer without writing a single row of the plan behind it', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->previous->start, 12000);

    Livewire::test(BudgetsPage::class)->assertViewHas('showCopyBanner', true);

    expect(copyOfferAssignmentCount($this->user->id, $this->current))->toBe(0);

    // A second render, because an auto-fill that runs once looks the same as one
    // that runs on the render after the reader has ignored the offer.
    Livewire::test(BudgetsPage::class)->assertViewHas('showCopyBanner', true);

    expect(copyOfferAssignmentCount($this->user->id, $this->current))->toBe(0);
});

it('writes the plan once the reader asks for it', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->previous->start, 12000);

    Livewire::test(BudgetsPage::class)->call('copyLastMonth');

    expect(copyOfferAssignmentCount($this->user->id, $this->current))->toBe(1);

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $this->current->start->toDateString(),
        'assigned_minor' => 12000,
    ]);
});

it('offers nothing where the month before has nothing to copy', function (): void {
    expect(copyOfferAssignmentCount($this->user->id, $this->previous))->toBe(0);

    Livewire::test(BudgetsPage::class)->assertViewHas('showCopyBanner', false);
});
