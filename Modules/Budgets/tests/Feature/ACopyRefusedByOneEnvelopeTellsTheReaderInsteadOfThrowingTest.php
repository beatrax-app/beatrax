<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// copyFromPeriod() re-validates every source row against canBudget() and throws
// the same InvalidArgumentException setAssigned() throws. The grid caught it
// there and not here, so the one refusal the writer documents arrived as an
// error page over the whole month.
//
// categories.kind is written here directly because no first-party screen
// changes it: the applier does, straight out of the op log, with nothing
// re-checking what an envelope_assignments row already keyed to that category
// now means.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'copyrefusal-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'copyrefusal-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->stale = Category::create(['user_id' => null, 'name' => 'Stale', 'slug' => 'copyrefusal-stale-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $periods = app(PeriodQuery::class);
    $this->current = $periods->current();
    $this->previous = $periods->previous($this->current);

    $writer = app(EnvelopeWriter::class);
    $writer->setAssigned($this->user, $this->groceries->id, $this->previous->start, 10000);
    $writer->setAssigned($this->user, $this->stale->id, $this->previous->start, 20000);
});

it('reports the refusal instead of throwing when one source envelope can no longer be budgeted', function (): void {
    DB::table('categories')->where('id', $this->stale->id)->update(['kind' => 'income']);

    Livewire::test(BudgetsPage::class)
        ->call('copyLastMonth')
        ->assertDispatched('toast', message: Lang::get('budgets::messages.errors.category_not_found'))
        ->assertNotDispatched('toast', message: Lang::get('budgets::messages.notices.copied_last_month'));

    // The whole month rolls back with it: copyFromPeriod() applies a month in
    // one transaction on purpose, so a copy that stopped half way cannot be
    // told from a deliberate one.
    expect(DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('period_start', $this->current->start->toDateString())
        ->count())->toBe(0);
});

it('still copies a month every envelope of which can be budgeted', function (): void {
    Livewire::test(BudgetsPage::class)
        ->call('copyLastMonth')
        ->assertDispatched('toast', message: Lang::get('budgets::messages.notices.copied_last_month'));

    expect(DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('period_start', $this->current->start->toDateString())
        ->pluck('assigned_minor')
        ->map(static fn (mixed $minor): int => (int) $minor)
        ->sort()
        ->values()
        ->all())->toBe([10000, 20000]);
});
