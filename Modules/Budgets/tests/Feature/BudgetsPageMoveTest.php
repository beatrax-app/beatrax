<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// The Livewire wiring only; EnvelopeWriter::move()/undoMove() themselves are
// covered by EnvelopeMoveTest.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'envelopemove-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'envmove-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'envmove-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 50000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);
});

it('opens the move modal for a row and exposes the destination + recent-moves view data', function (): void {
    Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->groceries->id)
        ->assertSet('moveFromCategoryId', $this->groceries->id)
        ->assertViewHas('moveFromCategory', fn ($row) => $row !== null && $row->categoryId === $this->groceries->id)
        ->assertViewHas('moveDestinations', fn ($rows) => array_key_exists($this->dining->id, $rows) && ! array_key_exists($this->groceries->id, $rows));
});

it('moves money between two envelopes, updating both rows live with the to-budget unchanged (Req 5)', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->groceries->id)
        ->set('moveToCategoryId', (string) $this->dining->id)
        ->set('moveAmount', '20.00')
        ->call('moveMoney')
        ->assertDispatched('toast');

    $rows = $component->viewData('rows');
    expect($rows[$this->groceries->id]->availableMinor)->toBe(50000 - 2000);
    expect($rows[$this->dining->id]->availableMinor)->toBe(10000 + 2000);

    $this->assertDatabaseHas('envelope_moves', ['category_id' => $this->groceries->id, 'counterpart_category_id' => $this->dining->id, 'amount_minor' => -2000, 'kind' => 'move_out']);
});

it('shows the "choose a destination" error when no destination is selected', function (): void {
    Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->groceries->id)
        ->set('moveAmount', '20.00')
        ->call('moveMoney')
        ->assertSet('moveError', 'Choose an envelope to move money to.');

    $this->assertDatabaseMissing('envelope_moves', ['category_id' => $this->groceries->id]);
});

it('shows the "enter a valid amount" error for a zero or invalid amount', function (): void {
    Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->groceries->id)
        ->set('moveToCategoryId', (string) $this->dining->id)
        ->set('moveAmount', '0')
        ->call('moveMoney')
        ->assertSet('moveError', 'Enter an amount greater than zero.');

    $this->assertDatabaseMissing('envelope_moves', ['category_id' => $this->groceries->id]);
});

it('undoes a move via the per-envelope recent-moves list, restoring both envelopes', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->call('openMove', $this->groceries->id)
        ->set('moveToCategoryId', (string) $this->dining->id)
        ->set('moveAmount', '20.00')
        ->call('moveMoney');

    $recentMoves = $component->viewData('recentMoves');
    $moveId = $recentMoves[$this->groceries->id][0]->id;

    $component->call('undoMove', $moveId)->assertDispatched('toast');

    $rows = $component->viewData('rows');
    expect($rows[$this->groceries->id]->availableMinor)->toBe(50000);
    expect($rows[$this->dining->id]->availableMinor)->toBe(10000);
    expect(DB::table('envelope_moves')->where('category_id', $this->groceries->id)->where('counterpart_category_id', $this->dining->id)->count())->toBe(0);
});
