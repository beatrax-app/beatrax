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

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'envelopegrid-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // Genesis anchor well before "current" so the copy-last-month fixtures
    // below always have a real prior period to read from.
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'envgrid-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'envgrid-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
});

it('renders the envelope grid with the ready-to-assign header', function (): void {
    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('Budgets')
        ->assertSee('Ready to assign')
        ->assertSee('Groceries');
});

it('assigns an amount inline and live-updates that rows available and the to-budget header (Req 3)', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->set("assignedInputs.{$this->groceries->id}", '50.00')
        ->call('setAssigned', $this->groceries->id);

    $rows = $component->viewData('rows');
    expect($rows[$this->groceries->id]->assignedMinor)->toBe(5000);
    expect($rows[$this->groceries->id]->availableMinor)->toBe(5000);
    expect($component->viewData('toBudgetMinor'))->toBe(-5000);

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'assigned_minor' => 5000,
    ]);
});

it('permits over-assignment: to-budget goes negative and the write is never rejected (Req 8)', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->set("assignedInputs.{$this->groceries->id}", '900.00')
        ->call('setAssigned', $this->groceries->id);

    expect($component->viewData('toBudgetMinor'))->toBeLessThan(0);
    $this->assertDatabaseHas('envelope_assignments', [
        'category_id' => $this->groceries->id,
        'assigned_minor' => 90000,
    ]);
});

it('clears an envelopes assigned amount back to zero (tombstone, D-06)', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, app(PeriodQuery::class)->current()->start, 5000);

    Livewire::test(BudgetsPage::class)
        ->set("assignedInputs.{$this->groceries->id}", '')
        ->call('setAssigned', $this->groceries->id);

    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $this->groceries->id]);
});

it('rejects assigning a category that is not the users own or a global expense category (IDOR)', function (): void {
    $other = User::create(['username' => 'mallory-'.bin2hex(random_bytes(4)), 'password' => 'x', 'period_start_day' => 1]);
    $foreign = Category::create(['user_id' => $other->id, 'name' => 'Therapy', 'slug' => 'envgrid-therapy-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 3]);

    Livewire::test(BudgetsPage::class)
        ->set("assignedInputs.{$foreign->id}", '50.00')
        ->call('setAssigned', $foreign->id);

    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $foreign->id]);
});

it('toggles the overspend mode for an envelope', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->call('setOverspendMode', $this->groceries->id, 'carry_negative');

    $rows = $component->viewData('rows');
    expect($rows[$this->groceries->id]->overspendMode)->toBe('carry_negative');
});

it('copies last months assignments only when the selected month has none and the prior month has some (Req 6)', function (): void {
    $current = app(PeriodQuery::class)->current();
    $previous = app(PeriodQuery::class)->previous($current);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $previous->start, 12000);

    Livewire::test(BudgetsPage::class)
        ->assertViewHas('showCopyBanner', true)
        ->call('copyLastMonth');

    $this->assertDatabaseHas('envelope_assignments', [
        'category_id' => $this->groceries->id,
        'period_start' => $current->start->toDateString(),
        'assigned_minor' => 12000,
    ]);
});

it('does not offer copy-last-month once the selected month already has an assignment', function (): void {
    $current = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 5000);

    Livewire::test(BudgetsPage::class)
        ->assertViewHas('showCopyBanner', false);
});
