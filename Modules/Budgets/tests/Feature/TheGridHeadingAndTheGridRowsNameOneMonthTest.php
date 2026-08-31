<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// CarryoverQuery clamps a target outside [genesis, current+12] and folds the
// clamped month instead. The page went on rendering the month it had resolved,
// so heading, grid, moves list and copy banner could describe two months at
// once — reachable without touching a URL, by emptying the earliest month.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'one-month-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 17,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'one-month-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('shows the assignment the rendered month actually holds after genesis moves past the open month', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $previous->start, 10000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 40000);

    // With no envelope_activated_at the fold's genesis follows the earliest
    // assignment row, so zeroing the earliest month moves it forward.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $previous->start, 0);

    $page = Livewire::test(BudgetsPage::class, ['periodStartStr' => $previous->start->toDateString()]);

    $rendered = $page->viewData('period');
    $stored = (int) DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->groceries->id)
        ->where('period_start', $rendered->start->toDateString())
        ->sum('assigned_minor');

    expect($page->viewData('rows')[$this->groceries->id]->assignedMinor)->toBe($stored)
        ->and($rendered->start->toDateString())->toBe($current->start->toDateString())
        ->and($page->get('periodStartStr'))->toBe($current->start->toDateString());
});

it('leaves an in-range selection and its anchor exactly where the reader put them', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $previous->start, 10000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 40000);

    $page = Livewire::test(BudgetsPage::class, ['periodStartStr' => $previous->start->toDateString()]);

    expect($page->viewData('period')->start->toDateString())->toBe($previous->start->toDateString())
        ->and($page->viewData('rows')[$this->groceries->id]->assignedMinor)->toBe(10000)
        ->and($page->get('periodStartStr'))->toBe($previous->start->toDateString());
});
