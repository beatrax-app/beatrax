<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\ValueObjects\Money;

// `envelope_moves.amount_minor` is written negative for the `move_out` half of
// a pair and positive for the `move_in` half, and CarryoverQuery's `Moved`
// term is SUM(amount_minor) over exactly the rows this disclosure lists. The
// line took abs() of its own figure and put a '+' on the incoming side only,
// so a EUR 50.00 move out printed EUR 50.00 directly beneath a column that had
// counted it as -5000 — and the same row already printed -EUR 50.00 whenever
// its `kind` was a spelling this build had no case for, so one row rendered two
// different figures depending on which branch reached it.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'move-sign-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(3)->startOfMonth(),
    ]);

    $suffix = bin2hex(random_bytes(3));
    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'movesign-g-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'movesign-d-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();

    // No figure on the rendered grid is 50.00 or 25.00 in any other place:
    // assigned 600/100, available 525/175, to-budget -700, spent nought. A
    // signed move amount found in the HTML can only be a history line.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 60000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->dining->id, $this->period->start, 10000);

    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 5000);
    app(EnvelopeWriter::class)->move($this->user, $this->groceries->id, $this->dining->id, $this->period->start, 2500);
});

it('prints an outgoing move with the sign the Moved column summed it with', function (): void {
    $component = Livewire::test(BudgetsPage::class)->assertOk();

    $rows = $component->viewData('rows');

    // -5000 + -2500, the two rows the disclosure below this figure lists.
    expect($rows[$this->groceries->id]->netMovedMinor)->toBe(-7500);
    expect($rows[$this->dining->id]->netMovedMinor)->toBe(7500);

    $html = $component->html();

    // abs() rendered these two as EUR 50.00 and EUR 25.00, so the three lines
    // under a Moved column reading -EUR 75.00 read as two positive amounts.
    expect($html)
        ->toContain(Money::ofMinor(-5000, Currency::Eur->value)->format())
        ->toContain(Money::ofMinor(-2500, Currency::Eur->value)->format());
});

it('keeps the leading plus on the side that received the money', function (): void {
    $component = Livewire::test(BudgetsPage::class)->assertOk();
    $html = $component->html();

    // The '+' is the incoming side's marker and appears nowhere else on the
    // grid, so its two occurrences are Dining's two credit lines.
    expect($html)
        ->toContain('+'.Money::ofMinor(5000, Currency::Eur->value)->format())
        ->toContain('+'.Money::ofMinor(2500, Currency::Eur->value)->format());

    expect(substr_count($html, '+'.Money::ofMinor(5000, Currency::Eur->value)->format()))->toBe(1);
});

// The debit half is the one that changed. Its stored figure is what the column
// above it was summed from, so the two have to be the same integer.
it('renders each stored move amount exactly as the row holds it', function (): void {
    $stored = DB::table('envelope_moves')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->groceries->id)
        ->orderBy('amount_minor')
        ->pluck('amount_minor')
        ->map(static fn (mixed $minor): int => (int) $minor)
        ->all();

    expect($stored)->toBe([-5000, -2500]);

    $html = Livewire::test(BudgetsPage::class)->html();

    foreach ($stored as $minor) {
        expect($html)->toContain(Money::ofMinor($minor, Currency::Eur->value)->format());
    }
});
